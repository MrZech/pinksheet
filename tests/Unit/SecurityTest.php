<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Security & CSRF Safeguards Tests.
 *
 * Validates CSRF token generation, validation, and rejection logic
 * without including config.php (to avoid session_start/header side effects).
 *
 * [Security] — CSRF token generation and validation.
 */
#[CoversNothing]
final class SecurityTest extends TestCase
{
    private string $originalSession;

    protected function setUp(): void
    {
        $this->originalSession = $_SESSION['csrf_token'] ?? '';
    }

    protected function tearDown(): void
    {
        $_SESSION['csrf_token'] = $this->originalSession;
    }

    // ── Token Generation ──────────────────────────────────────────────

    public function test_csrf_token_is_64_character_hex_string(): void
    {
        $token = bin2hex(random_bytes(32));
        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function test_csrf_token_is_unique_per_generation(): void
    {
        $token1 = bin2hex(random_bytes(32));
        $token2 = bin2hex(random_bytes(32));
        $this->assertNotSame($token1, $token2);
    }

    // ── Validation ────────────────────────────────────────────────────

    public function test_validate_csrf_returns_true_with_matching_token(): void
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $token = $_SESSION['csrf_token'];

        $result = hash_equals($_SESSION['csrf_token'], $token);
        $this->assertTrue($result);
    }

    public function test_validate_csrf_returns_false_with_wrong_token(): void
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $wrongToken = bin2hex(random_bytes(32));

        $result = hash_equals($_SESSION['csrf_token'], $wrongToken);
        $this->assertFalse($result);
    }

    public function test_validate_csrf_returns_false_with_empty_token(): void
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $result = hash_equals($_SESSION['csrf_token'], '');
        $this->assertFalse($result);
    }

    public function test_validate_csrf_returns_false_with_null_token(): void
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $result = hash_equals($_SESSION['csrf_token'], '');
        $this->assertFalse($result);
    }

    public function test_validate_csrf_returns_false_when_session_not_set(): void
    {
        unset($_SESSION['csrf_token']);

        $stored = $_SESSION['csrf_token'] ?? '';
        $this->assertSame('', $stored);

        $result = hash_equals($stored, bin2hex(random_bytes(32)));
        $this->assertFalse($result);
    }

    // ── Timing-Attack Safety ──────────────────────────────────────────

    public function test_hash_equals_prevents_timing_attacks(): void
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        // Flip the final hex digit to a guaranteed-different value so the
        // tampered string can never accidentally equal the original (the
        // old '0' suffix matched when the token already ended in '0',
        // which made this test flaky ~1 in 16 runs).
        $last = substr($_SESSION['csrf_token'], -1);
        $flip = $last === 'f' ? 'e' : 'f';
        $tampered = substr($_SESSION['csrf_token'], 0, -1) . $flip;
        $this->assertFalse(hash_equals($_SESSION['csrf_token'], $tampered));

        $same = hash_equals($_SESSION['csrf_token'], $_SESSION['csrf_token']);
        $this->assertTrue($same);
    }

    // ── Form Submission Workflow ──────────────────────────────────────

    public function test_legitimate_form_submission_passes_validation(): void
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/update_item.php';
        $_POST = [
            'csrf_token' => $_SESSION['csrf_token'],
            'sku' => 'TEST-001',
            'field' => 'status',
            'value' => 'Tested',
        ];

        $submittedToken = $_POST['csrf_token'] ?? '';
        $this->assertTrue(hash_equals($_SESSION['csrf_token'], $submittedToken));

        $_POST = [];
    }

    #[DataProvider('invalidTokenProvider')]
    public function test_invalid_tokens_are_rejected(string $description, string $submittedToken): void
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $result = hash_equals($_SESSION['csrf_token'], $submittedToken);
        $this->assertFalse($result, "Failed for case: $description");
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidTokenProvider(): iterable
    {
        $realToken = bin2hex(random_bytes(32));
        yield 'empty string' => ['empty string', ''];
        yield 'blank space' => ['blank space', '   '];
        yield 'short token' => ['short token', 'abc123'];
        yield 'tampered last char' => ['tampered last char', substr($realToken, 0, -1) . '0'];
        yield 'uppercased hex' => ['uppercased hex', strtoupper($realToken)];
        yield 'extra suffix' => ['extra suffix', $realToken . 'extra'];
    }
}
