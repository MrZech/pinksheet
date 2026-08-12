<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Inventory CSV export regression tests.
 *
 * export_inventory.php streams the full inventory (or just active items) as a
 * downloadable CSV. These tests pin the two things that commonly break:
 *
 *  1. The price filters must compare numerically (CAST(:p AS REAL)) — the same
 *     silent lexicographic bug that hit lookup_preview.php.
 *  2. scope=active must mean "not sold" and must behave case-insensitively
 *     (the app stores both 'sold' and legacy 'SOLD' statuses).
 *
 * [Export] — inventory CSV export endpoint.
 */
#[CoversNothing]
final class ExportInventoryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createSandboxDatabase();
        $this->insertItem('A-100', 123.0, 'intake');
        $this->insertItem('B-300', 300.0, 'ebay review');
        $this->insertItem('C-SOLD', 99.0, 'sold');
        $this->insertItem('D-SOLDLEGACY', 50.0, 'SOLD');
        $this->insertItem('E-NOSTATUS', null, null);
    }

    private function insertItem(string $sku, ?float $price, ?string $status): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO intake_items (sku, sku_normalized, status, dispotech_price, ebay_price, quantity)
             VALUES (:sku, :norm, :status, :dispotech, :ebay, 1)'
        );
        $stmt->execute([
            'sku' => $sku,
            'norm' => strtoupper($sku),
            'status' => $status,
            'dispotech' => $price,
            'ebay' => $price,
        ]);
    }

    public function test_active_scope_excludes_sold_case_insensitively(): void
    {
        $sql = "SELECT sku FROM intake_items
                WHERE (status IS NULL OR LOWER(TRIM(status)) != 'sold')
                ORDER BY sku";
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);

        // 'sold' and legacy 'SOLD' both excluded; NULL status still counts as active.
        $this->assertSame(['A-100', 'B-300', 'E-NOSTATUS'], $rows);
    }

    public function test_active_scope_source_uses_case_insensitive_not_sold(): void
    {
        $source = (string)file_get_contents(TESTING_ROOT . '/export_inventory.php');
        $this->assertStringContainsString(
            "LOWER(TRIM(status)) != 'sold'",
            $source,
            'export_inventory.php scope=active must exclude sold items case-insensitively'
        );
    }

    public function test_export_uses_numeric_cast_for_price_filters(): void
    {
        $source = (string)file_get_contents(TESTING_ROOT . '/export_inventory.php');
        $this->assertStringContainsString(
            'CAST(:min_price AS REAL)',
            $source,
            'export_inventory.php must cast min_price to REAL so bound TEXT params compare numerically'
        );
        $this->assertStringContainsString(
            'CAST(:max_price AS REAL)',
            $source,
            'export_inventory.php must cast max_price to REAL so bound TEXT params compare numerically'
        );
    }

    public function test_export_has_real_csv_download_headers(): void
    {
        $source = (string)file_get_contents(TESTING_ROOT . '/export_inventory.php');
        $this->assertStringContainsString(
            "Content-Type: text/csv",
            $source,
            'Export must stream as text/csv'
        );
        $this->assertStringContainsString(
            'Content-Disposition: attachment',
            $source,
            'Export must trigger a browser download'
        );
        $this->assertStringContainsString(
            'inventory_',
            $source,
            'Download filename must be prefixed with inventory_'
        );
    }

    public function test_status_filter_is_case_insensitive(): void
    {
        // The app stores both 'sold' and legacy 'SOLD', so the status filter
        // must match either spelling.
        $sql = "SELECT sku FROM intake_items
                WHERE LOWER(TRIM(status)) = LOWER(TRIM(:status))
                ORDER BY sku";
        foreach (['sold', 'SOLD', 'Sold'] as $spelling) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['status' => $spelling]);
            $skus = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $this->assertSame(['C-SOLD', 'D-SOLDLEGACY'], $skus, "status filter must match '$spelling'");
        }
    }

    public function test_status_filter_source_is_case_insensitive(): void
    {
        $source = (string)file_get_contents(TESTING_ROOT . '/export_inventory.php');
        $this->assertStringContainsString(
            "LOWER(TRIM(status)) = LOWER(TRIM(:status))",
            $source,
            'export_inventory.php status filter must compare case-insensitively'
        );
    }

    public function test_csv_escaping_quotes_fields_with_commas_and_quotes(): void
    {
        // Replicate the endpoint's RFC 4180 escaping inline (the endpoint
        // defines it locally, so pin the behaviour here).
        $escape = static function (string $cell): string {
            if (strpbrk($cell, ",\"\r\n") !== false) {
                return '"' . str_replace('"', '""', $cell) . '"';
            }
            return $cell;
        };

        $this->assertSame('plain', $escape('plain'));
        $this->assertSame('"a,b"', $escape('a,b'));
        $this->assertSame('"say ""hi"""', $escape('say "hi"'));
    }
}
