<?php
declare(strict_types=1);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/../../square_sync.php';
require_once __DIR__ . '/../../square_sync_queue.php';

function reconRepairIssues(PDO $pdo, int $runId, bool $dryRun = false): array
{
    $repaired = 0;
    $failed = 0;
    $skipped = 0;
    $manualRequired = 0;

    $stmt = $pdo->prepare(<<<'SQL'
SELECT * FROM reconciliation_issues
WHERE run_id = :rid
  AND repair_status = 'pending'
  AND auto_repairable = 1
ORDER BY
  CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END,
  id ASC
SQL);
    $stmt->execute(['rid' => $runId]);
    $issues = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($issues as $issue) {
        $issueId = (int)$issue['id'];
        $sku = (string)($issue['sku_normalized'] ?? '');
        $repairAction = (string)($issue['repair_action'] ?? '');

        if ($dryRun) {
            reconUpdateIssue($pdo, $issueId, [
                'repair_status' => 'skipped',
                'repair_result' => 'Dry run — would execute: ' . $repairAction,
                'repaired_at' => date('Y-m-d H:i:s'),
            ]);
            $skipped++;
            continue;
        }

        $result = reconExecuteRepair($pdo, $sku, $repairAction, $issue);

        if ($result['status'] === 'ok') {
            reconUpdateIssue($pdo, $issueId, [
                'repair_status' => 'auto_repaired',
                'repair_result' => $result['message'],
                'repaired_at' => date('Y-m-d H:i:s'),
            ]);
            $repaired++;
        } elseif ($result['status'] === 'manual') {
            reconUpdateIssue($pdo, $issueId, [
                'repair_status' => 'manual_required',
                'repair_result' => $result['message'],
            ]);
            $manualRequired++;
        } else {
            reconUpdateIssue($pdo, $issueId, [
                'repair_status' => 'failed',
                'repair_result' => $result['message'],
            ]);
            $failed++;
        }
    }

    // Count pending non-repairable issues as manual-required
    $pmStmt = $pdo->prepare("SELECT COUNT(*) FROM reconciliation_issues WHERE run_id = :rid AND repair_status = 'pending' AND auto_repairable = 0");
    $pmStmt->execute(['rid' => $runId]);
    $manualRequired += (int)$pmStmt->fetchColumn();

    reconUpdateRun($pdo, $runId, [
        'issues_repaired' => $repaired,
        'manual_actions_required' => $manualRequired,
    ]);

    // Raise alerts for persistent failures
    if ($failed > 0) {
        reconAddAlert($pdo, 'repair_failures', 'warning',
            "$failed repair(s) failed during reconciliation",
            "Run #$runId had $failed failed repairs. Check logs for details.");
    }
    if ($manualRequired > 0) {
        reconAddAlert($pdo, 'manual_actions_required', 'warning',
            "$manualRequired issue(s) require manual review",
            "Run #$runId identified $manualRequired items needing technician attention.");
    }

    return [
        'repaired' => $repaired,
        'failed' => $failed,
        'skipped' => $skipped,
        'manual_required' => $manualRequired,
    ];
}

function reconExecuteRepair(PDO $pdo, string $sku, string $action, array $issue): array
{
    return match ($action) {
        'catalog_upsert', 'full_sync' => reconRepairSyncItem($pdo, $sku),
        'inventory_set' => reconRepairInventory($pdo, $sku),
        'mark_sold' => reconRepairMarkSold($pdo, $sku),
        'reset_queue' => reconRepairResetQueue($pdo, $sku),
        'manual_review' => ['status' => 'manual', 'message' => 'Requires manual review: ' . ($issue['description'] ?? '')],
        default => ['status' => 'error', 'message' => 'Unknown repair action: ' . $action],
    };
}

function reconRepairSyncItem(PDO $pdo, string $sku): array
{
    if ($sku === '') {
        return ['status' => 'error', 'message' => 'Empty SKU'];
    }
    try {
        $result = squareSyncItemBySku($pdo, $sku);
        if (($result['status'] ?? '') === 'ok' || ($result['status'] ?? '') === 'skipped') {
            return ['status' => 'ok', 'message' => ($result['message'] ?? 'Synced') . ' for ' . $sku];
        }
        return ['status' => 'error', 'message' => ($result['message'] ?? 'Sync failed') . ' for ' . $sku];
    } catch (Throwable $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

function reconRepairInventory(PDO $pdo, string $sku): array
{
    if ($sku === '') {
        return ['status' => 'error', 'message' => 'Empty SKU'];
    }
    try {
        squareQueueEnqueue($pdo, $sku, 'inventory_set', 10);
        return ['status' => 'ok', 'message' => 'Inventory set queued for ' . $sku];
    } catch (Throwable $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

function reconRepairMarkSold(PDO $pdo, string $sku): array
{
    if ($sku === '') {
        return ['status' => 'error', 'message' => 'Empty SKU'];
    }
    try {
        // Verify sale exists before marking
        $check = $pdo->prepare("SELECT COUNT(*) FROM sales_history WHERE sku_normalized = :sku");
        $check->execute(['sku' => $sku]);
        if ((int)$check->fetchColumn() === 0) {
            return ['status' => 'manual', 'message' => "SKU $sku has no sale record — manual review required before marking sold"];
        }

        $update = $pdo->prepare("UPDATE intake_items SET status = 'sold', updated_at = datetime('now') WHERE sku_normalized = :sku AND status != 'sold'");
        $update->execute(['sku' => $sku]);

        if ($update->rowCount() > 0) {
            squareQueueEnqueue($pdo, $sku, 'inventory_set', 10);
            return ['status' => 'ok', 'message' => 'Marked ' . $sku . ' as sold in Pinksheet'];
        }
        return ['status' => 'ok', 'message' => $sku . ' was already marked sold'];
    } catch (Throwable $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

function reconRepairResetQueue(PDO $pdo, string $sku): array
{
    if ($sku === '') {
        // Reset all dead letters
        squareQueueResetDeadLetter($pdo);
        return ['status' => 'ok', 'message' => 'All dead letter queue items reset'];
    }
    try {
        squareQueueResetDeadLetter($pdo, $sku);
        return ['status' => 'ok', 'message' => 'Queue reset for ' . $sku];
    } catch (Throwable $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}
