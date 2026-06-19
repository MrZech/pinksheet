<?php
declare(strict_types=1);
/**
 * backup_databases.php  –  CLI script
 *
 * Creates safe online snapshots of intake.sqlite and archive.sqlite
 * using SQLite's VACUUM INTO command, which does not block readers
 * or writers on the source database.
 *
 * Backups are written to data/backups/ with a timestamp suffix.
 *
 * Usage:
 *   php scripts/backup_databases.php
 *   php scripts/backup_databases.php --dry-run   (report only)
 *   php scripts/backup_databases.php --out=path   (custom backup dir)
 */

$dryRun   = in_array('--dry-run', $argv ?? [], true);
$backupBase = null;
foreach ($argv ?? [] as $a) {
    if (str_starts_with($a, '--out=')) {
        $backupBase = substr($a, 6);
    }
}

$projectRoot = dirname(__DIR__);
$dbDir       = $projectRoot . '/data';
$backupDir   = $backupBase ?? ($dbDir . '/backups');
$timestamp   = gmdate('Y-m-d-His');
$databases   = [
    'intake.sqlite'   => $dbDir . '/intake.sqlite',
    'archive.sqlite'  => $dbDir . '/archive.sqlite',
];

/* ── Ensure backup directory exists ─────────────────────────── */
if (!$dryRun && !is_dir($backupDir)) {
    if (!mkdir($backupDir, 0777, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "FATAL: could not create backup directory: $backupDir\n");
        exit(1);
    }
}
if (!is_writable($backupDir)) {
    // Try to relax permissions
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
        echo "skip: $name does not exist, nothing to back up.\n";
        continue;
    }

    $dest = $backupDir . '/' . str_replace('.sqlite', ".$timestamp.sqlite", $name);
    echo ($dryRun ? 'would back up ' : 'backing up ') . "$name -> $dest\n";

    if ($dryRun) {
        $success++;
        continue;
    }

    /* ── VACUUM INTO (non-blocking, online safe) ──────────────── */
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

/* ── Clean up backups older than 30 days ────────────────────── */
$cutoff = time() - (30 * 86400);
$removed = 0;
foreach (glob($backupDir . '/*.sqlite') ?: [] as $old) {
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
echo "done: {$action}backup $success database(s), {$action}prune $removed old backup(s)";
if ($failed > 0) {
    echo ", $failed error(s)";
}
echo "\n";
exit($failed > 0 ? 1 : 0);
