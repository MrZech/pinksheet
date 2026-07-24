<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance(true);
ensureStorageWritable();

require_csrf();

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

$photoIdsRaw = trim((string)($_POST['photo_ids'] ?? ''));
if ($photoIdsRaw === '') {
    errorResponse('photo_ids is required');
}

$ids = array_filter(array_map('intval', explode(',', $photoIdsRaw)));
if (count($ids) < 2) {
    errorResponse('At least 2 photo IDs are required to reorder');
}

try {
    $pdo = pdoConnect(__DIR__ . '/data/intake.sqlite');
    try {
        $pdo->exec("ALTER TABLE sku_photos ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0");
    } catch (Throwable $e) {
        // ignore if exists
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('UPDATE sku_photos SET sort_order = :sort_order WHERE id = :id');
    foreach ($ids as $index => $id) {
        $stmt->execute(['sort_order' => $index + 1, 'id' => $id]);
    }
    $pdo->commit();

    successResponse();
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    errorResponse('DB error', 500);
}
