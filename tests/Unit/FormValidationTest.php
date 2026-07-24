<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Form Validation Unit Tests.
 *
 * Tests the validation rules used by index.php and update_item.php
 * in isolation. Because validation is currently inline in production
 * code, this test reimplements each rule as a pure function to pin
 * down the expected behaviour.
 *
 * [Form Handling & Validation] — rules, edge cases, rejection paths.
 */
#[CoversNothing]
final class FormValidationTest extends TestCase
{
    // ── Helper: replicate the production validation subsets ─────────────

    private static function validateSku(string $sku): ?string
    {
        $trimmed = trim($sku);
        if ($trimmed === '') {
            return 'SKU is required.';
        }
        if (strlen($trimmed) > 50) {
            return 'SKU must be 50 characters or fewer.';
        }
        return null;
    }

    private static function validatePrice(mixed $price): ?string
    {
        if ($price === null || $price === '') {
            return null; // optional
        }
        if (!is_numeric($price)) {
            return 'Price must be a number.';
        }
        $val = (float) $price;
        if ($val < 0) {
            return 'Price cannot be negative.';
        }
        if ($val > 999999.99) {
            return 'Price exceeds maximum allowed value.';
        }
        return null;
    }

    private static function validateStatus(string $status): ?string
    {
        $allowed = [
            'intake', 'ebay draft', 'ebay review',
            'ebay listed', 'dispo tech store', 'sold',
        ];
        if (!in_array($status, $allowed, true)) {
            return 'Invalid status selected.';
        }
        return null;
    }

    private static function validateBooleanish(mixed $val, string $label): ?string
    {
        if ($val === null || $val === '') {
            return null;
        }
        if (!in_array((string) $val, ['0', '1'], true)) {
            return "{$label} must be 0 or 1.";
        }
        return null;
    }

    // ── SKU Validation ──────────────────────────────────────────────────

    /**
     * @return list<array{string, string|null}>
     */
    public static function skuProvider(): array
    {
        return [
            ['DT-1001',      null],
            ['',             'SKU is required.'],
            ['   ',          'SKU is required.'],
            [str_repeat('A', 51), 'SKU must be 50 characters or fewer.'],
            [str_repeat('A', 50), null],
        ];
    }

    #[DataProvider('skuProvider')]
    public function test_sku_validation(string $sku, ?string $expectedError): void
    {
        $this->assertSame($expectedError, self::validateSku($sku));
    }

    // ── Price Validation ────────────────────────────────────────────────

    /**
     * @return list<array{mixed, string|null}>
     */
    public static function priceProvider(): array
    {
        return [
            ['',         null],
            [null,       null],
            [0,          null],
            [99.99,      null],
            ['abc',      'Price must be a number.'],
            [-5,         'Price cannot be negative.'],
            [1_000_000,  'Price exceeds maximum allowed value.'],
            [999999.99,  null],
        ];
    }

    #[DataProvider('priceProvider')]
    public function test_price_validation(mixed $price, ?string $expectedError): void
    {
        $this->assertSame($expectedError, self::validatePrice($price));
    }

    // ── Status Validation ───────────────────────────────────────────────

    /**
     * @return list<array{string, string|null}>
     */
    public static function statusProvider(): array
    {
        return [
            ['intake',             null],
            ['ebay draft',         null],
            ['ebay review',        null],
            ['ebay listed',        null],
            ['dispo tech store',   null],
            ['sold',               null],
            ['Invalid',                 'Invalid status selected.'],
            ['',                        'Invalid status selected.'],
        ];
    }

    #[DataProvider('statusProvider')]
    public function test_status_validation(string $status, ?string $expectedError): void
    {
        $this->assertSame($expectedError, self::validateStatus($status));
    }

    // ── Boolean-ish Validation ──────────────────────────────────────────

    /**
     * @return list<array{mixed, string|null}>
     */
    public static function booleanishProvider(): array
    {
        return [
            ['',     null],
            [null,   null],
            ['0',    null],
            ['1',    null],
            [0,      null],
            [1,      null],
            ['yes',  'diagnostics must be 0 or 1.'],
            [true,   'diagnostics must be 0 or 1.'],
        ];
    }

    #[DataProvider('booleanishProvider')]
    public function test_booleanish_validation(mixed $val): void
    {
        $result = self::validateBooleanish($val, 'diagnostics');
        if ($val === '' || $val === null || $val === '0' || $val === '1' || $val === 0 || $val === 1) {
            $this->assertNull($result);
        } else {
            $this->assertNotNull($result);
        }
    }

    // ── Composite: full item validation ─────────────────────────────────

    public function test_valid_item_passes_all_rules(): void
    {
        $this->assertNull(self::validateSku('DT-1001'));
        $this->assertNull(self::validateStatus('intake'));
        $this->assertNull(self::validatePrice(299.99));
    }

    public function test_invalid_item_fails_composite(): void
    {
        $this->assertNotNull(self::validateSku(''));
        $this->assertNotNull(self::validateStatus('Bogus'));
        $this->assertNotNull(self::validatePrice(-1));
    }

    public function test_sku_injection_attempts(): void
    {
        $attempts = [
            "DT-1001; DROP TABLE intake_items;",
            "' OR '1'='1",
            "<script>alert('xss')</script>",
        ];
        foreach ($attempts as $sku) {
            $error = self::validateSku($sku);
            // All pass length check; we just verify no crash
            $this->assertNull($error);
        }
    }
}
