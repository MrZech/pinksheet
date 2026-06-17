<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/square_sync.php';
checkMaintenance(true);
ensureStorageWritable();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

require_csrf();

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
    'price' => true,
    'reviewed' => true,
    // Back-compat: old clients/inputs may still send these.
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
    squareSyncEnsureSchema($pdo);

    $columns = $pdo->query('PRAGMA table_info(intake_items)')->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_map(static fn($row) => (string)($row['name'] ?? ''), $columns);
    $hasSkuNormalized = in_array('sku_normalized', $columnNames, true);
    $skuWhere = $hasSkuNormalized
        ? '(UPPER(COALESCE(sku, \'\')) = :sku OR UPPER(COALESCE(sku_normalized, \'\')) = :sku)'
        : 'UPPER(COALESCE(sku, \'\')) = :sku';

    if ($field === 'status') {
        $stmt = $pdo->prepare('UPDATE intake_items SET status = :val, updated_at = datetime("now") WHERE ' . $skuWhere);
        $stmt->execute([':val' => (string)$value, ':sku' => $sku]);
    } elseif ($field === 'reviewed') {
        $reviewed = match (true) {
            $value === '2' || $value === 2 => 2,
            $value === '1' || $value === 1 || $value === true => 1,
            default => 0,
        };
        $stmt = $pdo->prepare('UPDATE intake_items SET reviewed = :val, updated_at = datetime("now") WHERE ' . $skuWhere);
        $stmt->execute([':val' => $reviewed, ':sku' => $sku]);
    } else {
        $price = is_numeric($value) ? (float)$value : null;
        // Unify pricing: treat any price update as the single canonical price.
        $stmt = $pdo->prepare("UPDATE intake_items SET dispotech_price = :val, ebay_price = :val, updated_at = datetime('now') WHERE " . $skuWhere);
        $stmt->execute([':val' => $price, ':sku' => $sku]);
    }
    // SQLite PDO often reports rowCount() === 0 when UPDATE matched rows but no column
    // values actually changed (e.g. status already that lane). Only 404 when SKU missing.
    if ($stmt->rowCount() === 0) {
        $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM intake_items WHERE ' . $skuWhere);
        $existsStmt->execute([':sku' => $sku]);
        if ((int) $existsStmt->fetchColumn() === 0) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'SKU not found']);
            exit;
        }
    }
    $updatedStmt = $pdo->prepare('SELECT updated_at FROM intake_items WHERE ' . $skuWhere);
    $updatedStmt->execute([':sku' => $sku]);
    $updatedAt = (string)($updatedStmt->fetchColumn() ?? '');
    $squareSync = squareSyncItemBySku($pdo, $sku);
    echo json_encode(['ok' => true, 'updated_at' => $updatedAt, 'square_sync' => $squareSync['status'] ?? 'skipped']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error']);
}
