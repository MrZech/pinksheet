<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance(true);
ensureStorageWritable();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$sku = strtoupper(trim((string)($_POST['sku'] ?? '')));
$field = trim((string)($_POST['field'] ?? ''));
$value = $_POST['value'] ?? null;

if ($sku === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'SKU is required']);
    exit;
}

$allowedFields = [
    'status' => true,
    'dispotech_price' => true,
    'ebay_price' => true,
];
if (!isset($allowedFields[$field])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Field not allowed']);
    exit;
}

try {
    $pdo = new PDO('sqlite:' . __DIR__ . '/data/intake.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    if ($field === 'status') {
        $stmt = $pdo->prepare('UPDATE intake_items SET status = :val, updated_at = datetime("now") WHERE UPPER(sku) = :sku');
        $stmt->execute([':val' => (string)$value, ':sku' => $sku]);
    } else {
        $price = is_numeric($value) ? (float)$value : null;
        $stmt = $pdo->prepare("UPDATE intake_items SET {$field} = :val, updated_at = datetime('now') WHERE UPPER(sku) = :sku");
        $stmt->execute([':val' => $price, ':sku' => $sku]);
    }
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'SKU not found']);
        exit;
    }
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error']);
}
