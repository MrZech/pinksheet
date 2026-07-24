<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/square_sync.php';
checkMaintenance(true);
ensureStorageWritable();

require_csrf();

// Only allow local/private network.
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$isPrivate = false;
if ($remote !== '') {
    $isPrivate = $remote === '127.0.0.1'
        || $remote === '::1'
        || filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}
if (!$isPrivate) {
    errorResponse('Forbidden', 403);
}

$photoId = (int)($_POST['photo_id'] ?? 0);
$sku = strtoupper(trim((string)($_POST['sku'] ?? '')));
if ($photoId <= 0 || $sku === '') {
    errorResponse('photo_id and sku are required');
}

try {
    $pdo = pdoConnect(__DIR__ . '/data/intake.sqlite');
    try {
        $pdo->exec("ALTER TABLE sku_photos ADD COLUMN is_thumb INTEGER NOT NULL DEFAULT 0");
    } catch (Throwable $e) {
        // ignore if exists
    }
    squareSyncEnsureSchema($pdo);
    $pdo->beginTransaction();
    $clear = $pdo->prepare('UPDATE sku_photos SET is_thumb = 0 WHERE sku_normalized = :sku');
    $clear->execute([':sku' => $sku]);
    $set = $pdo->prepare('UPDATE sku_photos SET is_thumb = 1 WHERE id = :id AND sku_normalized = :sku');
    $set->execute([':id' => $photoId, ':sku' => $sku]);
    $pdo->commit();
    if ($set->rowCount() === 0) {
        errorResponse('Photo not found for that SKU', 404);
    }
    $squareSync = squareSyncItemBySku($pdo, $sku);
    successResponse(['square_sync' => $squareSync['status'] ?? 'skipped']);
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    errorResponse('DB error', 500);
}
