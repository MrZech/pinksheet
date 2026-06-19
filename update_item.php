<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/square_sync.php';
checkMaintenance(true);
ensureStorageWritable();

/**
 * Return a flat JSON error so JS callers can check `data.ok` directly.
 */
function updateError(string $message, int $code = 400): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    updateError('Method not allowed', 405);
}

require_csrf();

$sku = strtoupper(trim((string)($_POST['sku'] ?? '')));
$field = trim((string)($_POST['field'] ?? ''));
$value = $_POST['value'] ?? null;

if ($sku === '') {
    updateError('SKU is required');
}

$allowedFields = [
    'status' => true,
    'price' => true,
    'reviewed' => true,
    'dispotech_price' => true,
    'ebay_price' => true,
];
if (!isset($allowedFields[$field])) {
    updateError('Field not allowed');
}

try {
    $pdo = pdoConnect(__DIR__ . '/data/intake.sqlite');
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
            updateError('SKU not found', 404);
        }
    }
    $updatedStmt = $pdo->prepare('SELECT updated_at FROM intake_items WHERE ' . $skuWhere);
    $updatedStmt->execute([':sku' => $sku]);
    $updatedAt = (string)($updatedStmt->fetchColumn() ?? '');
    $squareSync = squareSyncItemBySku($pdo, $sku);
    $syncStatus = $squareSync['status'] ?? 'skipped';

    // Return flat JSON so JS callers can check `data.ok` directly.
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'          => true,
        'updated_at'  => $updatedAt,
        'square_sync' => $syncStatus,
    ]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'    => false,
        'error' => 'DB error',
    ]);
    exit;
}
