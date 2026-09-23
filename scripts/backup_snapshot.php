<?php
declare(strict_types=1);

/**
 * scripts/backup_snapshot.php — online-safe SQLite snapshot (VACUUM INTO).
 *
 * Creates a consistent timestamped snapshot of data/intake.sqlite plus a
 * SHA-256 checksum, without blocking readers or writers.  Used by
 * scripts/backup.ps1, the backup_now.php PHP fallback, and scheduled tasks.
 *
 * Usage:
 *   php scripts/backup_snapshot.php                  # → data/backups/
 *   php scripts/backup_snapshot.php --out=/path/to   # custom directory
 */

require_once __DIR__ . '/../lib/backup.php';

$backupDir = dirname(__DIR__) . '/data/backups';
foreach ($argv ?? [] as $a) {
    if (str_starts_with($a, '--out=')) {
        $backupDir = substr($a, 6);
    }
}

// snapshotDatabase() already accepts a $prefix so multiple databases can be
// backed up side by side without colliding on filename — but this script
// only ever called it for intake.sqlite. data/archive.sqlite (sold/historical
// items) has real data and its own prefix was never actually used, so it has
// never been backed up by this path (the one scripts/backup.ps1, backup_now.php,
// and the scheduled task all call). Only back it up when the file exists, so
// installs without an archive DB yet don't fail.
$databases = [
    'intake' => dirname(__DIR__) . '/data/intake.sqlite',
    'archive' => dirname(__DIR__) . '/data/archive.sqlite',
];

$exitCode = 0;
foreach ($databases as $prefix => $srcPath) {
    if (!is_file($srcPath)) {
        continue;
    }
    $result = snapshotDatabase($srcPath, $backupDir, $prefix);
    if ($result['ok']) {
        echo 'SQLite backup created: ' . $result['dest'] . PHP_EOL;
        echo 'SHA256 written: ' . $result['dest'] . '.sha256' . PHP_EOL;
    } else {
        fwrite(STDERR, 'Backup failed (' . $prefix . '): ' . ($result['error'] ?? 'unknown error') . PHP_EOL);
        $exitCode = 1;
    }
}

exit($exitCode);
