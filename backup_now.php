<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance(true);

header('Content-Type: application/json; charset=utf-8');

// Basic protection: only allow local requests.
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$isPrivate = false;
if ($remote !== '') {
    // Allow loopback and RFC1918/RFC4193 private ranges so local/LAN users can trigger backups.
    $isPrivate = $remote === '127.0.0.1'
        || $remote === '::1'
        || filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}
if (!$isPrivate) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$script = __DIR__ . '/scripts/backup.ps1';
if (!is_readable($script)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Backup script missing']);
    exit;
}

$cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File ' . escapeshellarg($script);
$output = [];
$exit = 0;
exec($cmd . ' 2>&1', $output, $exit);

echo json_encode([
    'ok' => $exit === 0,
    'exit' => $exit,
    'output' => $output,
]);
