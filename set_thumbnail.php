<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/square_sync.php';
checkMaintenance(true);
ensureStorageWritable();

header('Content-Type: application/json; charset=utf-8');

// Only allow local/private network.
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$isPrivate = false;
if ($remote !== '') {
    $isPrivate = $remote === '127.0.0.1'
        || $remote === '::1'
        || filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}
if (!$isPrivate) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$photoId = (int)($_POST['photo_id'] ?? 0);
$sku = strtoupper(trim((string)($_POST['sku'] ?? '')));
if ($photoId <= 0 || $sku === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'photo_id and sku are required']);
    exit;
}

try {
    $pdo = new PDO('sqlite:' . __DIR__ . '/data/intake.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec("ALTER TABLE sku_photos ADD COLUMN is_thumb INTEGER NOT NULL DEFAULT 0");
} catch (Throwable $e) {
    // ignore if exists
}

try {
    $pdo = new PDO('sqlite:' . __DIR__ . '/data/intake.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    squareSyncEnsureSchema($pdo);
    $pdo->beginTransaction();
    $clear = $pdo->prepare('UPDATE sku_photos SET is_thumb = 0 WHERE sku_normalized = :sku');
    $clear->execute([':sku' => $sku]);
    $set = $pdo->prepare('UPDATE sku_photos SET is_thumb = 1 WHERE id = :id AND sku_normalized = :sku');
    $set->execute([':id' => $photoId, ':sku' => $sku]);
    $pdo->commit();
    if ($set->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Photo not found for that SKU']);
        exit;
    }
    $squareSync = squareSyncItemBySku($pdo, $sku);
    echo json_encode(['ok' => true, 'square_sync' => $squareSync['status'] ?? 'skipped']);
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error']);
}
