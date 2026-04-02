<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance(true);
ensureStorageWritable();

const DB_PATH = __DIR__ . '/data/intake.sqlite';

header('Content-Type: application/json; charset=utf-8');

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$sku = strtoupper(trim((string)($_POST['sku'] ?? '')));
$confirm = strtoupper(trim((string)($_POST['confirm'] ?? '')));

if ($id <= 0 || $sku === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing id or sku']);
    exit;
}

if ($confirm !== 'DELETE') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Confirm with DELETE']);
    exit;
}

try {
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $stmt = $pdo->prepare('DELETE FROM intake_items WHERE id = :id AND sku_normalized = :sku');
    $stmt->execute(['id' => $id, 'sku' => $sku]);
    $count = $stmt->rowCount();
    echo json_encode(['status' => 'ok', 'deleted' => $count]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}
