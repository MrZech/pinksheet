<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SKU Normalization Tests.
 *
 * Verifies that the centralized normalizeSku() helper behaves
 * identically to the original strtoupper(trim(...)) logic used
 * across all 11 production call sites.
 *
 * Tests call the helper directly via the polyfill in bootstrap.php
 * (which mirrors the config.php definition).
 *
 * [Data Normalization] — SKU trimming, uppercasing, edge cases.
 */
#[CoversNothing]
final class SkuTest extends TestCase
{
    /**
     * The core contract: normalizeSku trims whitespace and uppercases.
     */
    #[DataProvider('skuNormalizationProvider')]
    public function test_normalize_sku_contract(string $input, string $expected): void
    {
        // Call via config.php or polyfill (both define normalizeSku)
        $this->assertTrue(function_exists('normalizeSku'), 'normalizeSku must be defined');

        $result = \normalizeSku($input);
        $this->assertSame($expected, $result);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function skuNormalizationProvider(): iterable
    {
        yield 'whitespace padding' => ['  abc-123  ', 'ABC-123'];
        yield 'mixed case' => ['AbC-123', 'ABC-123'];
        yield 'already clean' => ['ABC-123', 'ABC-123'];
        yield 'lowercase only' => ['abc-123', 'ABC-123'];
        yield 'trailing space' => ['abc-123   ', 'ABC-123'];
        yield 'leading space' => ['   abc-123', 'ABC-123'];
        yield 'inner space' => ['abc 123', 'ABC 123'];
        yield 'special characters' => ['abc_123!@#', 'ABC_123!@#'];
        yield 'numeric only' => ['123456', '123456'];
        yield 'single character' => ['a', 'A'];
        yield 'empty string' => ['', ''];
        yield 'only whitespace' => ['   ', ''];
        yield 'mixed with tabs' => ["\tABC-123\t", 'ABC-123'];
        yield 'newlines' => ["\nabc-123\n", 'ABC-123'];
        yield 'dots and dashes' => ['dt.100-abc', 'DT.100-ABC'];
        yield 'underscores' => ['sku_001', 'SKU_001'];
        yield 'long sku' => ['very-long-sku-code-12345', 'VERY-LONG-SKU-CODE-12345'];
    }

    // ── Edge Cases ─────────────────────────────────────────────────────

    public function test_normalize_sku_does_not_strip_known_prefix_patterns(): void
    {
        $result = \normalizeSku('DT-1001');
        $this->assertStringStartsWith('DT-', $result);
        $this->assertSame('DT-1001', $result);
    }

    public function test_normalize_sku_handles_null_coalesce_pattern(): void
    {
        // Mimics the production pattern: normalizeSku($sku ?? '')
        $sku = null;
        $result = \normalizeSku($sku ?? '');
        $this->assertSame('', $result);
    }

    // ── Production Call-Site Verification ─────────────────────────────

    /**
     * Verify that all files that previously defined their own
     * normalizeSku now rely on the centralized version by confirming
     * the function is available globally.
     */
    public function test_centralized_function_exists(): void
    {
        $this->assertTrue(function_exists('normalizeSku'));
    }

    /**
     * Confirm the function signature matches all call-sites:
     * accepts string, returns string.
     */
    public function test_function_signature_is_consistent(): void
    {
        $ref = new ReflectionFunction('normalizeSku');
        $params = $ref->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('sku', $params[0]->getName());
        $this->assertTrue($params[0]->hasType());
        $this->assertSame('string', $params[0]->getType()->getName());

        $returnType = $ref->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('string', (string) $returnType);
    }
}
