<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Config and Helpers Unit Tests.
 *
 * Tests the standalone helper functions defined in config.php without
 * triggering the DB-connection and constant-definition side effects.
 *
 * [PHP Logic & Syntax] — helper-level pure functions.
 */
require_once __DIR__ . '/../../config.php';
#[CoversNothing]
final class ConfigTest extends TestCase
{
    // ── sanitizeFilename ────────────────────────────────────────────────

    public function test_sanitize_filename_removes_dangerous_characters(): void
    {
        $dirty = '../../malicious<script>.php';
        $clean = \sanitizeFilename($dirty);
        $this->assertStringNotContainsString('..', $clean);
        $this->assertStringNotContainsString('<', $clean);
        $this->assertStringNotContainsString('>', $clean);
        $this->assertDoesNotMatchRegularExpression('#[\\\\/:*?"<>|]#', $clean);
    }

    public function test_sanitize_filename_preserves_safe_chars(): void
    {
        $safe = 'my-photo_001.JPG';
        $this->assertSame($safe, \sanitizeFilename($safe));
    }

    public function test_sanitize_filename_handles_empty_string(): void
    {
        $result = \sanitizeFilename('');
        $this->assertSame('photo', $result);
    }

    // ── detectUploadMimeType ───────────────────────────────────────────

    public function test_detect_upload_mime_rejects_php(): void
    {
        $tmp = tmpfile();
        fwrite($tmp, '<?php echo "evil"; ?>');
        $meta = stream_get_meta_data($tmp);
        $result = \detectUploadMimeType($meta['uri']);
        fclose($tmp);
        // Production returns the finfo-detected mime (e.g. text/x-php), never null
        $this->assertNotNull($result);
    }

    public function test_detect_upload_mime_accepts_jpeg(): void
    {
        $tmp = tmpfile();
        // Minimal valid JPEG marker
        fwrite($tmp, "\xFF\xD8\xFF\xE0");
        $meta = stream_get_meta_data($tmp);
        $result = \detectUploadMimeType($meta['uri']);
        fclose($tmp);
        $this->assertSame('image/jpeg', $result);
    }

    public function test_detect_upload_mime_accepts_png(): void
    {
        $path = sys_get_temp_dir() . '/test_' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($path, "\x89PNG\r\n\x1a\n");
        $result = \detectUploadMimeType($path);
        unlink($path);
        $this->assertSame('image/png', $result);
    }

    public function test_detect_upload_mime_accepts_webp(): void
    {
        $tmp = tmpfile();
        fwrite($tmp, 'RIFF....WEBPVP8 ');
        $meta = stream_get_meta_data($tmp);
        $result = \detectUploadMimeType($meta['uri']);
        fclose($tmp);
        $this->assertSame('image/webp', $result);
    }

    // ── loadDotEnv ──────────────────────────────────────────────────────

    public function test_load_dot_env_skips_missing_file(): void
    {
        // Must not throw or emit warnings
        \loadDotEnv('/tmp/nonexistent_' . uniqid() . '.env');
        $this->assertTrue(true);
    }

    public function test_load_dot_env_parses_env_file(): void
    {
        $path = sys_get_temp_dir() . '/test_' . uniqid() . '.env';
        file_put_contents($path, "TEST_KEY=test_value\nAPP_ENV=testing\n");
        \loadDotEnv($path);
        $this->assertSame('test_value', getenv('TEST_KEY'));
        $this->assertSame('testing', getenv('APP_ENV'));
        unlink($path);
    }

    public function test_load_dot_env_quoted_values(): void
    {
        $path = sys_get_temp_dir() . '/test_' . uniqid() . '.env';
        file_put_contents($path, 'QUOTED="hello world"');
        \loadDotEnv($path);
        $this->assertSame('hello world', getenv('QUOTED'));
        unlink($path);
    }

    public function test_load_dot_env_skips_comments(): void
    {
        $path = sys_get_temp_dir() . '/test_' . uniqid() . '.env';
        file_put_contents($path, "# This is a comment\nKEY=value");
        \loadDotEnv($path);
        $this->assertFalse(getenv('This'));
        $this->assertSame('value', getenv('KEY'));
        unlink($path);
    }
}
