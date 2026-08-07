<?php
declare(strict_types=1);

require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/repair.php';
require_once __DIR__ . '/report.php';

/**
 * Run a full reconciliation cycle: detect issues, repair where safe, generate report.
 *
 * @param PDO    $pdo
 * @param string $triggerType  'manual', 'scheduled', or 'ondemand'
 * @param bool   $dryRun       When true, no repairs are executed
 * @param bool   $fetchCatalog When true, fetches Square catalog for orphan detection
 * @return array{
 *   run_id: int,
 *   status: string,
 *   checked: int,
 *   detected: int,
 *   repaired: int,
 *   failed: int,
 *   manual: int,
 *   runtime: float,
 *   report: array,
 * }
 */
function reconRun(PDO $pdo, string $triggerType, bool $dryRun = false, bool $fetchCatalog = true): array
{
    $runId = reconCreateRun($pdo, $triggerType);

    try {
        // Phase 1: Detection
        $detectResult = reconDetectIssues($pdo, $runId, $fetchCatalog);

        // Phase 2: Repair
        $repairResult = reconRepairIssues($pdo, $runId, $dryRun);

        // Phase 3: Report
        $runtime = $detectResult['runtime'];
        $report = reconGenerateReport($pdo, $runId);

        reconUpdateRun($pdo, $runId, [
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
            'runtime_seconds' => round($runtime, 3),
        ]);

        return [
            'run_id' => $runId,
            'status' => 'completed',
            'checked' => (int)$pdo->query("SELECT COUNT(*) FROM intake_items WHERE sku_normalized IS NOT NULL AND TRIM(sku_normalized) <> ''")->fetchColumn(),
            'detected' => $detectResult['detected'],
            'repaired' => $repairResult['repaired'],
            'failed' => $repairResult['failed'],
            'manual' => $repairResult['manual_required'],
            'runtime' => round($runtime, 3),
            'report' => $report,
        ];
    } catch (Throwable $e) {
        reconUpdateRun($pdo, $runId, [
            'status' => 'failed',
            'completed_at' => date('Y-m-d H:i:s'),
            'error_message' => $e->getMessage(),
        ]);
        squareSyncLog('Reconciliation run #' . $runId . ' failed: ' . $e->getMessage());

        reconAddAlert($pdo, 'reconciliation_failed', 'critical',
            'Reconciliation run failed',
            'Run #' . $runId . ' (' . $triggerType . '): ' . $e->getMessage());

        return [
            'run_id' => $runId,
            'status' => 'failed',
            'checked' => 0,
            'detected' => 0,
            'repaired' => 0,
            'failed' => 0,
            'manual' => 0,
            'runtime' => 0,
            'report' => [],
            'error' => $e->getMessage(),
        ];
    }
}

function reconGetStatus(PDO $pdo): array
{
    $config = squareSyncConfig();

    $lastRun = $pdo->query("SELECT * FROM reconciliation_runs ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    $pendingIssues = 0;
    $repairFailures = 0;
    $alertsActive = 0;
    $alertsCritical = 0;

    try {
        $pendingIssues = (int)$pdo->query("SELECT COUNT(*) FROM reconciliation_issues WHERE repair_status IN ('pending', 'failed', 'manual_required')")->fetchColumn();
        $repairFailures = (int)$pdo->query("SELECT COUNT(*) FROM reconciliation_issues WHERE repair_status = 'failed'")->fetchColumn();
        $alertsActive = (int)$pdo->query("SELECT COUNT(*) FROM reconciliation_alerts WHERE dismissed = 0")->fetchColumn();
        $alertsCritical = (int)$pdo->query("SELECT COUNT(*) FROM reconciliation_alerts WHERE dismissed = 0 AND severity = 'critical'")->fetchColumn();
    } catch (Throwable $e) {}

    $queueStats = [];
    try {
        $queueStats = squareQueueStats($pdo);
    } catch (Throwable $e) {}

    return [
        'connected' => $config['enabled'],
        'last_run' => $lastRun ? [
            'id' => (int)$lastRun['id'],
            'trigger_type' => $lastRun['trigger_type'],
            'status' => $lastRun['status'],
            'started_at' => $lastRun['started_at'],
            'completed_at' => $lastRun['completed_at'],
            'issues_detected' => (int)($lastRun['issues_detected'] ?? 0),
            'issues_repaired' => (int)($lastRun['issues_repaired'] ?? 0),
            'runtime_seconds' => (float)($lastRun['runtime_seconds'] ?? 0),
        ] : null,
        'pending_issues' => $pendingIssues,
        'repair_failures' => $repairFailures,
        'alerts_total' => $alertsActive,
        'alerts_critical' => $alertsCritical,
        'queue_dead_letter' => $queueStats['dead_letter'] ?? 0,
        'queue_retrying' => $queueStats['retrying'] ?? 0,
        'is_running' => $lastRun && ($lastRun['status'] ?? '') === 'running',
    ];
}
