<?php
declare(strict_types=1);

/**
 * Shared online-safe SQLite backup helpers.
 *
 * Uses SQLite's VACUUM INTO, which produces a consistent snapshot of the
 * live database (including any uncheckpointed WAL data) without blocking
 * readers or writers.  A plain file copy() of a WAL-mode database can miss
 * recent writes or produce a corrupt snapshot — never do that for live DBs.
 */

/**
 * Create a timestamped VACUUM INTO snapshot plus a SHA-256 checksum file.
 *
 * @param  string $srcPath  Absolute path to the live database.
 * @param  string $destDir  Backup directory (created if missing).
 * @param  string $prefix   Filename prefix, default "intake".
 * @return array            ['ok' => bool, 'dest' => ?string, 'error' => ?string]
 */
function snapshotDatabase(string $srcPath, string $destDir, string $prefix = 'intake'): array
{
    if (!is_file($srcPath)) {
        return ['ok' => false, 'dest' => null, 'error' => 'Database not found: ' . $srcPath];
    }
    if (!is_dir($destDir) && !@mkdir($destDir, 0777, true) && !is_dir($destDir)) {
        return ['ok' => false, 'dest' => null, 'error' => 'Could not create backup directory: ' . $destDir];
    }
    if (!is_writable($destDir)) {
        return ['ok' => false, 'dest' => null, 'error' => 'Backup directory is not writable: ' . $destDir];
    }

    $dest = rtrim($destDir, '/\\') . '/' . $prefix . '-' . date('Ymd-His') . '.sqlite';

    try {
        $pdo = new PDO('sqlite:' . $srcPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    } catch (Throwable $e) {
        return ['ok' => false, 'dest' => null, 'error' => 'VACUUM INTO failed: ' . $e->getMessage()];
    }

    if (!is_file($dest)) {
        return ['ok' => false, 'dest' => null, 'error' => 'Snapshot file was not created.'];
    }

    $hash = @hash_file('sha256', $dest);
    if ($hash === false) {
        return ['ok' => false, 'dest' => $dest, 'error' => 'Snapshot created but checksum could not be written.'];
    }
    @file_put_contents($dest . '.sha256', $hash);

    return ['ok' => true, 'dest' => $dest, 'error' => null];
}
