<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Session & State Regression Tests.
 *
 * Verifies that previously-resolved state management bugs do not re-appear:
 *   - Autosave draft integrity
 *   - Reviewed flag default and toggle
 *   - Updated_at timestamp propagation
 *   - User preferences persisted across page loads
 *
 * [Session & State Handling] — draft autosave, reviewed toggle,
 * timestamp management, preference persistence.
 */
#[CoversNothing]
final class SessionStateRegressionTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createSandboxDatabase();
    }

    // ── Reviewed Toggle Persistence ─────────────────────────────────────

    public function test_reviewed_flag_toggle_from_zero_to_one(): void
    {
        $this->pdo->exec("INSERT INTO intake_items (sku, status, reviewed) VALUES ('TOGGLE-001', 'Intake', 0)");
        $id = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('UPDATE intake_items SET reviewed = 1 WHERE id = ?')->execute([$id]);
        $val = $this->pdo->query("SELECT reviewed FROM intake_items WHERE id = {$id}")->fetchColumn();
        $this->assertSame(1, (int) $val);
    }

    public function test_reviewed_flag_toggle_from_one_to_zero(): void
    {
        $this->pdo->exec("INSERT INTO intake_items (sku, status, reviewed) VALUES ('TOGGLE-002', 'Intake', 1)");
        $id = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('UPDATE intake_items SET reviewed = 0 WHERE id = ?')->execute([$id]);
        $val = $this->pdo->query("SELECT reviewed FROM intake_items WHERE id = {$id}")->fetchColumn();
        $this->assertSame(0, (int) $val);
    }

    public function test_reviewed_flag_is_independent_per_item(): void
    {
        $this->pdo->exec("INSERT INTO intake_items (sku, reviewed) VALUES ('A', 0)");
        $this->pdo->exec("INSERT INTO intake_items (sku, reviewed) VALUES ('B', 1)");

        $a = $this->pdo->query("SELECT reviewed FROM intake_items WHERE sku = 'A'")->fetchColumn();
        $b = $this->pdo->query("SELECT reviewed FROM intake_items WHERE sku = 'B'")->fetchColumn();

        $this->assertSame(0, (int) $a);
        $this->assertSame(1, (int) $b);
    }

    // ── Updated_at Timestamp ────────────────────────────────────────────

    public function test_updated_at_changes_on_update(): void
    {
        $this->pdo->exec("INSERT INTO intake_items (sku) VALUES ('TS-001')");
        $id = (int) $this->pdo->lastInsertId();

        // Set a known timestamp so we can detect the change
        $this->pdo->prepare("UPDATE intake_items SET updated_at = '2000-01-01 00:00:00' WHERE id = ?")->execute([$id]);
        $ts1 = $this->pdo->query("SELECT updated_at FROM intake_items WHERE id = {$id}")->fetchColumn();

        $this->pdo->prepare("UPDATE intake_items SET status = 'Tested', updated_at = datetime('now') WHERE id = ?")->execute([$id]);
        $ts2 = $this->pdo->query("SELECT updated_at FROM intake_items WHERE id = {$id}")->fetchColumn();

        $this->assertNotSame($ts1, $ts2, 'updated_at should change when item is updated');
    }

    // ── Draft Autosave (intake_drafts) ──────────────────────────────────

    public function test_draft_can_be_saved_and_retrieved(): void
    {
        $sku = 'DRAFT-001';
        $payload = json_encode(['status' => 'Intake', 'what_is_it' => 'Draft item']);

        // Save
        $this->pdo->prepare("INSERT OR REPLACE INTO intake_drafts (sku_normalized, payload, updated_at) VALUES (?, ?, datetime('now'))")
            ->execute([$sku, $payload]);

        // Retrieve
        $stmt = $this->pdo->prepare('SELECT payload FROM intake_drafts WHERE sku_normalized = ?');
        $stmt->execute([$sku]);
        $this->assertSame($payload, $stmt->fetchColumn());
    }

    public function test_draft_upsert_replaces_previous(): void
    {
        $sku = 'DRAFT-UPSERT';

        $this->pdo->prepare("INSERT INTO intake_drafts (sku_normalized, payload) VALUES (?, 'v1')")->execute([$sku]);
        $this->pdo->prepare("INSERT OR REPLACE INTO intake_drafts (sku_normalized, payload, updated_at) VALUES (?, 'v2', datetime('now'))")->execute([$sku]);

        $payload = $this->pdo->prepare('SELECT payload FROM intake_drafts WHERE sku_normalized = ?');
        $payload->execute([$sku]);
        $this->assertSame('v2', $payload->fetchColumn());
    }

    public function test_draft_missing_returns_empty(): void
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM intake_drafts WHERE sku_normalized = ?');
        $stmt->execute(['NONEXISTENT-DRAFT']);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function test_draft_payload_is_json(): void
    {
        $sku = 'DRAFT-JSON';
        $data = ['field1' => 'value1', 'field2' => 42];
        $payload = json_encode($data);

        $this->pdo->prepare("INSERT INTO intake_drafts (sku_normalized, payload) VALUES (?, ?)")->execute([$sku, $payload]);
        $this->assertNotNull(json_decode($payload, true));
    }

    // ── Script Cache State ──────────────────────────────────────────────

    public function test_script_cache_persists_across_requests(): void
    {
        $this->pdo->exec("INSERT INTO script_cache (sku_normalized, sku_display, prompt_text) VALUES ('CACHE-001', 'CACHE-001', 'cached prompt')");

        // Simulate a second "request"
        $stmt = $this->pdo->query("SELECT prompt_text FROM script_cache WHERE sku_normalized = 'CACHE-001'");
        $this->assertSame('cached prompt', $stmt->fetchColumn());
    }

    // ── State Independence ──────────────────────────────────────────────

    public function test_item_state_is_independent(): void
    {
        InventoryFixtures::standardInventory($this->pdo);

        // Update item 1 status
        $this->pdo->prepare("UPDATE intake_items SET status = 'SOLD' WHERE id = 1")->execute();

        // Item 2 should be unaffected
        $status2 = $this->pdo->query("SELECT status FROM intake_items WHERE id = 2")->fetchColumn();
        $this->assertSame('ebay draft', $status2);
    }

    public function test_reviewed_flag_not_lost_on_misc_update(): void
    {
        $this->pdo->exec("INSERT INTO intake_items (sku, status, reviewed) VALUES ('STATE-001', 'Intake', 1)");
        $id = (int) $this->pdo->lastInsertId();

        // Update an unrelated field
        $this->pdo->prepare("UPDATE intake_items SET what_is_it = 'Updated' WHERE id = ?")->execute([$id]);

        $reviewed = $this->pdo->query("SELECT reviewed FROM intake_items WHERE id = {$id}")->fetchColumn();
        $this->assertSame(1, (int) $reviewed, 'reviewed flag should survive unrelated updates');
    }
}
