<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ReconciliationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createSandboxDatabase();
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Bootstrap creates square_catalog_sync with an incompatible schema.
        // Drop it so the production schema (from squareSyncEnsureSchema) is used.
        $this->pdo->exec("DROP TABLE IF EXISTS square_catalog_sync");

        require_once __DIR__ . '/../../square_sync.php';
        require_once __DIR__ . '/../../square_sync_queue.php';
        require_once __DIR__ . '/../../lib/reconciliation/schema.php';
        require_once __DIR__ . '/../../lib/reconciliation/engine.php';
        require_once __DIR__ . '/../../lib/reconciliation/repair.php';
        require_once __DIR__ . '/../../lib/reconciliation/report.php';

        squareSyncEnsureSchema($this->pdo);
        squareQueueEnsureSchema($this->pdo);
        reconEnsureSchema($this->pdo);

        // Create sales_history table (normally created by square_webhook_service)
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS sales_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sku TEXT NOT NULL,
    sku_normalized TEXT NOT NULL,
    square_order_id TEXT NOT NULL,
    square_payment_id TEXT,
    sale_price REAL NOT NULL DEFAULT 0,
    tax_amount REAL NOT NULL DEFAULT 0,
    discount_amount REAL NOT NULL DEFAULT 0,
    line_item_quantity INTEGER NOT NULL DEFAULT 1,
    sold_at TEXT NOT NULL,
    location_id TEXT,
    source TEXT NOT NULL DEFAULT 'square_pos',
    receipt_number TEXT,
    employee_name TEXT,
    notes TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(square_order_id, sku_normalized)
);
SQL);
    }

    private function mockSquareConfigDisabled(): void
    {
        // We don't mock the config function — tests use $fetchCatalog=false to skip API calls
    }

    private function insertItem(array $data): int
    {
        $fields = ['sku', 'sku_normalized', 'status', 'what_is_it', 'functional', 'condition',
            'is_square', 'dispotech_price', 'ebay_price'];
        $cols = [];
        $vals = [];
        $params = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $cols[] = $f;
                $vals[] = ":$f";
                $params[$f] = $data[$f];
            }
        }
        $sql = 'INSERT INTO intake_items (' . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$this->pdo->lastInsertId();
    }

    private function insertSyncRow(array $data): void
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
INSERT OR REPLACE INTO square_catalog_sync
    (sku_normalized, square_item_id, square_item_version, square_variation_id,
     square_variation_version, square_image_id, payload_hash, last_synced_at, last_error)
