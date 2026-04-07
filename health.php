<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$backupDir = __DIR__ . '/data/backups';
$latestBackup = null;
$latestBackupAgeHours = null;
$latestBackupSize = null;
$backupChecksumOk = null;

if (is_dir($backupDir)) {
    $latestFile = null;
    foreach (new DirectoryIterator($backupDir) as $fileInfo) {
        if ($fileInfo->isFile()) {
            if ($latestFile === null || $fileInfo->getMTime() > $latestFile->getMTime()) {
                $latestFile = $fileInfo;
            }
        }
    }
    if ($latestFile) {
        $latestBackup = $latestFile->getFilename();
        $latestBackupAgeHours = (time() - $latestFile->getMTime()) / 3600;
        $latestBackupSize = $latestFile->getSize();
        $hashFile = $latestFile->getPathname() . '.sha256';
        if (is_file($hashFile)) {
            $expected = trim((string)file_get_contents($hashFile));
            $actual = hash_file('sha256', $latestFile->getPathname());
            $backupChecksumOk = $actual === $expected;
        }
    }
}

http_response_code(MAINTENANCE_MODE ? 503 : 200);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'status' => MAINTENANCE_MODE ? 'maintenance' : 'ok',
    'maintenance' => MAINTENANCE_MODE,
    'backup' => [
        'latest' => $latestBackup,
        'age_hours' => $latestBackupAgeHours,
        'size_bytes' => $latestBackupSize,
        'checksum_ok' => $backupChecksumOk,
    ],
    'max_query_length' => MAX_QUERY_LENGTH,
    'max_status_length' => MAX_STATUS_LENGTH,
    'suggestion_limit' => SUGGESTION_LIMIT,
    'preview_limit' => PREVIEW_LIMIT,
], JSON_THROW_ON_ERROR);
