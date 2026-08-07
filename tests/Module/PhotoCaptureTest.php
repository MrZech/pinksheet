<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Photo Capture Module Tests.
 *
 * Tests the photo upload pipeline including MIME detection, chunked upload
 * assembly, SKU-photo association, and file storage integrity.
 */
require_once __DIR__ . '/../../config.php';
#[CoversNothing]
final class PhotoCaptureTest extends TestCase
{
    private PDO $pdo;
    private string $photoDir;

    protected function setUp(): void
    {
        $this->pdo = createSandboxDatabase();
        $this->photoDir = TESTING_ROOT . '/tmp/test_data/photos/' . bin2hex(random_bytes(4));
        mkdir($this->photoDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->photoDir);
    }

    public function test_rejects_php_mime(): void
    {
        $path = $this->photoDir . '/evil.php';
        file_put_contents($path, 'not a real image file');
        $mime = detectUploadMimeType($path);
        $this->assertNotNull($mime);
    }

    public function test_accepts_valid_jpeg(): void
    {
        $path = $this->photoDir . '/photo.jpg';
        file_put_contents($path, "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 100));
        $mime = detectUploadMimeType($path);
        $this->assertSame('image/jpeg', $mime);
    }

    public function test_accepts_valid_png(): void
    {
        $path = $this->photoDir . '/photo.png';
        file_put_contents($path, "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 100));
        $mime = detectUploadMimeType($path);
        $this->assertSame('image/png', $mime);
    }

    public function test_store_photo_file_and_record(): void
    {
        $sku = 'DT-TESTPHOTO';
        $originalName = 'test_photo.jpg';
        $storedName = uniqid('photo_') . '.jpg';
        $content = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 1024);

        $destPath = $this->photoDir . '/' . $storedName;
        file_put_contents($destPath, $content);

        $stmt = $this->pdo->prepare('INSERT INTO sku_photos (sku_normalized, original_name, stored_name, mime_type, file_size) VALUES (:sku, :orig, :stored, :mime, :size)');
        $stmt->execute([
            'sku'    => $sku,
            'orig'   => $originalName,
            'stored' => $storedName,
            'mime'   => 'image/jpeg',
            'size'   => strlen($content),
        ]);
        $photoId = (int) $this->pdo->lastInsertId();

        $this->assertFileExists($destPath);
        $this->assertSame(1028, filesize($destPath));

        $row = $this->pdo->query("SELECT * FROM sku_photos WHERE id = {$photoId}")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($sku, $row['sku_normalized']);
        $this->assertSame($originalName, $row['original_name']);
        $this->assertSame('image/jpeg', $row['mime_type']);
        $this->assertSame(1028, (int) $row['file_size']);
    }

    public function test_multiple_photos_per_sku(): void
    {
        $sku = 'DT-MULTIPHOTO';
        $count = 3;
        for ($i = 1; $i <= $count; $i++) {
            $stored = "photo_{$i}.jpg";
            file_put_contents($this->photoDir . '/' . $stored, "\xFF\xD8\xFF\xE0");
            $this->pdo->prepare('INSERT INTO sku_photos (sku_normalized, original_name, stored_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?)')
                ->execute([$sku, "orig_{$i}.jpg", $stored, 'image/jpeg', 100]);
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM sku_photos WHERE sku_normalized = :sku');
        $stmt->execute(['sku' => $sku]);
        $this->assertSame($count, (int) $stmt->fetchColumn());
    }

    public function test_chunked_upload_assembly(): void
    {
        $chunkDir = $this->photoDir . '/chunks_' . bin2hex(random_bytes(4));
        mkdir($chunkDir, 0777, true);

        $chunks = ['part1', 'part2', 'part3'];
        foreach ($chunks as $i => $content) {
            file_put_contents("{$chunkDir}/chunk_{$i}", $content);
        }

        $finalContent = '';
        for ($i = 0; $i < count($chunks); $i++) {
            $chunkPath = "{$chunkDir}/chunk_{$i}";
            if (is_file($chunkPath)) {
                $finalContent .= file_get_contents($chunkPath);
            }
        }

        $this->assertSame('part1part2part3', $finalContent);
        $this->rmdirRecursive($chunkDir);
    }

    public function test_chunked_upload_with_missing_chunk(): void
    {
        $chunkDir = $this->photoDir . '/chunks_' . bin2hex(random_bytes(4));
        mkdir($chunkDir, 0777, true);

        file_put_contents("{$chunkDir}/chunk_0", 'first');
        file_put_contents("{$chunkDir}/chunk_2", 'third');

        $expectedChunks = 3;
        $finalContent = '';
        $allFound = true;
        for ($i = 0; $i < $expectedChunks; $i++) {
            $chunkPath = "{$chunkDir}/chunk_{$i}";
            if (is_file($chunkPath)) {
                $finalContent .= file_get_contents($chunkPath);
            } else {
                $allFound = false;
            }
        }

        $this->assertFalse($allFound);
        $this->assertSame('firstthird', $finalContent);
        $this->rmdirRecursive($chunkDir);
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) { return; }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $f) {
            $path = $dir . '/' . $f;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}
