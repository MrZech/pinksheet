<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance(true);

header('Content-Type: application/json; charset=utf-8');

// Basic protection: only allow local/private network.
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$isPrivate = false;
if ($remote !== '') {
    $isPrivate = $remote === '127.0.0.1'
        || $remote === '::1'
        || filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}
if (!$isPrivate) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$repoRoot = __DIR__;
$dbPath = $repoRoot . '/data/intake.sqlite';
$backupDir = $repoRoot . '/data/backups';

$latestBackup = null;
$latestBackupPath = null;
if (is_dir($backupDir)) {
    $latestMtime = 0;
    foreach (new DirectoryIterator($backupDir) as $fileInfo) {
        if ($fileInfo->isFile() && $fileInfo->getMTime() > $latestMtime) {
            $latestMtime = $fileInfo->getMTime();
            $latestBackup = $fileInfo->getFilename();
            $latestBackupPath = $fileInfo->getPathname();
        }
    }
}

function verifyChecksum(string $path): bool
{
    $hashFile = $path . '.sha256';
    if (!is_file($hashFile)) {
        return true; // no checksum; treat as pass
    }
    $expected = trim((string)file_get_contents($hashFile));
    $actual = hash_file('sha256', $path);
    return $expected !== '' && $actual === $expected;
}

function checkDb(string $path): bool
{
    $checker = __DIR__ . '/scripts/check_db.php';
    if (!is_file($checker)) {
        return false;
    }
    $cmd = PHP_BINARY . ' -d detect_unicode=0 -f ' . escapeshellarg($checker) . ' ' . escapeshellarg($path) . ' inline';
    exec($cmd, $output, $exit);
    return $exit === 0;
}

$ok = true;
$messages = [];

if (!is_file($dbPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Primary DB missing']);
    exit;
}

if (!checkDb($dbPath)) {
    $ok = false;
    $messages[] = 'Primary DB integrity failed';
}

if ($latestBackupPath) {
    if (!verifyChecksum($latestBackupPath)) {
        $ok = false;
        $messages[] = 'Latest backup checksum mismatch';
    }
    if (!checkDb($latestBackupPath)) {
        $ok = false;
        $messages[] = 'Latest backup integrity failed';
    }
} else {
    $messages[] = 'No backup found';
}

http_response_code($ok ? 200 : 500);
echo json_encode([
    'ok' => $ok,
    'latest_backup' => $latestBackup,
    'messages' => $messages,
]);
