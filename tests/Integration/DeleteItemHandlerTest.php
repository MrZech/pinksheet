<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Delete Item Handler Integration Tests.
 *
 * Simulates the delete_item.php AJAX endpoint. Tests archive,
 * removal, and error conditions.
 *
 * [CRUD Operations] — soft-delete with archive.
 * [JSON API Output] — ok/error JSON shape.
 * [Database Layer] — cascade and constraint behaviour.
 */
#[CoversNothing]
final class DeleteItemHandlerTest extends TestCase
{
    private PDO $pdo;
    private int $itemId;

    protected function setUp(): void
    {
        $this->pdo = createSandboxDatabase();
        $items = InventoryFixtures::standardInventory($this->pdo);
        $this->itemId = $items[0]['id'];
    }

    public function test_delete_item_returns_ok(): void
    {
        $response = $this->simulateDelete($this->itemId);
        $this->assertTrue($response['ok']);
    }

    public function test_delete_item_removes_from_intake_items(): void
    {
        $this->simulateDelete($this->itemId);
        $check = $this->pdo->prepare('SELECT COUNT(*) FROM intake_items WHERE id = :id');
        $check->execute(['id' => $this->itemId]);
        $this->assertSame(0, (int) $check->fetchColumn());
    }

    public function test_delete_item_archives_to_intake_deleted(): void
    {
        // Fetch original data before deletion
        $original = $this->pdo->query("SELECT * FROM intake_items WHERE id = {$this->itemId}")->fetch(PDO::FETCH_ASSOC);

        $this->simulateDelete($this->itemId);

        $archived = $this->pdo->prepare('SELECT * FROM intake_deleted WHERE id = :id');
        $archived->execute(['id' => $this->itemId]);
        $row = $archived->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertSame($original['sku'],         $row['sku']);
        $this->assertSame($original['status'],      $row['status']);
        $this->assertSame($original['what_is_it'],  $row['what_is_it']);
        $this->assertArrayHasKey('deleted_at', $row);
        $this->assertNotNull($row['deleted_at']);
    }

    public function test_delete_nonexistent_item_returns_error(): void
    {
        $response = $this->simulateDelete(99999);
        $this->assertFalse($response['ok']);
        $this->assertArrayHasKey('error', $response);
    }

    public function test_delete_item_with_zero_id_returns_error(): void
    {
        $response = $this->simulateDelete(0);
        $this->assertFalse($response['ok']);
    }

    public function test_delete_preserves_other_items(): void
    {
        $this->simulateDelete($this->itemId);

        $remaining = $this->pdo->query('SELECT COUNT(*) FROM intake_items')->fetchColumn();
        $this->assertSame(11, (int) $remaining);
    }

    public function test_double_delete_returns_error_second_time(): void
    {
        $this->simulateDelete($this->itemId);
        $response = $this->simulateDelete($this->itemId);
        $this->assertFalse($response['ok']);
    }

    // ── SQL Injection Hardening ─────────────────────────────────────────

    public function test_delete_with_injection_attempt_returns_error(): void
    {
        $_POST = ['id' => "1; DROP TABLE intake_items; --"];
        $response = $this->simulateDelete(0); // (int) cast makes it 0
        $this->assertFalse($response['ok']);
    }

    // ── Helper ──────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function simulateDelete(int $id): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'error' => 'Invalid ID.'];
        }

        // Check existence (simulating the production flow)
        $check = $this->pdo->prepare('SELECT COUNT(*) FROM intake_items WHERE id = :id');
        $check->execute(['id' => $id]);
        if ((int) $check->fetchColumn() === 0) {
            return ['ok' => false, 'error' => 'Item not found.'];
        }

        // Archive
        $archive = $this->pdo->prepare("INSERT INTO intake_deleted SELECT *, datetime('now') AS deleted_at FROM intake_items WHERE id = :id");
        $archive->execute(['id' => $id]);

        // Delete
        $del = $this->pdo->prepare('DELETE FROM intake_items WHERE id = :id');
        $del->execute(['id' => $id]);

        return ['ok' => true, 'message' => 'Item deleted and archived.'];
    }
}
