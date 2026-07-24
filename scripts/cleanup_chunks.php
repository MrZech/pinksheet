<?php
declare(strict_types=1);
/**
 * cleanup_chunks.php  –  CLI script
 *
 * Scans data/chunks/ for stale upload directories and removes any
 * subdirectory whose modification time is older than STALE_SECONDS
 * (default: 24 hours).  Designed to be run via cron / Task Scheduler
 * on a regular interval (e.g. once per hour).
 *
 * Usage:
 *   php scripts/cleanup_chunks.php
 *   php scripts/cleanup_chunks.php --dry-run     (report only, no deletion)
 *   php scripts/cleanup_chunks.php --max-age=3600 (override TTL in seconds)
 */

$dryRun   = in_array('--dry-run', $argv ?? [], true);
$maxAge   = 86400; // 24 hours

foreach ($argv ?? [] as $a) {
    if (str_starts_with($a, '--max-age=')) {
        $v = (int)substr($a, 10);
        if ($v > 0) $maxAge = $v;
    }
}

$chunksDir = __DIR__ . '/../data/chunks';
if (!is_dir($chunksDir)) {
    echo "info: $chunksDir does not exist, nothing to clean.\n";
    exit(0);
}

$now       = time();
$removed   = 0;
$failed    = 0;

$items = new DirectoryIterator($chunksDir);
foreach ($items as $item) {
    if (!$item->isDir() || $item->isDot()) {
        continue;
    }

    $dirPath = $item->getPathname();
    $mtime   = $item->getMTime();

    if (($now - $mtime) < $maxAge) {
        continue; // still fresh
    }

    echo ($dryRun ? 'would remove ' : 'removing ') . $dirPath . "\n";

    if ($dryRun) {
        $removed++;
        continue;
    }

    /* Recursively delete all files then the directory itself */
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dirPath, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $file) {
        if ($file->isDir()) {
            @rmdir($file->getPathname());
        } else {
            @unlink($file->getPathname());
        }
    }

    if (@rmdir($dirPath)) {
        $removed++;
    } else {
        echo "error: failed to remove $dirPath\n";
        $failed++;
    }
}

$action = $dryRun ? 'would remove' : 'removed';
echo "done: $action $removed stale chunk director" . ($removed === 1 ? 'y' : 'ies');
if ($failed > 0) {
    echo ", $failed failed";
}
echo "\n";
exit($failed > 0 ? 1 : 0);
