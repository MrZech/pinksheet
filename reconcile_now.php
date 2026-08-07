<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/square_sync.php';
require_once __DIR__ . '/lib/reconciliation/scheduler.php';

checkMaintenance(true);
ensureStorageWritable();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

require_csrf();

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$isPrivate = $remote === '127.0.0.1' || $remote === '::1'
    || filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
if (!$isPrivate) {
    errorResponse('Forbidden', 403);
}

$dryRun = ($_POST['dry_run'] ?? '') === '1';
$fetchCatalog = ($_POST['fetch_catalog'] ?? '1') === '1';
$triggerType = 'ondemand';

@set_time_limit(120);
@ignore_user_abort(true);

try {
    $pdo = pdoConnect(__DIR__ . '/data/intake.sqlite');
    squareSyncEnsureSchema($pdo);

    $result = reconRun($pdo, $triggerType, $dryRun, $fetchCatalog);

    if ($result['status'] === 'completed') {
        successResponse([
            'run_id' => $result['run_id'],
            'status' => 'completed',
            'checked' => $result['checked'],
            'detected' => $result['detected'],
            'repaired' => $result['repaired'],
            'failed' => $result['failed'],
            'manual' => $result['manual'],
            'runtime_seconds' => $result['runtime'],
            'message' => 'Reconciliation completed: ' . $result['detected'] . ' issues detected, ' . $result['repaired'] . ' repaired, ' . $result['manual'] . ' require manual review.',
        ]);
    } else {
        errorResponse('Reconciliation failed: ' . ($result['error'] ?? 'Unknown error'), 500);
    }
} catch (Throwable $e) {
    errorResponse('Reconciliation error: ' . $e->getMessage(), 500);
}
