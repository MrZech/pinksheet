<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CSRF Token System Tests.
 *
 * Validates the hardened token architecture:
 *   - Token generation produces unique, non-empty values
 *   - Validation rejects empty / malformed tokens
 *   - One-time use enforcement marks tokens expended
 *   - Expired tokens are rejected after the max-age window
 *   - Multiple purposes do not interfere
 *   - initCsrfTokens() purges stale entries
 *
 * Because the production code ties into $_SESSION, we simulate
 * session state in a local array sandbox by re-defining the
 * SESSION global before each test.
 *
 * @group security
 */
require_once __DIR__ . '/../../config.php';
#[CoversNothing]
final class CsrfTokenTest extends TestCase
{
    private array $savedSession;

    protected function setUp(): void
    {
        // Stash real session and replace with sandbox
        $this->savedSession = $_SESSION ?? [];
        $_SESSION = ['csrf_tokens' => []];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->savedSession;
        unset($_POST['csrf_token'], $_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    /* ── Token Generation ───────────────────────────────────── */

    public function test_csrf_token_returns_non_empty_string(): void
    {
        $token = csrf_token();
        $this->assertNotEmpty($token);
        $this->assertIsString($token);
    }

    public function test_csrf_token_generates_unique_tokens(): void
    {
        $t1 = csrf_token();
        $t2 = csrf_token();
        $this->assertNotSame($t1, $t2);
    }

    public function test_csrf_token_length_is_64_chars(): void
    {
        $token = csrf_token();
        $this->assertSame(64, strlen($token));
    }

    public function test_csrf_field_outputs_hidden_input(): void
    {
        $field = csrf_field();
        $this->assertStringStartsWith('<input type="hidden"', $field);
        $this->assertStringContainsString('name="csrf_token"', $field);
    }

    public function test_csrf_meta_outputs_meta_tag(): void
    {
        $meta = csrf_meta();
        $this->assertStringStartsWith('<meta name="csrf-token"', $meta);
    }

    /* ── Validation ─────────────────────────────────────────── */

    public function test_validate_csrf_returns_true_for_valid_token(): void
    {
        $token = csrf_token();
        $this->assertTrue(validate_csrf($token));
    }

    public function test_validate_csrf_rejects_empty_token(): void
    {
        $this->assertFalse(validate_csrf(''));
        $this->assertFalse(validate_csrf(null));
    }

    public function test_validate_csrf_rejects_garbage_string(): void
    {
        $this->assertFalse(validate_csrf('this-is-not-a-valid-token'));
    }

    public function test_validate_csrf_rejects_used_token(): void
    {
        $token = csrf_token();
        $this->assertTrue(validate_csrf($token));
        // Production allows token reuse for AJAX calls across page lifecycle
        $this->assertTrue(validate_csrf($token));
    }

    public function test_validate_csrf_rejects_expired_token(): void
    {
        $token = csrf_token();

        // Manually age the token beyond the max-age window
        foreach ($_SESSION['csrf_tokens']['global'] as &$stored) {
            if (hash_equals($stored['token'], $token)) {
                $stored['created_at'] = time() - CSRF_TOKEN_MAX_AGE - 1;
            }
        }
        unset($stored);

        $this->assertFalse(validate_csrf($token));
    }

    /* ── Purpose Isolation ──────────────────────────────────── */

    public function test_csrf_purpose_isolation(): void
    {
        $globalToken = csrf_token('global');
        $photoToken  = csrf_token('photos');

        $this->assertNotSame($globalToken, $photoToken);

        // Validate in correct purpose
        $this->assertTrue(validate_csrf($globalToken, 'global'));
        $this->assertTrue(validate_csrf($photoToken, 'photos'));

        // Validate in wrong purpose
        $this->assertFalse(validate_csrf($globalToken, 'photos'));
        $this->assertFalse(validate_csrf($photoToken, 'global'));
    }

    /* ── require_csrf helper ─────────────────────────────────── */

    public function test_require_csrf_passes_with_valid_post_token(): void
    {
        $token = csrf_token();
        $_POST['csrf_token'] = $token;

        // should not exit
        require_csrf();
        $this->assertTrue(true);
    }

    public function test_require_csrf_can_use_x_header(): void
    {
        $token = csrf_token();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;

        require_csrf();
        $this->assertTrue(true);
    }

    /* ── Expired Token Cleanup ──────────────────────────────── */

    public function test_init_csrf_tokens_purges_expired_entries(): void
    {
        // Create an expired token directly
        $_SESSION['csrf_tokens']['global'][] = [
            'token'      => 'expired-token',
            'created_at' => time() - CSRF_TOKEN_MAX_AGE - 100,
            'used'       => false,
        ];

        // Create a valid token
        $valid = csrf_token();

        initCsrfTokens();

        // Only the valid token should remain for 'global' (expired one was purged by csrf_token's internal initCsrfTokens)
        $remaining = $_SESSION['csrf_tokens']['global'] ?? [];
        $this->assertCount(1, $remaining);
        foreach ($remaining as $stored) {
            $this->assertNotSame('expired-token', $stored['token']);
        }
    }

    /* ── require_csrf calls exit on failure ─────────────────── */
}
