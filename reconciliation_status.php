<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/square_sync.php';
require_once __DIR__ . '/lib/reconciliation/scheduler.php';

checkMaintenance(true);
ensureStorageWritable();

$pdo = pdoConnect(__DIR__ . '/data/intake.sqlite');
squareSyncEnsureSchema($pdo);

try {
    reconEnsureSchema($pdo);
} catch (Throwable $e) {
    // Tables may not exist yet on first call
}

$status = reconGetStatus($pdo);

$recentIssues = [];
try {
    $stmt = $pdo->query(<<<'SQL'
SELECT ri.*, rr.trigger_type, rr.started_at as run_started_at
FROM reconciliation_issues ri
JOIN reconciliation_runs rr ON rr.id = ri.run_id
WHERE ri.repair_status IN ('pending', 'failed', 'manual_required')
ORDER BY
  CASE ri.severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END,
  ri.id DESC
LIMIT 50
SQL);
    $recentIssues = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$recentRuns = [];
try {
    $stmt = $pdo->query("SELECT * FROM reconciliation_runs ORDER BY id DESC LIMIT 10");
    $recentRuns = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$latestReport = null;
try {
    $latestReport = reconGetLatestReport($pdo);
} catch (Throwable $e) {}

$alerts = [];
try {
    $alerts = reconActiveAlerts($pdo);
} catch (Throwable $e) {}

successResponse([
    'status' => $status,
    'issues' => $recentIssues,
    'recent_runs' => $recentRuns,
    'latest_report' => $latestReport,
    'alerts' => $alerts,
]);
