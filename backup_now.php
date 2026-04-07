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

// Locate PowerShell (handles Windows and pwsh on Linux/macOS). Exit 127 typically means the shell couldn't find it.
$psCandidates = [];
if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
    $systemRoot = getenv('SystemRoot') ?: 'C:\\Windows';
    $psCandidates = [
        $systemRoot . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe',
        'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe',
        'powershell.exe',
        'powershell',
    ];
} else {
    $psCandidates = [
        '/usr/bin/pwsh',
        '/usr/local/bin/pwsh',
        '/opt/microsoft/powershell/7/pwsh',
        'pwsh',
        'powershell',
    ];
}

$psPath = null;
foreach ($psCandidates as $candidate) {
    // is_executable works for absolute paths; for bare commands we rely on shell PATH, so accept them last.
    $isAbsolute = strpos($candidate, ':') !== false || str_starts_with($candidate, '/');
    if ($isAbsolute && is_executable($candidate)) {
        $psPath = $candidate;
        break;
    }
    if (!$isAbsolute) {
        // keep as a fallback; we'll use the first bare command if no absolute path found
        $psPath = $psPath ?? $candidate;
    }
}

if ($psPath === null) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'PowerShell not found on server', 'candidates' => $psCandidates]);
    exit;
}

$cmd = escapeshellarg($psPath) . ' -NoProfile -ExecutionPolicy Bypass -File ' . escapeshellarg($script);
$output = [];
$exit = 0;
exec($cmd . ' 2>&1', $output, $exit);

echo json_encode([
    'ok' => $exit === 0,
    'exit' => $exit,
    'command' => $cmd,
    'ps_found' => $psPath,
    'ps_candidates' => $psCandidates,
    'script_exists' => is_readable($script),
    'output' => $output,
]);
