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

function maskSecret(string $value): string
{
    $len = strlen($value);
    if ($len <= 8) {
        return str_repeat('*', $len);
    }
    return substr($value, 0, 4) . str_repeat('*', max(0, $len - 8)) . substr($value, -4);
}

function squareDebugJson(array $config, string $path): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL extension not enabled.');
    }
    $ch = curl_init($config['base_url'] . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Square-Version: ' . $config['api_version'],
            'Authorization: Bearer ' . $config['token'],
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($raw === false || $err !== '') {
        throw new RuntimeException('Request failed: ' . $err);
    }
    $decoded = json_decode((string)$raw, true);
    return [
        'status_code' => $code,
        'body' => is_array($decoded) ? $decoded : substr((string)$raw, 0, 500),
    ];
}

$config = squareSyncConfig();
$envPath = __DIR__ . '/.env';
$syncSku = strtoupper(trim((string)($_GET['sku'] ?? '')));
$runSync = isset($_GET['run_sync']) && $_GET['run_sync'] === '1';
$response = [
    'ok' => true,
    'server' => [
        'php_version' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'remote_addr' => $remote,
        'host' => $host,
    ],
    'env' => [
        'env_path' => $envPath,
        'env_exists' => is_file($envPath),
        'env_readable' => is_readable($envPath),
        'square_environment' => getenv('SQUARE_ENVIRONMENT') ?: null,
        'square_access_token_present' => trim((string)(getenv('SQUARE_ACCESS_TOKEN') ?: '')) !== '',
        'square_access_token_masked' => trim((string)(getenv('SQUARE_ACCESS_TOKEN') ?: '')) !== '' ? maskSecret((string)getenv('SQUARE_ACCESS_TOKEN')) : null,
        'square_location_id' => getenv('SQUARE_LOCATION_ID') ?: null,
        'square_sync_enabled' => getenv('SQUARE_SYNC_ENABLED') !== false ? getenv('SQUARE_SYNC_ENABLED') : null,
    ],
    'php_extensions' => [
        'curl' => extension_loaded('curl'),
        'openssl' => extension_loaded('openssl'),
        'fileinfo' => extension_loaded('fileinfo'),
        'pdo_sqlite' => extension_loaded('pdo_sqlite'),
    ],
    'square_config' => [
        'enabled' => $config['enabled'],
        'base_url' => $config['base_url'],
        'api_version' => $config['api_version'],
        'currency' => $config['currency'],
        'default_quantity' => $config['default_quantity'],
    ],
    'requested_sync' => [
        'run_sync' => $runSync,
        'sku' => $syncSku !== '' ? $syncSku : null,
    ],
];

try {
    $response['square_test'] = [
        'locations' => squareDebugJson($config, '/v2/locations'),
    ];

    if ($runSync) {
        if ($syncSku === '') {
            throw new RuntimeException('Missing sku query parameter for run_sync=1.');
        }
        $pdo = new PDO('sqlite:' . __DIR__ . '/data/intake.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        squareSyncEnsureSchema($pdo);
        $response['square_test']['sync_result'] = squareSyncItemBySku($pdo, $syncSku);
    }
} catch (Throwable $e) {
    $response['ok'] = false;
    $response['square_test_error'] = $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT);
