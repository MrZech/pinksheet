<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Database Constraint Regression Tests.
 *
 * Ensures database schema invariants — foreign keys, unique constraints,
 * default values, cascading behaviour — remain intact across migrations.
 *
 * [Database Layer] — constraint regression prevention.
 */
#[CoversNothing]
final class DatabaseConstraintRegressionTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createSandboxDatabase();
    }

    // ── Schema Invariants ───────────────────────────────────────────────

    public function test_intake_items_has_expected_columns(): void
    {
        $cols = $this->pdo->query('PRAGMA table_info(intake_items)')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');

        $expected = ['id', 'created_at', 'updated_at', 'sku', 'sku_normalized', 'status', 'what_is_it'];
        foreach ($expected as $col) {
            $this->assertContains($col, $names, "Missing column: {$col}");
        }
    }

    public function test_intake_items_has_auto_increment_primary_key(): void
    {
        $cols = $this->pdo->query('PRAGMA table_info(intake_items)')->fetchAll(PDO::FETCH_ASSOC);
        $pkCol = current(array_filter($cols, fn($c) => $c['pk'] == 1));
        $this->assertNotFalse($pkCol, 'intake_items must have a primary key');
        $this->assertSame('id', $pkCol['name']);
    }

    public function test_intake_items_created_at_defaults_to_now(): void
    {
        $this->pdo->exec("INSERT INTO intake_items (sku) VALUES ('DEFAULT-TEST')");
        $created = $this->pdo->query("SELECT created_at FROM intake_items WHERE sku = 'DEFAULT-TEST'")->fetchColumn();
        $this->assertNotEmpty($created);
        // Should be close to current time
        $ts = strtotime((string) $created);
        $this->assertNotFalse($ts);
        $this->assertLessThan(5, abs(time() - $ts), 'created_at should be within 5 seconds of now');
    }

    public function test_intake_deleted_columns_match_intake_items(): void
    {
        $itemsCols = $this->pdo->query('PRAGMA table_info(intake_items)')->fetchAll(PDO::FETCH_ASSOC);
        $deletedCols = $this->pdo->query('PRAGMA table_info(intake_deleted)')->fetchAll(PDO::FETCH_ASSOC);

        $itemsNames = array_column($itemsCols, 'name');
        $deletedNames = array_column($deletedCols, 'name');

        // intake_deleted should have all intake_items columns plus deleted_at
        foreach ($itemsNames as $name) {
            $this->assertContains($name, $deletedNames, "intake_deleted missing column: {$name}");
        }
        $this->assertContains('deleted_at', $deletedNames);
    }

    // ── Default Values ──────────────────────────────────────────────────

    public function test_reviewed_defaults_to_zero(): void
    {
        $this->pdo->exec("INSERT INTO intake_items (sku) VALUES ('DEFAULT-REVIEWED')");
        $reviewed = $this->pdo->query("SELECT reviewed FROM intake_items WHERE sku = 'DEFAULT-REVIEWED'")->fetchColumn();
        $this->assertSame(0, (int) $reviewed);
    }

    public function test_diagnostics_test_ran_defaults_to_null(): void
    {
        $this->pdo->exec("INSERT INTO intake_items (sku) VALUES ('DEFAULT-DIAG')");
        $diag = $this->pdo->query("SELECT diagnostics_test_ran FROM intake_items WHERE sku = 'DEFAULT-DIAG'")->fetchColumn();
        $this->assertNull($diag);
    }

    public function test_is_square_defaults_to_null(): void
    {
        $this->pdo->exec("INSERT INTO intake_items (sku) VALUES ('DEFAULT-SQUARE')");
        $square = $this->pdo->query("SELECT is_square FROM intake_items WHERE sku = 'DEFAULT-SQUARE'")->fetchColumn();
        // SQLite may return null or a falsy value depending on schema
        $this->assertNull($square);
    }

    // ── No Uniqueness on SKU (permitted duplicates) ─────────────────────

    public function test_duplicate_sku_is_permitted(): void
    {
        $this->pdo->exec("INSERT INTO intake_items (sku, sku_normalized) VALUES ('DUP', 'DUP')");
        $this->pdo->exec("INSERT INTO intake_items (sku, sku_normalized) VALUES ('DUP', 'DUP')");

        $count = $this->pdo->query("SELECT COUNT(*) FROM intake_items WHERE sku = 'DUP'")->fetchColumn();
        $this->assertSame(2, (int) $count);
    }

    // ── Photo Foreign Key (no hard FK, but logical link) ────────────────

    public function test_photo_can_exist_without_item(): void
    {
        // sku_photos has no foreign key constraint — orphaned photos are allowed
        $this->pdo->exec("INSERT INTO sku_photos (sku_normalized, original_name, stored_name, mime_type) VALUES ('ORPHAN', 'orphan.jpg', 'orphan.jpg', 'image/jpeg')");
        $count = $this->pdo->query("SELECT COUNT(*) FROM sku_photos WHERE sku_normalized = 'ORPHAN'")->fetchColumn();
        $this->assertSame(1, (int) $count);
    }

    // ── script_cache primary key ────────────────────────────────────────

    public function test_script_cache_upsert_preserves_single_row_per_sku(): void
    {
        $this->pdo->exec("INSERT OR REPLACE INTO script_cache (sku_normalized, sku_display, prompt_text) VALUES ('DT-1001', 'DT-1001', 'first')");
        $this->pdo->exec("INSERT OR REPLACE INTO script_cache (sku_normalized, sku_display, prompt_text) VALUES ('DT-1001', 'DT-1001', 'second')");

        $count = $this->pdo->query("SELECT COUNT(*) FROM script_cache WHERE sku_normalized = 'DT-1001'")->fetchColumn();
        $this->assertSame(1, (int) $count, 'script_cache should have exactly 1 row per SKU after upsert');
    }
}
