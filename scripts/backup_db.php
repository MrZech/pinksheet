<?php
declare(strict_types=1);
/**
 * scripts/backup_db.php  –  SQLite Automated Backup
 *
 * Creates online-safe snapshots of intake.sqlite (and archive.sqlite if
 * present) using VACUUM INTO, which does not block readers or writers.
 *
 * Retention: backups older than 14 days are pruned automatically.
 *
 * Crontab (runs daily at 2:00 AM):
 *   0 2 * * * /usr/bin/php /path/to/project/scripts/backup_db.php >/dev/null 2>&1
 *
 * Usage:
 *   php scripts/backup_db.php                      # normal run
 *   php scripts/backup_db.php --dry-run             # report only, no writes
 *   php scripts/backup_db.php --out=/custom/path    # custom backup directory
 */

$dryRun     = in_array('--dry-run', $argv ?? [], true);
$backupBase = null;
foreach ($argv ?? [] as $a) {
    if (str_starts_with($a, '--out=')) {
        $backupBase = substr($a, 6);
    }
}

$projectRoot = dirname(__DIR__);
$dbDir       = $projectRoot . '/data';
$backupDir   = $backupBase ?? ($dbDir . '/backups');
$timestamp   = gmdate('Y-m-d');
$databases   = [
    'intake.sqlite'              => $dbDir . '/intake.sqlite',
    'archive.sqlite'             => $dbDir . '/archive.sqlite',
];

/* ── Ensure backup directory exists ──────────────────────────────── */
if (!$dryRun && !is_dir($backupDir)) {
    if (!mkdir($backupDir, 0777, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "FATAL: could not create backup directory: $backupDir\n");
        exit(1);
    }
}
if (!is_writable($backupDir)) {
    @chmod($backupDir, 0777);
    if (!is_writable($backupDir)) {
        fwrite(STDERR, "FATAL: backup directory not writable: $backupDir\n");
        exit(1);
    }
}

$success = 0;
$failed  = 0;

foreach ($databases as $name => $path) {
    if (!is_file($path)) {
        echo "skip: $name does not exist.\n";
        continue;
    }

    $dest = $backupDir . '/inventory_backup_' . $timestamp . '.sqlite';
    echo ($dryRun ? 'would back up ' : 'backing up ') . "$name -> $dest\n";

    if ($dryRun) {
        $success++;
        continue;
    }

    /* ── VACUUM INTO (online safe, non-blocking) ──────────────────── */
    try {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
        echo "  ok: " . number_format(filesize($dest) / 1024, 1) . " KB written\n";
        $success++;
    } catch (Throwable $e) {
        fwrite(STDERR, "  error: " . $e->getMessage() . "\n");
        $failed++;
    }
}

/* ── Prune backups older than 14 days ────────────────────────────── */
$cutoff = time() - (14 * 86400);
$removed = 0;
foreach (glob($backupDir . '/inventory_backup_*.sqlite') ?: [] as $old) {
    if (filemtime($old) < $cutoff) {
        if ($dryRun) {
            echo "would prune: $old\n";
        } else {
            @unlink($old);
        }
        $removed++;
    }
}

$action = $dryRun ? 'would ' : '';
echo "done: {$action}backed up $success database(s), {$action}pruned $removed old backup(s)";
if ($failed > 0) {
    echo ", $failed error(s)";
}
echo "\n";

exit($failed > 0 ? 1 : 0);
