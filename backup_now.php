<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance(true);

header('Content-Type: application/json; charset=utf-8');

// Basic protection: only allow local requests.
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
$isPrivate = false;
if ($remote !== '') {
    // Allow loopback and RFC1918/RFC4193 private ranges so local/LAN users can trigger backups.
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

$script = __DIR__ . '/scripts/backup.ps1';

/**
 * Lightweight PHP fallback for environments without PowerShell (e.g., Linux host).
 */
function runPhpBackup(): array
{
    $repoRoot = __DIR__;
    $dbPath = $repoRoot . '/data/intake.sqlite';
    $backupDir = $repoRoot . '/data/backups';
    $logFile = $repoRoot . '/logs/lookup.csv';
    $logArchiveDir = $repoRoot . '/logs/archive';
    $oneDrive = getenv('USERPROFILE') ? getenv('USERPROFILE') . '/OneDrive/pinksheet-backups' : null;
    $photosMirror = getenv('BACKUP_PHOTOS_MIRROR') ?: null;
    $messages = [];
    $ok = true;

    foreach ([$backupDir, $logArchiveDir] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Could not create directory: ' . $dir];
        }
    }

    $timestamp = date('Ymd-His');
    if (is_file($dbPath)) {
        $dest = $backupDir . '/intake-' . $timestamp . '.sqlite';
        if (!copy($dbPath, $dest)) {
            return ['ok' => false, 'error' => 'Failed to copy DB to ' . $dest];
        }
        $messages[] = 'SQLite backup created: ' . $dest;
        // Write SHA256 checksum
        $hash = hash_file('sha256', $dest);
        if ($hash !== false) {
            file_put_contents($dest . '.sha256', $hash);
            $messages[] = 'SHA256 written: ' . $dest . '.sha256';
        } else {
            $messages[] = 'Failed to write checksum.';
            $ok = false;
        }
    } else {
        $messages[] = 'SQLite database not found at ' . $dbPath;
        $ok = false;
    }

    // Mirror to OneDrive if available.
    if ($oneDrive) {
        if (!is_dir($oneDrive) && !mkdir($oneDrive, 0777, true) && !is_dir($oneDrive)) {
            $messages[] = 'Failed to create OneDrive mirror at ' . $oneDrive;
            $ok = false;
        } else {
            $mirrorPath = $oneDrive . '/intake-' . $timestamp . '.sqlite';
            if (!copy($dest ?? '', $mirrorPath)) {
                $messages[] = 'Failed to mirror backup to OneDrive at ' . $mirrorPath;
                $ok = false;
            } else {
                $messages[] = 'Mirrored backup to OneDrive: ' . $mirrorPath;
                if (is_file(($dest ?? '') . '.sha256')) {
                    copy(($dest ?? '') . '.sha256', $mirrorPath . '.sha256');
                }
            }
        }
    }

    // Optional photo mirror (set BACKUP_PHOTOS_MIRROR=/path/to/mirror)
    if ($photosMirror) {
        $sourcePhotos = $repoRoot . '/data/sku_photos';
        if (!is_dir($photosMirror) && !mkdir($photosMirror, 0777, true) && !is_dir($photosMirror)) {
            $messages[] = 'Failed to create photo mirror at ' . $photosMirror;
            $ok = false;
        } else {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sourcePhotos, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $item) {
                $targetPath = $photosMirror . substr($item->getPathname(), strlen($sourcePhotos));
                if ($item->isDir()) {
                    if (!is_dir($targetPath) && !mkdir($targetPath, 0777, true) && !is_dir($targetPath)) {
                        $messages[] = 'Failed to create dir ' . $targetPath;
                        $ok = false;
                        break;
                    }
                } else {
                    if (!copy($item->getPathname(), $targetPath)) {
                        $messages[] = 'Failed to copy photo ' . $item->getPathname();
                        $ok = false;
                        break;
                    }
                }
            }
            if ($ok) {
                $messages[] = 'Mirrored photos to ' . $photosMirror;
            }
        }
    }

    if (is_file($logFile)) {
        $length = filesize($logFile);
        if ($length > 0) {
            $archiveDest = $logArchiveDir . '/lookup-' . $timestamp . '.csv';
            if (!copy($logFile, $archiveDest)) {
                $messages[] = 'Failed to archive lookup log.';
                $ok = false;
            } else {
                file_put_contents($logFile, '');
                $messages[] = 'Rotated lookup log to ' . $archiveDest;
            }
        } else {
            $messages[] = 'Lookup log exists but is empty; nothing to rotate.';
        }
    } else {
        $messages[] = 'Lookup log not found at ' . $logFile;
    }

    // Retention not applied in PHP fallback (default keeps all).
    return ['ok' => $ok, 'output' => $messages, 'fallback' => 'php'];
}

// Locate PowerShell (handles Windows and pwsh on Linux/macOS). If not found, fall back to PHP backup.
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
    ];
}

$psPath = null;
foreach ($psCandidates as $candidate) {
    $isAbsolute = str_starts_with($candidate, '/') || strpos($candidate, ':') !== false;
    if ($isAbsolute && is_executable($candidate)) {
        $psPath = $candidate;
        break;
    }
}

$usedFallback = false;
$output = [];
$exit = 0;
$cmd = null;

if ($psPath !== null && is_readable($script)) {
    $cmd = escapeshellarg($psPath) . ' -NoProfile -ExecutionPolicy Bypass -File ' . escapeshellarg($script);
    exec($cmd . ' 2>&1', $output, $exit);
    if ($exit === 127) {
        // PowerShell command not found at runtime; fall back to PHP.
        $fallback = runPhpBackup();
        http_response_code($fallback['ok'] ? 200 : 500);
        echo json_encode(array_merge($fallback, [
            'ps_used' => $psPath,
            'ps_exit' => $exit,
            'command' => $cmd,
        ]));
        exit;
    }
} else {
    $fallback = runPhpBackup();
    http_response_code($fallback['ok'] ? 200 : 500);
    echo json_encode(array_merge($fallback, [
        'ps_used' => null,
        'command' => $cmd,
        'ps_candidates' => $psCandidates,
    ]));
    exit;
}

echo json_encode([
    'ok' => $exit === 0,
    'exit' => $exit,
    'command' => $cmd,
    'ps_found' => $psPath,
    'ps_candidates' => $psCandidates,
    'script_exists' => is_readable($script),
    'output' => $output,
    'fallback' => $usedFallback ? 'php' : 'powershell',
]);
