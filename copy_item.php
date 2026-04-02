<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance(true);
ensureStorageWritable();

const DB_PATH = __DIR__ . '/data/intake.sqlite';

header('Content-Type: application/json; charset=utf-8');

function normalizeSku(string $sku): string
{
    return strtoupper(trim($sku));
}

$sku = normalizeSku((string)($_GET['sku'] ?? ''));
if ($sku === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'SKU is required']);
    exit;
}

try {
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $stmt = $pdo->prepare('SELECT * FROM intake_items WHERE sku_normalized = :sku ORDER BY id DESC LIMIT 1');
    $stmt->execute(['sku' => $sku]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['status' => 'not_found', 'message' => 'No record for that SKU']);
        exit;
    }
    // Strip fields we should not copy directly.
    unset($row['id'], $row['sku'], $row['sku_normalized'], $row['created_at'], $row['updated_at']);
    echo json_encode([
        'status' => 'ok',
        'data' => $row,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}
