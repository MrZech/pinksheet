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

$result = snapshotDatabase(dirname(__DIR__) . '/data/intake.sqlite', $backupDir);

if ($result['ok']) {
    echo 'SQLite backup created: ' . $result['dest'] . PHP_EOL;
    echo 'SHA256 written: ' . $result['dest'] . '.sha256' . PHP_EOL;
    exit(0);
}

fwrite(STDERR, 'Backup failed: ' . ($result['error'] ?? 'unknown error') . PHP_EOL);
exit(1);
