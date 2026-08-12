<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Lookup price-filter regression tests.
 *
 * lookup_preview.php filters inventory by price range. The params are bound
 * with PDO execute(array), which binds every value as TEXT — and SQLite then
 * compares prices lexicographically ('123' < '50', so a 50–300 range matched
 * nothing). The endpoint wraps the bound params in CAST(:p AS REAL) to force
 * numeric comparison. These tests pin that behavior so the silent breakage
 * cannot come back.
 *
 * [Lookup] — price range filtering and ordering.
 */
#[CoversNothing]
final class LookupPriceFilterTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createSandboxDatabase();
        $this->insertItem('A-100', 123.0, 123.0, 'intake');
        $this->insertItem('B-300', 300.0, 300.0, 'ebay review');
        $this->insertItem('C-NONE', null, null, 'intake');
        $this->insertItem('D-075', 75.0, null, 'ebay draft');
    }

    private function insertItem(string $sku, ?float $dispotech, ?float $ebay, string $status): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO intake_items (sku, sku_normalized, status, dispotech_price, ebay_price, quantity)
             VALUES (:sku, :norm, :status, :dispotech, :ebay, 1)'
        );
        $stmt->execute([
            'sku' => $sku,
            'norm' => strtoupper($sku),
            'status' => $status,
            'dispotech' => $dispotech,
            'ebay' => $ebay,
        ]);
    }

    private function effectivePriceSql(): string
    {
        return 'COALESCE(dispotech_price, ebay_price, 0)';
    }

    private function fetchSkusInRange(float $min, float $max): array
    {
        $eff = $this->effectivePriceSql();
        $sql = "SELECT sku FROM intake_items
                WHERE $eff >= CAST(:min_price AS REAL) AND $eff <= CAST(:max_price AS REAL)
                ORDER BY sku";
        $stmt = $this->pdo->prepare($sql);
        // Execute with an array exactly like lookup_preview.php does — PDO
        // binds these as TEXT, so only the CAST keeps the comparison numeric.
        $stmt->execute(['min_price' => $min, 'max_price' => $max]);
        return array_map(static fn(array $r): string => (string)$r['sku'], $stmt->fetchAll());
    }

    public function test_price_range_matches_numerically_not_lexicographically(): void
    {
        // Old behaviour: bound as TEXT, SQLite compares '123' < '50' lexically,
        // so this range returned nothing even though B-300 ($300) and A-100
        // ($123) are both inside it.
        $this->assertSame(
            ['A-100', 'B-300', 'D-075'],
            $this->fetchSkusInRange(50, 300),
            'Price range 50–300 must match items priced $75, $123 and $300 numerically'
        );
    }

    public function test_min_price_only_excludes_low_and_priceless_items(): void
    {
        $eff = $this->effectivePriceSql();
        $sql = "SELECT sku FROM intake_items WHERE $eff >= CAST(:min_price AS REAL) ORDER BY sku";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['min_price' => 200]);
        $skus = array_map(static fn(array $r): string => (string)$r['sku'], $stmt->fetchAll());

        // Only B-300 is >= $200; the $123 and $75 items and the no-price item drop out.
        $this->assertSame(['B-300'], $skus);
    }

    public function test_max_price_only_includes_items_at_or_below_bound(): void
    {
        $eff = $this->effectivePriceSql();
        $sql = "SELECT sku FROM intake_items WHERE $eff <= CAST(:max_price AS REAL) ORDER BY sku";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['max_price' => 100]);
        $skus = array_map(static fn(array $r): string => (string)$r['sku'], $stmt->fetchAll());

        // COALESCE(..., 0) means a no-price item has effective price 0, so it
        // also satisfies a max-price-only filter (same as the client-side
        // filter, which parses missing prices as 0).
        $this->assertSame(['C-NONE', 'D-075'], $skus);
    }

    public function test_price_ordering_is_numeric(): void
    {
        $eff = $this->effectivePriceSql();
        $sql = "SELECT sku FROM intake_items ORDER BY $eff ASC, id DESC";
        $rows = $this->pdo->query($sql)->fetchAll();
        $skus = array_map(static fn(array $r): string => (string)$r['sku'], $rows);

        $this->assertSame(['C-NONE', 'D-075', 'A-100', 'B-300'], $skus);
    }

    public function test_lookup_preview_uses_numeric_cast_in_price_conditions(): void
    {
        $source = (string)file_get_contents(TESTING_ROOT . '/lookup_preview.php');
        $this->assertStringContainsString(
            'CAST(:min_price AS REAL)',
            $source,
            'lookup_preview.php must cast min_price to REAL so bound TEXT params compare numerically'
        );
        $this->assertStringContainsString(
            'CAST(:max_price AS REAL)',
            $source,
            'lookup_preview.php must cast max_price to REAL so bound TEXT params compare numerically'
        );
    }
}
