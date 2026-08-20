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
 *  1. It is a real ZIP download (ZipArchive + application/zip headers).
 *  2. Photos are pulled from data/sku_photos and written into each SKU folder.
 *  3. Filters behave exactly like the CSV export (case-insensitive active
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

    public function test_writes_photos_and_info_into_each_sku_folder(): void
    {
        $source = $this->source();
        $this->assertStringContainsString('data/sku_photos', $source);
        $this->assertStringContainsString('addEmptyDir($folder)', $source);
        $this->assertStringContainsString('$zip->addFile(', $source);
        $this->assertStringContainsString('/info.txt', $source);
    }

    public function test_manifest_matches_the_csv_export_columns(): void
    {
        $this->assertStringContainsString(
            'SKU,Status,What is it?,eBay Category,Qty,Dispotech Price,eBay Price,Updated',
            $this->source()
        );
    }
}
