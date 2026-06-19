<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Image Pipeline Tests.
 *
 * Validates the centralized image upload pipeline:
 *   - detectUploadMimeType() behaves correctly for each format
 *   - imageConvertToPng() converts JPG/WebP/GIF → PNG with alpha
 *   - processUploadedPhoto() runs full MIME + GD polyglot + store
 *   - Memory-limit / dimension-probe / downscale safety checks
 *
 * @group image-pipeline
 */
#[CoversNothing]
final class ImagePipelineTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pinksheet_test_' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0777, true);

        // Ensure constants are defined for the pipeline
        if (!defined('PNG_ONLY_MODE')) {
            define('PNG_ONLY_MODE', true);
        }
        if (!defined('PNG_REJECT_NON_PNG')) {
            define('PNG_REJECT_NON_PNG', false);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmpDir);
        }
    }

    /* ── detectUploadMimeType ───────────────────────────────── */

    /**
     * Create a minimal valid image resource and export to a temp file.
     */
    private function createTestImage(string $format, int $w = 4, int $h = 4): string
    {
        $path = $this->tmpDir . '/test.' . $format;
        $img  = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 0, 0));

        switch ($format) {
            case 'jpg':
            case 'jpeg':
                imagejpeg($img, $path);
                break;
            case 'png':
                imagepng($img, $path);
                break;
            case 'webp':
                imagewebp($img, $path);
                break;
            case 'gif':
                imagegif($img, $path);
                break;
        }
        imagedestroy($img);
        return $path;
    }

    #[DataProvider('mimeTypeProvider')]
    public function test_detect_upload_mime_type(string $format, string $expectedMime): void
    {
        $path = $this->createTestImage($format);
        $this->assertFileExists($path);

        $mime = detectUploadMimeType($path, 'test.' . $format);
        $this->assertSame($expectedMime, $mime);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function mimeTypeProvider(): iterable
    {
        yield 'JPEG' => ['jpg', 'image/jpeg'];
        yield 'PNG'  => ['png', 'image/png'];
        yield 'WebP' => ['webp', 'image/webp'];
        yield 'GIF'  => ['gif', 'image/gif'];
    }

    public function test_detect_upload_mime_type_unknown_extension(): void
    {
        $path = $this->tmpDir . '/test.bin';
        file_put_contents($path, "\x00\x01\x02\x03");
        $mime = detectUploadMimeType($path, 'test.bin');
        $this->assertSame('application/octet-stream', $mime);
    }

    public function test_detect_upload_mime_type_empty_path(): void
    {
        $mime = detectUploadMimeType('', 'test.jpg');
        $this->assertSame('application/octet-stream', $mime);
    }

    /* ── imageConvertToPng ──────────────────────────────────── */

    public function test_image_convert_to_png_from_jpeg(): void
    {
        $src     = $this->createTestImage('jpg');
        $result  = imageConvertToPng($src, $this->tmpDir);
        $this->assertNotNull($result);
        $this->assertStringEndsWith('.png', $result);

        $dstPath = $this->tmpDir . '/' . $result;
        $this->assertFileExists($dstPath);

        $info = getimagesize($dstPath);
        $this->assertNotFalse($info);
        $this->assertSame('image/png', $info['mime']);
    }

    public function test_image_convert_to_png_from_gif(): void
    {
        $src    = $this->createTestImage('gif');
        $result = imageConvertToPng($src, $this->tmpDir);
        $this->assertNotNull($result);
        $this->assertStringEndsWith('.png', $result);
    }

    public function test_image_convert_to_png_passthrough(): void
    {
        $src    = $this->createTestImage('png');
        $result = imageConvertToPng($src, $this->tmpDir);
        $this->assertNotNull($result);
        $this->assertStringEndsWith('.png', $result);
    }

    public function test_image_convert_to_png_invalid_src(): void
    {
        $result = imageConvertToPng('/nonexistent/file.jpg', $this->tmpDir);
        $this->assertNull($result);
    }

    /* ── Downscale protection ───────────────────────────────── */

    public function test_image_downscale_on_oversized_dimensions(): void
    {
        // Create a 5000×10 image which exceeds the 4096 cap
        $width  = 5000;
        $height = 10;
        $path   = $this->tmpDir . '/oversized.jpg';
        $img    = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, imagecolorallocate($img, 0, 128, 0));
        imagejpeg($img, $path);
        imagedestroy($img);

        $result = imageConvertToPng($path, $this->tmpDir);
        $this->assertNotNull($result);

        $dstPath = $this->tmpDir . '/' . $result;
        $info    = getimagesize($dstPath);
        $this->assertNotFalse($info);
        // Longest edge must be ≤ 4096
        $this->assertLessThanOrEqual(4096, max($info[0], $info[1]));
    }

    /* ── Polyglot shield ────────────────────────────────────── */

    public function test_polyglot_rejects_invalid_image(): void
    {
        $tmpFile = $this->tmpDir . '/fake.png';
        file_put_contents($tmpFile, "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 100));

        $result = processUploadedPhoto([
            'name'     => 'fake.png',
            'tmp_name' => $tmpFile,
            'size'     => filesize($tmpFile),
            'error'    => UPLOAD_ERR_OK,
        ], 'ZZ-TESTPOLY');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsStringIgnoringCase('not a valid image', $result['message'] ?? '');
    }

    public function test_polyglot_rejects_text_file(): void
    {
        $tmpFile = $this->tmpDir . '/notanimage.png';
        file_put_contents($tmpFile, '<?php echo "not an image"; ?>');

        $result = processUploadedPhoto([
            'name'     => 'notanimage.png',
            'tmp_name' => $tmpFile,
            'size'     => filesize($tmpFile),
            'error'    => UPLOAD_ERR_OK,
        ], 'ZZ-TESTPOLY');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsStringIgnoringCase('not a valid image', $result['message'] ?? '');
    }

    /* ── processUploadedPhoto full pipeline ─────────────────── */

    public function test_process_uploaded_photo_valid_png(): void
    {
        $src     = $this->createTestImage('png');
        $result  = processUploadedPhoto([
            'name'     => 'test.png',
            'tmp_name' => $src,
            'size'     => filesize($src),
            'error'    => UPLOAD_ERR_OK,
        ], 'ZZ-TESTPIPE');
        $this->assertTrue($result['ok']);
        $this->assertNotEmpty($result['stored_name']);
        $this->assertSame('image/png', $result['mime_type']);
        $this->assertGreaterThan(0, $result['file_size']);

        // Verify the stored file exists and is a valid PNG
        $photoDir = defined('PHOTO_UPLOAD_DIR') ? PHOTO_UPLOAD_DIR : (__DIR__ . '/../../data/sku_photos');
        $stored   = $photoDir . '/ZZ-TESTPIPE/' . $result['stored_name'];
        if (is_file($stored)) {
            $info = getimagesize($stored);
            $this->assertNotFalse($info);
            $this->assertSame('image/png', $info['mime']);
            @unlink($stored);
            @rmdir($photoDir . '/ZZ-TESTPIPE');
        }
    }

    public function test_process_uploaded_photo_valid_jpeg_converts_to_png(): void
    {
        $src    = $this->createTestImage('jpg');
        $result = processUploadedPhoto([
            'name'     => 'test.jpg',
            'tmp_name' => $src,
            'size'     => filesize($src),
            'error'    => UPLOAD_ERR_OK,
        ], 'ZZ-TESTJPG');
        $this->assertTrue($result['ok']);
        $this->assertSame('image/png', $result['mime_type']);

        // Cleanup
        $photoDir = defined('PHOTO_UPLOAD_DIR') ? PHOTO_UPLOAD_DIR : (__DIR__ . '/../../data/sku_photos');
        $stored   = $photoDir . '/ZZ-TESTJPG/' . $result['stored_name'];
        if (is_file($stored)) {
            @unlink($stored);
            @rmdir($photoDir . '/ZZ-TESTJPG');
        }
    }

    public function test_process_uploaded_photo_missing_file(): void
    {
        $result = processUploadedPhoto([
            'name'     => 'missing.png',
            'tmp_name' => '/nonexistent/path',
            'size'     => 0,
            'error'    => UPLOAD_ERR_OK,
        ], 'ZZ-TESTMISS');
        $this->assertFalse($result['ok']);
    }

    /* ── normalizedSkuDirectory ─────────────────────────────── */

    public function test_normalized_sku_directory_uppercases(): void
    {
        $this->assertSame('ABC-123', normalizedSkuDirectory('abc-123'));
        $this->assertSame('ABC-123', normalizedSkuDirectory('ABC-123'));
        $this->assertSame('UNASSIGNED', normalizedSkuDirectory(''));
        $this->assertSame('UNASSIGNED', normalizedSkuDirectory('___'));
        $this->assertSame('HELLO_WORLD', normalizedSkuDirectory('hello world'));
    }

    /* ── sanitizeFilename ───────────────────────────────────── */

    public function test_sanitize_filename(): void
    {
        $this->assertSame('photo', sanitizeFilename(''));
        $this->assertSame('hello.jpg', sanitizeFilename('hello.jpg'));
        $this->assertSame('a_b', sanitizeFilename('a/b'));
        $this->assertSame('a_b', sanitizeFilename('a\\b'));
        $this->assertSame('a_b', sanitizeFilename('a:b'));
    }
}
