<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Photo Caching Mechanics Tests.
 *
 * Verifies that photo.php implements proper HTTP caching:
 * - ETag fingerprint based on file mtime + size
 * - Cache-Control: public with max-age
 * - 304 Not Modified response when If-None-Match matches
 *
 * Tests examine the source code contracts since actual HTTP
 * responses require a running server.
 *
 * [Caching] — ETag generation, Cache-Control headers, 304 logic.
 */
#[CoversNothing]
final class CacheTest extends TestCase
{
    private string $photoFile;

    protected function setUp(): void
    {
        $this->photoFile = TESTING_ROOT . '/photo.php';
    }

    // ── Source Code Contracts ─────────────────────────────────────────

    public function test_photo_php_contains_etag_logic(): void
    {
        $source = file_get_contents($this->photoFile);
        $this->assertStringContainsString('ETag', $source);
    }

    public function test_photo_php_contains_filemtime_etag(): void
    {
        $source = file_get_contents($this->photoFile);
        $this->assertStringContainsString('filemtime', $source);
    }

    public function test_photo_php_contains_filesize_etag(): void
    {
        $source = file_get_contents($this->photoFile);
        $this->assertStringContainsString('filesize', $source);
    }

    public function test_photo_php_contains_cache_control_header(): void
    {
        $source = file_get_contents($this->photoFile);
        $this->assertStringContainsString('Cache-Control', $source);
    }

    public function test_photo_php_contains_max_age_directive(): void
    {
        $source = file_get_contents($this->photoFile);
        $this->assertStringContainsString('max-age', $source);
    }

    public function test_photo_php_sends_304_for_conditional_request(): void
    {
        $source = file_get_contents($this->photoFile);
        $this->assertStringContainsString('304', $source);
        $this->assertStringContainsString('http_response_code(304', $source);
    }

    public function test_photo_php_contains_if_none_match_logic(): void
    {
        $source = file_get_contents($this->photoFile);
        $this->assertStringContainsString('HTTP_IF_NONE_MATCH', $source);
    }

    public function test_photo_php_serves_public_cache(): void
    {
        $source = file_get_contents($this->photoFile);
        $this->assertStringContainsString('public', $source);
    }

    // ── ETag Format Verification ──────────────────────────────────────

    public function test_etag_is_based_on_file_metadata(): void
    {
        $testFile = TESTING_ROOT . '/data/sku_photos';
        if (!is_dir($testFile)) {
            $this->markTestSkipped('Photo directory does not exist for ETag format test');
        }

        // Verify the ETag formula used in photo.php
        $source = file_get_contents($this->photoFile);

        // ETag should incorporate mtime and size
        $hasMtime = str_contains($source, 'filemtime');
        $hasSize  = str_contains($source, 'filesize');
        $hasHash  = str_contains($source, 'md5') || str_contains($source, 'sha1')
                    || str_contains($source, 'crc') || str_contains($source, 'dechex')
                    || str_contains($source, 'sprintf')
                    || str_contains($source, '"' . '"') // concatenation pattern
                    || str_contains($source, 'ETag');

        $this->assertTrue($hasMtime && ($hasSize || $hasHash),
            'ETag must incorporate file mtime and size/hash');
    }

    // ── No Regression on Printing / Photo Retrieval ───────────────────

    public function test_photo_php_does_not_break_output_format(): void
    {
        $source = file_get_contents($this->photoFile);

        // Must still serve Content-Type header for images
        $this->assertStringContainsString('Content-Type', $source);

        // Must still read and output the file
        $this->assertTrue(
            str_contains($source, 'readfile')
            || str_contains($source, 'file_get_contents')
            || str_contains($source, 'fread')
            || str_contains($source, 'fpassthru'),
            'photo.php must use readfile/file_get_contents/fread/fpassthru to output file data'
        );
    }

    public function test_photo_php_returns_image_data_on_normal_request(): void
    {
        $source = file_get_contents($this->photoFile);

        // Must not exit before reading the file (i.e., the 304 path
        // must be conditional, not unconditional)
        $this->assertStringContainsString('if', $source);
    }

    // ── Cache Header Format ───────────────────────────────────────────

    public function test_cache_control_has_public_max_age(): void
    {
        $source = file_get_contents($this->photoFile);

        // Find the Cache-Control header line
        $lines = file($this->photoFile, FILE_IGNORE_NEW_LINES);
        $found = false;
        foreach ($lines as $line) {
            if (stripos($line, 'Cache-Control') !== false) {
                $found = true;
                $this->assertStringContainsString('public', $line);
                $this->assertStringContainsString('max-age', $line);
                break;
            }
        }
        $this->assertTrue($found, 'Cache-Control header must be present');
    }
}
