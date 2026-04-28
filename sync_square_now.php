<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/square_sync.php';
checkMaintenance(true);
ensureStorageWritable();

header('Content-Type: application/json; charset=utf-8');

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
$isPrivate = false;
if ($remote !== '') {
    $isPrivate = $remote === '127.0.0.1'
        || $remote === '::1'
        || filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}
if (!$isPrivate && $remote === '') {
    $isPrivate = in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true)
        || str_starts_with($host, 'localhost:')
        || str_starts_with($host, '127.0.0.1:')
        || str_starts_with($host, '[::1]:');
}
if (!$isPrivate) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$config = squareSyncConfig();
if (!$config['enabled']) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Square sync is not configured']);
    exit;
}

try {
    $pdo = new PDO('sqlite:' . __DIR__ . '/data/intake.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    squareSyncEnsureSchema($pdo);

    $skus = $pdo->query("
        SELECT DISTINCT sku_normalized
        FROM intake_items
        WHERE sku_normalized IS NOT NULL
          AND TRIM(sku_normalized) <> ''
        ORDER BY sku_normalized ASC
    ")->fetchAll(PDO::FETCH_COLUMN);

    $summary = [
        'ok' => 0,
        'skipped' => 0,
        'error' => 0,
        'disabled' => 0,
        'total' => count($skus),
    ];
    $errors = [];

    foreach ($skus as $sku) {
        $sku = strtoupper(trim((string)$sku));
        if ($sku === '') {
            continue;
        }
        $result = squareSyncItemBySku($pdo, $sku);
        $status = (string)($result['status'] ?? 'skipped');
        if (!array_key_exists($status, $summary)) {
            $status = 'skipped';
        }
        $summary[$status]++;
        if ($status === 'error' && count($errors) < 8) {
            $errors[] = [
                'sku' => $sku,
                'message' => (string)($result['message'] ?? 'Unknown error'),
            ];
        }
    }

    $ok = $summary['error'] === 0;
    http_response_code($ok ? 200 : 500);
    echo json_encode([
        'ok' => $ok,
        'summary' => $summary,
        'errors' => $errors,
        'message' => 'Square sync completed',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
}
