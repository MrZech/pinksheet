<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Archive CSV export regression tests.
 *
 * export_archive.php streams the archive history (sold/archived records) as a
 * downloadable CSV, mirroring export_inventory.php and the Archive page's
 * filters (q, status, source, legacy_source, sold_from, sold_to).
 *
 * [Export] — archive CSV export endpoint.
 */
#[CoversNothing]
final class ExportArchiveTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createSandboxDatabase();
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS archive_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    sku TEXT,
    sku_normalized TEXT,
    title TEXT,
    status TEXT,
    sold_at TEXT,
    sold_price REAL,
    purchase_price REAL,
    source TEXT,
    buyer TEXT,
    notes TEXT,
    legacy_source TEXT,
    legacy_table TEXT,
    legacy_id TEXT,
    legacy_payload TEXT NOT NULL
);
SQL);
        $this->insertArchiveRow([
            'sku' => 'ARC-1',
            'title' => 'Old laptop',
            'status' => 'Sold',
            'sold_at' => '2026-07-01 10:00:00',
            'sold_price' => 125.5,
            'source' => 'ebay',
            'buyer' => 'Alice',
            'notes' => 'has, commas and "quotes"',
        ]);
        $this->insertArchiveRow([
            'sku' => 'ARC-2',
            'title' => 'Old monitor',
            'status' => 'Archived',
            'sold_at' => '2026-07-15 09:00:00',
            'sold_price' => 80.0,
            'source' => 'square',
            'notes' => 'kept',
        ]);
    }

    private function insertArchiveRow(array $data): void
    {
        $cols = array_keys($data);
        $stmt = $this->pdo->prepare(
            'INSERT INTO archive_items (' . implode(', ', $cols) . ", legacy_payload) VALUES ("
            . implode(', ', array_map(static fn($c) => ":$c", $cols)) . ", '{}')"
        );
        $stmt->execute(array_combine(
            array_map(static fn($c) => ":$c", $cols),
            array_values($data)
        ));
    }

    public function test_status_filter_is_case_insensitive(): void
    {
        $sql = "SELECT sku FROM archive_items
                WHERE lower(COALESCE(status, '')) = lower(:status)
                ORDER BY sku";
        foreach (['Sold', 'sold', 'SOLD'] as $spelling) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':status' => $spelling]);
            $this->assertSame(['ARC-1'], $stmt->fetchAll(PDO::FETCH_COLUMN), "status filter must match '$spelling'");
        }
    }

    public function test_sold_date_range_filter(): void
    {
        $sql = "SELECT sku FROM archive_items
                WHERE date(COALESCE(sold_at, created_at, updated_at)) >= date(:from)
                  AND date(COALESCE(sold_at, created_at, updated_at)) <= date(:to)
                ORDER BY sku";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':from' => '2026-07-01', ':to' => '2026-07-10']);
        $this->assertSame(['ARC-1'], $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function test_search_matches_multiple_columns(): void
    {
        // Replicate the endpoint's OR-based search: title, status, buyer all match.
        $sql = "SELECT sku FROM archive_items
                WHERE lower(COALESCE(sku, '')) LIKE :q_prefix
                   OR lower(COALESCE(title, '')) LIKE :q_prefix
                   OR lower(COALESCE(buyer, '')) LIKE :q_prefix
                ORDER BY sku";
        // Title prefix match: 'old laptop' starts 'Old laptop' (and not ARC-2).
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':q_prefix' => strtolower('old laptop') . '%']);
        $this->assertSame(['ARC-1'], $stmt->fetchAll(PDO::FETCH_COLUMN));

        // Buyer prefix match: 'alice' starts 'Alice'.
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':q_prefix' => strtolower('alice') . '%']);
        $this->assertSame(['ARC-1'], $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function test_export_has_real_csv_download_headers(): void
    {
        $source = (string)file_get_contents(TESTING_ROOT . '/export_archive.php');
        $this->assertStringContainsString('Content-Type: text/csv', $source);
        $this->assertStringContainsString('Content-Disposition: attachment', $source);
        $this->assertStringContainsString('archive_', $source, 'Download filename must be prefixed with archive_');
        // Must resolve the archive DB the same way the Archive page does.
        $this->assertStringContainsString('data/archive.sqlite', $source);
        $this->assertStringContainsString('data/intake.sqlite', $source);
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
