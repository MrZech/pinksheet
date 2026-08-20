<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Folder + photos ZIP export regression tests.
 *
 * export_bundle.php streams the inventory as a ZIP with one folder per SKU,
 * each folder carrying that SKU's photos and an info.txt, plus a root CSV
 * manifest. These tests pin the pieces most likely to regress:
 *
 *  1. It is a real ZIP download (Content-Type + Content-Disposition headers).
 *  2. Photos are pulled from data/sku_photos and written into each SKU folder.
 *  3. The zip extension may be missing on the server, so the pure-PHP
 *     fallback writer must still produce a valid, extractable ZIP.
 *  4. Filters behave exactly like the CSV export (case-insensitive active
 *     scope, numeric price casting).
 *
 * [Export] — folder + photos ZIP export endpoint.
 */
#[CoversNothing]
final class ExportBundleTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(TESTING_ROOT . '/export_bundle.php');
    }

    public function test_streams_a_zip_download(): void
    {
        $source = $this->source();
        $this->assertStringContainsString('ZipArchive', $source);
        $this->assertStringContainsString("Content-Type: application/zip", $source);
        $this->assertStringContainsString('Content-Disposition: attachment', $source);
        $this->assertStringContainsString('Content-Length:', $source);
    }

    public function test_uses_inventory_zip_filename(): void
    {
        $this->assertStringContainsString('inventory_', $this->source());
        $this->assertStringContainsString('.zip', $this->source());
    }

    public function test_active_scope_is_case_insensitive_not_sold(): void
    {
        $this->assertStringContainsString(
            "LOWER(TRIM(status)) != 'sold'",
            $this->source()
        );
    }

    public function test_price_filters_cast_to_real(): void
    {
        $source = $this->source();
        $this->assertStringContainsString('CAST(:min_price AS REAL)', $source);
        $this->assertStringContainsString('CAST(:max_price AS REAL)', $source);
    }

    public function test_collects_photos_and_info_into_each_sku_folder(): void
    {
        $source = $this->source();
        $this->assertStringContainsString('data/sku_photos', $source);
        $this->assertStringContainsString("'is_dir' => true", $source);
        $this->assertStringContainsString("'path' => \$diskPath", $source);
        $this->assertStringContainsString('/info.txt', $source);
        $this->assertStringContainsString('lib/bundle_zip.php', $source);
    }

    public function test_falls_back_when_zip_extension_is_missing(): void
    {
        $source = $this->source();
        $this->assertStringContainsString("class_exists('ZipArchive')", $source);
        $this->assertStringContainsString('bundleWritePureZipToStream', $source);
    }

    public function test_streams_when_zip_extension_is_missing(): void
    {
        // With no zip extension the archive must stream straight to the
        // browser so a large export never sits silently building (which
        // trips proxy read timeouts and stalls single-worker servers).
        $source = $this->source();
        $this->assertStringContainsString("fopen('php://output', 'wb')", $source);
        $this->assertStringContainsString('set_time_limit(0)', $source);
        $this->assertStringContainsString('zlib.output_compression', $source);
    }

    public function test_rejects_concurrent_exports(): void
    {
        $source = $this->source();
        $this->assertStringContainsString('.bundle_export.lock', $source);
        $this->assertStringContainsString('LOCK_EX | LOCK_NB', $source);
        $this->assertStringContainsString('An export is already running', $source);
    }

    public function test_manifest_matches_the_csv_export_columns(): void
    {
        $this->assertStringContainsString(
            'SKU,Status,What is it?,eBay Category,Qty,Dispotech Price,eBay Price,Updated',
            $this->source()
        );
    }

    public function test_pure_php_writer_produces_valid_extractable_zip(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is needed to validate the fallback writer output.');
        }
        require_once TESTING_ROOT . '/lib/bundle_zip.php';

        $dir = TESTING_ROOT . '/tmp/test_data/bundle_pure_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);

        $photoPath = $dir . '/photo.bin';
        $photoBytes = random_bytes(4096) . "\x00\x01binary\xff\xfe";
        file_put_contents($photoPath, $photoBytes);

        $zipPath = $dir . '/out.zip';
        $csv = "SKU,Status,What is it?,Qty\r\nA-1,intake,Laptop,1\r\n";
        $info = "SKU: A-1\nPhotos: 1\n";

        bundleWritePureZip($zipPath, [
            ['name' => 'inventory_2026-08-20.csv', 'content' => $csv],
            ['name' => 'A-1/', 'is_dir' => true],
            ['name' => 'A-1/01_photo.bin', 'path' => $photoPath],
            ['name' => 'A-1/info.txt', 'content' => $info],
        ]);

        $this->assertFileExists($zipPath);
        $this->assertGreaterThan(0, filesize($zipPath));

        // The archive must be structurally valid and readable by ZipArchive.
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true, 'pure-PHP ZIP must be openable');
        $this->assertSame($csv, $zip->getFromName('inventory_2026-08-20.csv'));
        $this->assertSame($photoBytes, $zip->getFromName('A-1/01_photo.bin'));
        $this->assertSame($info, $zip->getFromName('A-1/info.txt'));

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->statIndex($i)['name'];
        }
        $this->assertContains('A-1/', $names, 'directory entry must exist inside the ZIP');
        $zip->close();

        // Extract to disk — libzip validates CRC-32 during extraction, so a
        // corrupt checksum or header would fail here.
        $extractDir = $dir . '/x';
        mkdir($extractDir, 0777, true);
        $zip2 = new ZipArchive();
        $zip2->open($zipPath);
        $this->assertTrue($zip2->extractTo($extractDir), 'extraction must succeed (CRC-valid)');
        $zip2->close();

        $this->assertSame($csv, (string)file_get_contents($extractDir . '/inventory_2026-08-20.csv'));
        $this->assertSame($photoBytes, (string)file_get_contents($extractDir . '/A-1/01_photo.bin'));
        $this->assertSame($info, (string)file_get_contents($extractDir . '/A-1/info.txt'));

        $this->removeDir($dir);
    }

    /**
     * Recursively remove a temporary directory tree.
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
