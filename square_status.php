<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/square_sync.php';
require_once __DIR__ . '/square_sync_queue.php';

checkMaintenance(true);
ensureStorageWritable();

$pdo = pdoConnect(__DIR__ . '/data/intake.sqlite');
squareSyncEnsureSchema($pdo);

$config = squareSyncConfig();

$connected = $config['enabled'];

$lastSyncStmt = $pdo->query("SELECT MAX(last_synced_at) FROM square_catalog_sync WHERE last_synced_at IS NOT NULL");
$lastSyncAt = (string)($lastSyncStmt->fetchColumn() ?: '');

$lastErrorStmt = $pdo->query("SELECT last_error, updated_at FROM square_catalog_sync WHERE last_error IS NOT NULL AND last_error <> '' ORDER BY updated_at DESC LIMIT 1");
$lastErrorRow = $lastErrorStmt->fetch(PDO::FETCH_ASSOC);
$lastError = $lastErrorRow ? (string)($lastErrorRow['last_error'] ?? '') : null;
$lastErrorSince = $lastErrorRow ? (string)($lastErrorRow['updated_at'] ?? '') : null;

$queueStats = [];
if ($config['enabled']) {
    $queueStats = squareQueueStats($pdo);
}

$webhookToday = 0;
$webhookFailed = 0;
$todayStart = date('Y-m-d') . ' 00:00:00';
try {
    $whStmt = $pdo->prepare("SELECT COUNT(*) FROM webhook_processed WHERE processed_at >= :today");
    $whStmt->execute(['today' => $todayStart]);
    $webhookToday = (int)$whStmt->fetchColumn();

    $whFailedStmt = $pdo->prepare("SELECT COUNT(*) FROM sync_audit_log WHERE timestamp >= :today AND direction = 'pull' AND status = 'failure'");
    $whFailedStmt->execute(['today' => $todayStart]);
    $webhookFailed = (int)$whFailedStmt->fetchColumn();
} catch (Throwable $e) {
    // Table may not exist yet
}

$recentSale = null;
try {
    $saleStmt = $pdo->query("SELECT sku_normalized, sale_price, sold_at FROM sales_history ORDER BY sold_at DESC LIMIT 1");
    $recentSaleRow = $saleStmt->fetch(PDO::FETCH_ASSOC);
    if ($recentSaleRow) {
        $recentSale = [
            'sku' => (string)($recentSaleRow['sku_normalized'] ?? ''),
            'price' => (float)($recentSaleRow['sale_price'] ?? 0),
            'sold_at' => (string)($recentSaleRow['sold_at'] ?? ''),
        ];
    }
} catch (Throwable $e) {
    // Table may not exist yet
}

$soldToday = 0;
try {
    $soldStmt = $pdo->prepare("SELECT COUNT(*) FROM sales_history WHERE sold_at >= :today");
    $soldStmt->execute(['today' => $todayStart]);
    $soldToday = (int)$soldStmt->fetchColumn();
} catch (Throwable $e) {
    // Table may not exist yet
}

successResponse([
    'connected' => $connected,
    'last_sync_at' => $lastSyncAt ?: null,
    'last_error' => $lastError,
    'last_error_since' => $lastErrorSince,
    'queue_waiting' => ($queueStats['queued'] ?? 0) + ($queueStats['retrying'] ?? 0),
    'queue_failed' => $queueStats['failed'] ?? 0,
    'queue_dead_letter' => $queueStats['dead_letter'] ?? 0,
    'webhooks_today' => $webhookToday,
    'webhooks_failed' => $webhookFailed,
    'sold_today' => $soldToday,
    'recent_sale' => $recentSale,
]);