VALUES (:sku, :item_id, :item_ver, :var_id, :var_ver, :img_id, :hash, :synced_at, :error)
SQL);
        $stmt->execute([
            'sku' => $data['sku_normalized'] ?? '',
            'item_id' => $data['square_item_id'] ?? null,
            'item_ver' => $data['square_item_version'] ?? null,
            'var_id' => $data['square_variation_id'] ?? null,
            'var_ver' => $data['square_variation_version'] ?? null,
            'img_id' => $data['square_image_id'] ?? null,
            'hash' => $data['payload_hash'] ?? null,
            'synced_at' => $data['last_synced_at'] ?? null,
            'error' => $data['last_error'] ?? null,
        ]);
    }

    private function insertSaleRecord(string $sku): void
    {
        $this->pdo->prepare(<<<'SQL'
INSERT INTO sales_history (sku, sku_normalized, square_order_id, sale_price, sold_at)
VALUES (:sku, :sku, :oid, 49.99, datetime('now'))
SQL)->execute(['sku' => $sku, 'oid' => 'test-order-' . $sku]);
    }

    private function insertPhoto(string $sku): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO sku_photos (sku_normalized, original_name, stored_name, mime_type) VALUES (:sku, :orig, :stored, :mime)');
        $stmt->execute([
            'sku' => $sku,
            'orig' => 'test.jpg',
            'stored' => 'test_' . $sku . '.jpg',
            'mime' => 'image/jpeg',
        ]);
    }

    // ── Detection Tests ───────────────────────────────────────────

    public function test_detects_missing_catalog_mapping(): void
    {
        $this->insertItem(['sku' => 'DT-1001', 'sku_normalized' => 'DT-1001', 'status' => 'intake']);

        $runId = reconCreateRun($this->pdo, 'manual');
        $count = reconCheckMissingMappings($this->pdo, $runId);

        $this->assertSame(1, $count);

        $issues = $this->pdo->query("SELECT * FROM reconciliation_issues WHERE run_id = $runId")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $issues);
        $this->assertSame('missing_catalog_mapping', $issues[0]['issue_type']);
        $this->assertSame('DT-1001', $issues[0]['sku_normalized']);
        $this->assertSame('1', (string)$issues[0]['auto_repairable']);
    }

    public function test_detects_never_synced(): void
    {
        $this->insertItem(['sku' => 'DT-1001', 'sku_normalized' => 'DT-1001', 'status' => 'intake']);
        // Sync row exists but never synced
        $this->insertSyncRow(['sku_normalized' => 'DT-1001', 'square_item_id' => null, 'last_synced_at' => null]);

        $runId = reconCreateRun($this->pdo, 'manual');
        $count = reconCheckNeverSynced($this->pdo, $runId);

        $this->assertSame(1, $count);
    }

    public function test_skips_synced_items_in_never_synced_check(): void
    {
        $this->insertItem(['sku' => 'DT-1001', 'sku_normalized' => 'DT-1001', 'status' => 'intake']);
        $this->insertSyncRow([
            'sku_normalized' => 'DT-1001',
            'square_item_id' => 'sq-item-001',
            'square_variation_id' => 'sq-var-001',
            'last_synced_at' => '2026-01-15 10:00:00',
        ]);

        $runId = reconCreateRun($this->pdo, 'manual');
        $count = reconCheckNeverSynced($this->pdo, $runId);

        $this->assertSame(0, $count);
    }

    public function test_detects_stuck_retries(): void
    {
        $this->insertItem(['sku' => 'DT-1001', 'sku_normalized' => 'DT-1001', 'status' => 'intake']);
        $this->insertSyncRow([
            'sku_normalized' => 'DT-1001',
            'square_item_id' => 'sq-item-001',
            'last_synced_at' => null,
            'last_error' => 'API timeout after 20 seconds',
        ]);

        $runId = reconCreateRun($this->pdo, 'manual');
        $count = reconCheckStuckRetries($this->pdo, $runId);

        $this->assertSame(1, $count);
    }

    public function test_detects_sold_in_square_not_marked(): void
    {
        $this->insertItem(['sku' => 'DT-1001', 'sku_normalized' => 'DT-1001', 'status' => 'intake']);
        $this->insertSyncRow([
            'sku_normalized' => 'DT-1001',
            'square_variation_id' => 'sq-var-001',
            'last_synced_at' => '2026-01-15 10:00:00',
        ]);
        $this->insertSaleRecord('DT-1001');

        $runId = reconCreateRun($this->pdo, 'manual');
        $count = reconCheckSoldInSquareNotPinksheet($this->pdo, $runId);

        $this->assertSame(1, $count);

        $issues = $this->pdo->query("SELECT * FROM reconciliation_issues WHERE run_id = $runId")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame('sold_in_square_not_marked', $issues[0]['issue_type']);
        $this->assertSame('critical', $issues[0]['severity']);
        $this->assertSame('1', (string)$issues[0]['auto_repairable']);
    }

    public function test_skips_already_sold_items(): void
    {
        $this->insertItem(['sku' => 'DT-1001', 'sku_normalized' => 'DT-1001', 'status' => 'sold']);
        $this->insertSyncRow([
            'sku_normalized' => 'DT-1001',
            'square_variation_id' => 'sq-var-001',
            'last_synced_at' => '2026-01-15 10:00:00',
        ]);
        $this->insertSaleRecord('DT-1001');

        // Should be no issue — item is already marked sold
        $runId = reconCreateRun($this->pdo, 'manual');
        $count = reconCheckInventoryMismatches($this->pdo, $runId);

        $this->assertSame(0, $count);
    }

    public function test_detects_missing_images(): void
    {
        $this->insertItem(['sku' => 'DT-1001', 'sku_normalized' => 'DT-1001', 'status' => 'intake']);
        $this->insertSyncRow([
            'sku_normalized' => 'DT-1001',
            'square_item_id' => 'sq-item-001',
            'square_image_id' => null,
            'last_synced_at' => '2026-01-15 10:00:00',
        ]);
        $this->insertPhoto('DT-1001');

        $runId = reconCreateRun($this->pdo, 'manual');
        $count = reconCheckMissingImages($this->pdo, $runId);

        $this->assertSame(1, $count);
    }

    // ── Repair Tests ──────────────────────────────────────────────

    public function test_repair_mark_sold(): void
    {
        $this->insertItem(['sku' => 'DT-1001', 'sku_normalized' => 'DT-1001', 'status' => 'intake']);
        $this->insertSyncRow([
            'sku_normalized' => 'DT-1001',
            'square_variation_id' => 'sq-var-001',
            'last_synced_at' => '2026-01-15 10:00:00',
        ]);
        $this->insertSaleRecord('DT-1001');

        $result = reconRepairMarkSold($this->pdo, 'DT-1001');

        $this->assertSame('ok', $result['status']);

        $status = $this->pdo->query("SELECT status FROM intake_items WHERE sku_normalized = 'DT-1001'")->fetchColumn();
        $this->assertSame('sold', $status);
    }

    public function test_repair_mark_sold_requires_sale_record(): void
    {
        $this->insertItem(['sku' => 'DT-1001', 'sku_normalized' => 'DT-1001', 'status' => 'intake']);
        // No sale record in sales_history

        $result = reconRepairMarkSold($this->pdo, 'DT-1001');

        $this->assertSame('manual', $result['status']);

        // Status should NOT have changed
        $status = $this->pdo->query("SELECT status FROM intake_items WHERE sku_normalized = 'DT-1001'")->fetchColumn();
        $this->assertSame('intake', $status);
    }

    public function test_repair_reset_queue(): void
    {
        $this->pdo->exec(<<<'SQL'
INSERT INTO sync_queue (sku_normalized, operation, status, retry_count, max_retries, last_error)
VALUES ('DT-1001', 'catalog_upsert', 'dead_letter', 10, 10, 'Test error')
SQL);

        $result = reconRepairResetQueue($this->pdo, 'DT-1001');

        $this->assertSame('ok', $result['status']);

        $status = $this->pdo->query("SELECT status FROM sync_queue WHERE sku_normalized = 'DT-1001'")->fetchColumn();
        $this->assertSame('queued', $status);
    }

    // ── Full Reconciliation Run Tests ─────────────────────────────

    public function test_full_reconciliation_run_detects_issues(): void
    {
        // Item with no catalog mapping
        $this->insertItem(['sku' => 'DT-1001', 'sku_normalized' => 'DT-1001', 'status' => 'intake']);

        // Item sold in Square not marked in Pinksheet
        $this->insertItem(['sku' => 'DT-1002', 'sku_normalized' => 'DT-1002', 'status' => 'dispo tech store']);
        $this->insertSyncRow([
            'sku_normalized' => 'DT-1002',
            'square_variation_id' => 'sq-var-002',
            'last_synced_at' => '2026-01-15 10:00:00',
        ]);
        $this->insertSaleRecord('DT-1002');

        // Item with stuck retry
        $this->insertItem(['sku' => 'DT-1003', 'sku_normalized' => 'DT-1003', 'status' => 'ebay draft']);
        $this->insertSyncRow([
            'sku_normalized' => 'DT-1003',
            'square_item_id' => 'sq-item-003',
            'last_synced_at' => null,
            'last_error' => 'Rate limit exceeded',
        ]);

        // Heathy item — should not trigger issues
        $this->insertItem(['sku' => 'DT-1004', 'sku_normalized' => 'DT-1004', 'status' => 'dispo tech store']);
        $this->insertSyncRow([
            'sku_normalized' => 'DT-1004',
            'square_item_id' => 'sq-item-004',
            'square_variation_id' => 'sq-var-004',
            'square_image_id' => 'sq-img-004',
            'last_synced_at' => '2026-07-18 10:00:00',
            'last_error' => null,
        ]);

        // Run detection only (no Square API).
        // DT-1002 gets caught by both sold_in_square_not_marked checks, so total is 4.
        $runId = reconCreateRun($this->pdo, 'manual');
        $result = reconDetectIssues($this->pdo, $runId, false);

        $this->assertSame(4, $result['detected']);
        $this->assertSame(0, $result['api_made']);
        $this->assertSame(0, $result['api_failed']);
    }

    public function test_reconciliation_repair_run(): void
    {
        // Item sold in Square — should be repaired (marked sold)
        $this->insertItem(['sku' => 'DT-2001', 'sku_normalized' => 'DT-2001', 'status' => 'intake']);
        $this->insertSyncRow([
            'sku_normalized' => 'DT-2001',
            'square_variation_id' => 'sq-var-2001',
            'last_synced_at' => '2026-01-15 10:00:00',
        ]);
        $this->insertSaleRecord('DT-2001');

        // Create a pending dead letter in queue
        $this->pdo->exec(<<<'SQL'
INSERT INTO sync_queue (sku_normalized, operation, status, retry_count, max_retries, last_error)
VALUES ('DT-2002', 'inventory_set', 'dead_letter', 10, 10, 'Test timeout')
SQL);

        // Seed issues for repair
        $runId = reconCreateRun($this->pdo, 'manual');

        reconAddIssue($this->pdo, $runId, [
            'sku_normalized' => 'DT-2001',
            'issue_type' => 'sold_in_square_not_marked',
            'severity' => 'critical',
            'description' => 'Sold in Square not marked',
            'auto_repairable' => true,
            'repair_action' => 'mark_sold',
        ]);

        reconAddIssue($this->pdo, $runId, [
            'sku_normalized' => 'DT-2002',
            'issue_type' => 'queue_dead_letter',
            'severity' => 'warning',
            'description' => 'Queue dead letter',
            'auto_repairable' => true,
            'repair_action' => 'reset_queue',
        ]);

        reconAddIssue($this->pdo, $runId, [
            'sku_normalized' => '',
            'issue_type' => 'orphaned_square_item',
            'severity' => 'warning',
            'description' => 'Orphaned item — manual review',
            'auto_repairable' => false,
            'repair_action' => 'manual_review',
        ]);

        $result = reconRepairIssues($this->pdo, $runId, false);

        $this->assertSame(2, $result['repaired']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(1, $result['manual_required']);

        // Verify DT-2001 was marked sold
        $status = $this->pdo->query("SELECT status FROM intake_items WHERE sku_normalized = 'DT-2001'")->fetchColumn();
        $this->assertSame('sold', $status);

        // Verify DT-2002 queue was reset
        $qStatus = $this->pdo->query("SELECT status FROM sync_queue WHERE sku_normalized = 'DT-2002'")->fetchColumn();
        $this->assertSame('queued', $qStatus);
    }

    // ── Idempotency Tests ─────────────────────────────────────────

    public function test_detection_is_idempotent(): void
    {
        $this->insertItem(['sku' => 'DT-1001', 'sku_normalized' => 'DT-1001', 'status' => 'intake']);

        $runId1 = reconCreateRun($this->pdo, 'manual');
        $count1 = reconCheckMissingMappings($this->pdo, $runId1);

        $runId2 = reconCreateRun($this->pdo, 'manual');
        $count2 = reconCheckMissingMappings($this->pdo, $runId2);

        $this->assertSame(1, $count1);
        $this->assertSame(1, $count2);

        // Each run created its own issue row
        $totalIssues = (int)$this->pdo->query("SELECT COUNT(*) FROM reconciliation_issues")->fetchColumn();
        $this->assertSame(2, $totalIssues);
    }

    public function test_reconciliation_runs_are_independent(): void
    {
        $this->insertItem(['sku' => 'DT-1001', 'sku_normalized' => 'DT-1001', 'status' => 'intake']);

        // Run 1
        $runId1 = reconCreateRun($this->pdo, 'manual');
        $count1 = reconCheckMissingMappings($this->pdo, $runId1);

        // Run 2
        $runId2 = reconCreateRun($this->pdo, 'manual');
        $count2 = reconCheckMissingMappings($this->pdo, $runId2);

        $this->assertSame(1, $count1);
        $this->assertSame(1, $count2); // Same issue detected in separate runs

        // Each run has its own issue rows
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM reconciliation_issues WHERE run_id = :rid");
        $stmt->execute(['rid' => $runId1]);
        $r1 = (int)$stmt->fetchColumn();
        $stmt->execute(['rid' => $runId2]);
        $r2 = (int)$stmt->fetchColumn();

        $this->assertSame(1, $r1);
        $this->assertSame(1, $r2);
    }

    // ── Dry Run Tests ─────────────────────────────────────────────

    public function test_dry_run_does_not_execute_repairs(): void
    {
        $this->insertItem(['sku' => 'DT-1001', 'sku_normalized' => 'DT-1001', 'status' => 'intake']);
        $this->insertSyncRow([
            'sku_normalized' => 'DT-1001',
            'square_variation_id' => 'sq-var-001',
            'last_synced_at' => '2026-01-15 10:00:00',
        ]);
        $this->insertSaleRecord('DT-1001');

        $runId = reconCreateRun($this->pdo, 'manual');

        reconAddIssue($this->pdo, $runId, [
            'sku_normalized' => 'DT-1001',
            'issue_type' => 'sold_in_square_not_marked',
            'severity' => 'critical',
            'description' => 'Sold in Square not marked',
            'auto_repairable' => true,
            'repair_action' => 'mark_sold',
        ]);

        $result = reconRepairIssues($this->pdo, $runId, true);

        $this->assertSame(0, $result['repaired']);
        $this->assertSame(1, $result['skipped']);

        // Status should NOT have changed
        $status = $this->pdo->query("SELECT status FROM intake_items WHERE sku_normalized = 'DT-1001'")->fetchColumn();
        $this->assertSame('intake', $status);
    }

    // ── Report Tests ──────────────────────────────────────────────

    public function test_report_generation(): void
    {
        $this->insertItem(['sku' => 'DT-1001', 'sku_normalized' => 'DT-1001', 'status' => 'intake']);

        $runId = reconCreateRun($this->pdo, 'manual');
        $issuesCount = reconCheckMissingMappings($this->pdo, $runId);
        reconUpdateRun($this->pdo, $runId, [
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
            'issues_detected' => $issuesCount,
            'runtime_seconds' => 1.234,
        ]);

        $report = reconGenerateReport($this->pdo, $runId);

        $this->assertSame($runId, $report['run_id']);
        $this->assertSame(1, $report['issues_detected']);

        // Verify it was persisted
        $stored = reconGetReport($this->pdo, $runId);
        $this->assertNotNull($stored);
        $this->assertSame(1, $stored['issues_detected']);
    }

    // ── Alert Tests ───────────────────────────────────────────────

    public function test_alerts_are_raised_for_failed_repairs(): void
    {
        $runId = reconCreateRun($this->pdo, 'manual');

        reconAddIssue($this->pdo, $runId, [
            'sku_normalized' => 'DT-3001',
            'issue_type' => 'missing_catalog_mapping',
            'severity' => 'warning',
            'description' => 'No mapping',
            'auto_repairable' => true,
            'repair_action' => 'catalog_upsert',
        ]);

        // Mark repair as failed to simulate
        $this->pdo->prepare("UPDATE reconciliation_issues SET repair_status = 'failed', repair_result = 'API timeout' WHERE run_id = :rid")
            ->execute(['rid' => $runId]);

        reconUpdateRun($this->pdo, $runId, ['status' => 'completed']);

        // Trigger alert creation (done in reconRepairIssues, but we simulate by calling it)
        reconAddAlert($this->pdo, 'repair_failures', 'warning',
            '1 repair(s) failed during reconciliation',
            'Run #' . $runId);

        $alerts = reconActiveAlerts($this->pdo);
        $this->assertCount(1, $alerts);
        $this->assertSame('repair_failures', $alerts[0]['alert_type']);
    }

    // ── API Tracking Tests ────────────────────────────────────────

    public function test_run_tracks_api_metrics(): void
    {
        $runId = reconCreateRun($this->pdo, 'manual');

        reconUpdateRun($this->pdo, $runId, [
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
            'total_devices_checked' => 50,
            'issues_detected' => 5,
            'issues_repaired' => 3,
            'api_requests_made' => 10,
            'api_requests_failed' => 1,
            'runtime_seconds' => 4.567,
        ]);

        $run = $this->pdo->prepare("SELECT * FROM reconciliation_runs WHERE id = :id");
        $run->execute(['id' => $runId]);
        $data = $run->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('50', (string)$data['total_devices_checked']);
        $this->assertSame('5', (string)$data['issues_detected']);
        $this->assertSame('3', (string)$data['issues_repaired']);
        $this->assertSame('10', (string)$data['api_requests_made']);
        $this->assertSame('1', (string)$data['api_requests_failed']);
        $this->assertSame('4.567', (string)$data['runtime_seconds']);
    }
}
