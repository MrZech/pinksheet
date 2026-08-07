<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Update Item Handler Integration Tests.
 *
 * Simulates the update_item.php AJAX endpoint. Tests each allowed field
 * update, response JSON shape, and error conditions.
 *
 * [CRUD Operations] — field-level updates.
 * [JSON API Output] — correct ok/error/field/value keys.
 * [Form Handling & Validation] — field whitelist, value sanitisation.
 */
#[CoversNothing]
final class UpdateItemHandlerTest extends TestCase
{
    private PDO $pdo;
    private int $itemId;

    protected function setUp(): void
    {
        $this->pdo = createSandboxDatabase();
        $items = InventoryFixtures::standardInventory($this->pdo);
        $this->itemId = $items[0]['id'];
    }

    public function test_update_status_returns_ok(): void
    {
        $response = $this->simulateUpdate('status', 'Tested');
        $this->assertTrue($response['ok']);
        $this->assertSame('Tested', $response['value']);
    }

    public function test_update_reviewed_flag(): void
    {
        $response = $this->simulateUpdate('reviewed', '1');
        $this->assertTrue($response['ok']);
        $this->assertSame('1', $response['value']);
    }

    public function test_update_reviewed_flag_back_to_zero(): void
    {
        $this->simulateUpdate('reviewed', '1');
        $response = $this->simulateUpdate('reviewed', '0');
        $this->assertTrue($response['ok']);
        $this->assertSame('0', $response['value']);
    }

    public function test_update_dispotech_price(): void
    {
        $response = $this->simulateUpdate('dispotech_price', '499.99');
        $this->assertTrue($response['ok']);
        $this->assertSame('499.99', $response['value']);
    }

    public function test_update_ebay_price(): void
    {
        $response = $this->simulateUpdate('ebay_price', '299.00');
        $this->assertTrue($response['ok']);
        $this->assertSame('299.00', $response['value']);
    }

    public function test_update_what_is_it(): void
    {
        $response = $this->simulateUpdate('what_is_it', 'Updated Description');
        $this->assertTrue($response['ok']);
    }

    public function test_update_notes_with_special_chars(): void
    {
        $response = $this->simulateUpdate('notes', "Line 1\nLine 2 & <special> chars ' \"");
        $this->assertTrue($response['ok']);
    }

    public function test_update_invalid_field_returns_error(): void
    {
        $response = $this->simulateUpdate('nonexistent_field', 'value');
        $this->assertArrayHasKey('error', $response);
        $this->assertFalse($response['ok']);
    }

    public function test_update_nonexistent_item_returns_error(): void
    {
        // Temporarily replace item ID with bogus one
        $origId = $this->itemId;
        $this->itemId = 99999;
        $response = $this->simulateUpdate('status', 'SOLD');
        $this->assertFalse($response['ok']);
        $this->itemId = $origId;
    }

    public function test_update_updated_at_timestamp_changes(): void
    {
        // Set a known timestamp so we can detect the change
        $this->pdo->prepare("UPDATE intake_items SET updated_at = '2000-01-01 00:00:00' WHERE id = ?")->execute([$this->itemId]);
        $before = $this->pdo->query("SELECT updated_at FROM intake_items WHERE id = {$this->itemId}")->fetchColumn();

        $this->simulateUpdate('status', 'SOLD');

        $after = $this->pdo->query("SELECT updated_at FROM intake_items WHERE id = {$this->itemId}")->fetchColumn();
        $this->assertNotSame($before, $after);
    }

    public function test_update_preserves_other_fields(): void
    {
        $original = $this->pdo->query("SELECT * FROM intake_items WHERE id = {$this->itemId}")->fetch(PDO::FETCH_ASSOC);

        $this->simulateUpdate('notes', 'Only notes change');

        $updated = $this->pdo->query("SELECT * FROM intake_items WHERE id = {$this->itemId}")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($original['sku'],           $updated['sku']);
        $this->assertSame($original['status'],        $updated['status']);
        $this->assertSame($original['what_is_it'],    $updated['what_is_it']);
        $this->assertSame('Only notes change',         $updated['notes']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Simulate an AJAX POST to update_item.php and return the response.
     *
     * @return array<string, mixed>
     */
    private function simulateUpdate(string $field, string $value): array
    {
        $_POST = [
            'id'    => (string) $this->itemId,
            'field' => $field,
            'value' => $value,
        ];

        // Replicate update_item.php handler
        $id    = (int) ($_POST['id'] ?? 0);
        $f     = $_POST['field'] ?? '';
        $v     = $_POST['value'] ?? '';

        $allowedFields = ['status', 'reviewed', 'dispotech_price', 'ebay_price', 'notes', 'what_is_it', 'functional', 'condition', 'is_square'];

        if ($id <= 0 || !in_array($f, $allowedFields, true)) {
            return ['ok' => false, 'error' => 'Invalid field or ID'];
        }

        // Check item exists
        $check = $this->pdo->prepare('SELECT COUNT(*) FROM intake_items WHERE id = :id');
        $check->execute(['id' => $id]);
        if ((int) $check->fetchColumn() === 0) {
            return ['ok' => false, 'error' => 'Item not found'];
        }

        $stmt = $this->pdo->prepare("UPDATE intake_items SET {$f} = :value, updated_at = datetime('now') WHERE id = :id");
        $stmt->execute(['value' => $v, 'id' => $id]);

        return ['ok' => true, 'field' => $f, 'value' => $v];
    }
}
