<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CRUD Operations Unit Tests.
 *
 * [CRUD Operations] — insert, select, update, delete, soft-delete
 * against an in-memory SQLite sandbox.
 */
#[CoversNothing]
final class CrudOperationsTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createSandboxDatabase();
    }

    // ── Create ──────────────────────────────────────────────────────────

    public function test_insert_item_returns_positive_id(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO intake_items (sku, sku_normalized, status, what_is_it) VALUES (:sku, :sku_normalized, :status, :what_is_it)');
        $stmt->execute([
            'sku'            => 'INSERT-TEST',
            'sku_normalized' => 'INSERT-TEST',
            'status'         => 'Intake',
            'what_is_it'     => 'Test Insert Item',
        ]);
        $this->assertGreaterThan(0, (int) $this->pdo->lastInsertId());
    }

    public function test_insert_item_with_all_optional_fields(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO intake_items (sku, sku_normalized, status, what_is_it, functional, condition, is_square, ebay_price, dispotech_price, notes, reviewed) VALUES (:sku, :sku_normalized, :status, :what_is_it, :functional, :condition, :is_square, :ebay_price, :dispotech_price, :notes, :reviewed)');
        $stmt->execute([
            'sku'            => 'FULL-TEST',
            'sku_normalized' => 'FULL-TEST',
            'status'         => 'ebay review',
            'what_is_it'     => 'Full Fields Item',
            'functional'     => 'Yes',
            'condition'      => 'Excellent',
            'is_square'      => 1,
            'ebay_price'     => 499.99,
            'dispotech_price' => null,
            'notes'          => 'Test notes with special chars: <>&"\'\'',
            'reviewed'       => 1,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->assertGreaterThan(0, $id);
    }

    // ── Read ────────────────────────────────────────────────────────────

    public function test_select_item_by_id(): void
    {
        $items = InventoryFixtures::standardInventory($this->pdo);
        $first = $items[0];

        $stmt = $this->pdo->prepare('SELECT * FROM intake_items WHERE id = :id');
        $stmt->execute(['id' => $first['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertSame($first['sku'], $row['sku']);
        $this->assertSame($first['status'], $row['status']);
    }

    public function test_select_items_by_status(): void
    {
        InventoryFixtures::standardInventory($this->pdo);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM intake_items WHERE status = 'intake'");
        $stmt->execute();
        $this->assertSame(2, (int) $stmt->fetchColumn());
    }

    public function test_select_items_by_reviewed_flag(): void
    {
        InventoryFixtures::standardInventory($this->pdo);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM intake_items WHERE reviewed = 1');
        $stmt->execute();
        // Items 2,3,5,6,10,11
        $this->assertSame(6, (int) $stmt->fetchColumn());
    }

    public function test_empty_inventory_returns_zero_rows(): void
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM intake_items');
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    // ── Update ──────────────────────────────────────────────────────────

    public function test_update_item_status(): void
    {
        $items = InventoryFixtures::standardInventory($this->pdo);
        $target = $items[0];

        $stmt = $this->pdo->prepare("UPDATE intake_items SET status = 'Tested', updated_at = datetime('now') WHERE id = :id");
        $stmt->execute(['id' => $target['id']]);
        $this->assertSame(1, $stmt->rowCount());

        $check = $this->pdo->prepare('SELECT status FROM intake_items WHERE id = :id');
        $check->execute(['id' => $target['id']]);
        $this->assertSame('Tested', $check->fetchColumn());
    }

    public function test_update_item_price(): void
    {
        $items = InventoryFixtures::standardInventory($this->pdo);
        $target = $items[0];

        $stmt = $this->pdo->prepare('UPDATE intake_items SET dispotech_price = :price WHERE id = :id');
        $stmt->execute(['price' => 199.99, 'id' => $target['id']]);
        $this->assertSame(1, $stmt->rowCount());

        $check = $this->pdo->prepare('SELECT dispotech_price FROM intake_items WHERE id = :id');
        $check->execute(['id' => $target['id']]);
        $this->assertSame(199.99, (float) $check->fetchColumn());
    }

    public function test_update_nonexistent_item_affects_zero_rows(): void
    {
        $stmt = $this->pdo->prepare("UPDATE intake_items SET status = 'SOLD' WHERE id = :id");
        $stmt->execute(['id' => 99999]);
        $this->assertSame(0, $stmt->rowCount());
    }

    public function test_concurrent_update_lost_update_scenario(): void
    {
        $items = InventoryFixtures::standardInventory($this->pdo);
        $target = $items[0];
        $scenario = InventoryFixtures::concurrentUpdateScenario();

        // Update 1
        $stmt = $this->pdo->prepare("UPDATE intake_items SET status = :status, updated_at = :ts WHERE id = :id");
        $stmt->execute(['status' => $scenario['update1']['status'], 'ts' => $scenario['update1']['updated_at'], 'id' => $target['id']]);

        // Update 2 (wins)
        $stmt->execute(['status' => $scenario['update2']['status'], 'ts' => $scenario['update2']['updated_at'], 'id' => $target['id']]);

        $check = $this->pdo->prepare('SELECT status FROM intake_items WHERE id = :id');
        $check->execute(['id' => $target['id']]);
        $this->assertSame('SOLD', $check->fetchColumn());
    }

    // ── Delete ──────────────────────────────────────────────────────────

    public function test_delete_item_removes_row(): void
    {
        $items = InventoryFixtures::standardInventory($this->pdo);
        $target = $items[0];

        $stmt = $this->pdo->prepare('DELETE FROM intake_items WHERE id = :id');
        $stmt->execute(['id' => $target['id']]);
        $this->assertSame(1, $stmt->rowCount());

        $check = $this->pdo->prepare('SELECT COUNT(*) FROM intake_items WHERE id = :id');
        $check->execute(['id' => $target['id']]);
        $this->assertSame(0, (int) $check->fetchColumn());
    }

    /**
     * [Database Layer] — archive (soft-delete) via INSERT INTO intake_deleted
     * followed by DELETE from intake_items simulates the production
     * delete_item.php flow.
     */
    public function test_soft_delete_archives_to_intake_deleted(): void
    {
        $items = InventoryFixtures::standardInventory($this->pdo);
        $target = $items[0];

        // Archive
        $copy = $this->pdo->prepare('INSERT INTO intake_deleted SELECT *, datetime(\'now\') AS deleted_at FROM intake_items WHERE id = :id');
        $copy->execute(['id' => $target['id']]);

        // Delete
        $del = $this->pdo->prepare('DELETE FROM intake_items WHERE id = :id');
        $del->execute(['id' => $target['id']]);

        // Verify archived
        $archived = $this->pdo->prepare('SELECT * FROM intake_deleted WHERE id = :id');
        $archived->execute(['id' => $target['id']]);
        $row = $archived->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame($target['sku'], $row['sku']);
        $this->assertArrayHasKey('deleted_at', $row);
        $this->assertNotNull($row['deleted_at']);

        // Verify gone from main table
        $check = $this->pdo->prepare('SELECT COUNT(*) FROM intake_items WHERE id = :id');
        $check->execute(['id' => $target['id']]);
        $this->assertSame(0, (int) $check->fetchColumn());
    }

    public function test_delete_nonexistent_item_affects_zero_rows(): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM intake_items WHERE id = :id');
        $stmt->execute(['id' => 99999]);
        $this->assertSame(0, $stmt->rowCount());
    }

    // ── Search ──────────────────────────────────────────────────────────

    public function test_search_items_by_sku_partial(): void
    {
        InventoryFixtures::standardInventory($this->pdo);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM intake_items WHERE sku_normalized LIKE :pattern");
        $stmt->execute(['pattern' => 'DT-100%']);
        $this->assertSame(12, (int) $stmt->fetchColumn());
    }

    public function test_search_items_by_what_is_it(): void
    {
        InventoryFixtures::standardInventory($this->pdo);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM intake_items WHERE what_is_it LIKE :q');
        $stmt->execute(['q' => '%Dell%']);
        $this->assertSame(2, (int) $stmt->fetchColumn());
    }

    // ── Photo Association ───────────────────────────────────────────────

    public function test_insert_and_select_sku_photo(): void
    {
        $items = InventoryFixtures::standardInventory($this->pdo);
        $target = $items[0];

        $stmt = $this->pdo->prepare('INSERT INTO sku_photos (sku_normalized, original_name, stored_name, mime_type, file_size) VALUES (:sku, :orig, :stored, :mime, :size)');
        $stmt->execute([
            'sku'    => $target['sku_normalized'],
            'orig'   => 'photo1.jpg',
            'stored' => 'abc123.jpg',
            'mime'   => 'image/jpeg',
            'size'   => 102400,
        ]);
        $photoId = (int) $this->pdo->lastInsertId();
        $this->assertGreaterThan(0, $photoId);

        $fetch = $this->pdo->prepare('SELECT * FROM sku_photos WHERE id = :id');
        $fetch->execute(['id' => $photoId]);
        $row = $fetch->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($target['sku_normalized'], $row['sku_normalized']);
        $this->assertSame('photo1.jpg',     $row['original_name']);
    }
}
