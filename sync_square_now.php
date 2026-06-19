<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/square_sync.php';
checkMaintenance(true);
ensureStorageWritable();
@set_time_limit(0);
@ignore_user_abort(true);



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
    errorResponse('Forbidden', 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    errorResponse('Method not allowed', 405);
}

require_csrf();

$config = squareSyncConfig();
if (!$config['enabled']) {
    errorResponse('Square sync is not configured', 400);
}

try {
    $pdo = pdoConnect(__DIR__ . '/data/intake.sqlite');
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

    $allOk = $summary['error'] === 0;
    $partial = $summary['ok'] > 0 && $summary['error'] > 0;
    successResponse([
        'ok' => true,
        'all_ok' => $allOk,
        'partial' => $partial,
        'summary' => $summary,
        'errors' => $errors,
        'message' => $allOk ? 'Square sync completed' : ($partial ? 'Square sync partially completed' : 'Square sync completed with errors'),
    ]);
} catch (Throwable $e) {
    errorResponse($e->getMessage(), 500);
}
