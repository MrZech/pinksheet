<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance(true);
ensureStorageWritable();

const DB_PATH = __DIR__ . '/data/intake.sqlite';

$sku = normalizeSku((string)($_GET['sku'] ?? ''));
if ($sku === '') {
    errorResponse('SKU is required');
}

try {
    $pdo = pdoConnect(DB_PATH);
    $stmt = $pdo->prepare('SELECT * FROM intake_items WHERE sku_normalized = :sku ORDER BY id DESC LIMIT 1');
    $stmt->execute(['sku' => $sku]);
    $row = $stmt->fetch();
    if (!$row) {
        errorResponse('No record for that SKU', 404);
    }
    // Strip fields we should not copy directly.
    unset($row['id'], $row['sku'], $row['sku_normalized'], $row['created_at'], $row['updated_at']);
    successResponse([
        'status' => 'ok',
        'data' => $row,
    ]);
} catch (Throwable $e) {
    errorResponse('Server error', 500);
}
