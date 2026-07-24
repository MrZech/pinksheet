<?php
declare(strict_types=1);
/**
 * rotate_logs.php  –  CLI script
 *
 * Monitors the logs/ directory for oversized and stale files.
 *
 *   - Any log file exceeding MAX_SIZE (default 10 MB) is compressed
 *     into a .gz archive and truncated.
 *   - Any archived log older than MAX_AGE (default 30 days) is deleted.
 *
 * Designed to be run via cron / Task Scheduler hourly.
 *
 * Usage:
 *   php scripts/rotate_logs.php
 *   php scripts/rotate_logs.php --dry-run     (report only)
 *   php scripts/rotate_logs.php --max-size=5  (override MB threshold)
 *   php scripts/rotate_logs.php --max-age=60  (override retention days)
 */

$dryRun  = in_array('--dry-run', $argv ?? [], true);
$maxSize = 10;     // MB
$maxAge  = 30;     // days

foreach ($argv ?? [] as $a) {
    if (str_starts_with($a, '--max-size=')) {
        $v = (float)substr($a, 11);
        if ($v > 0) $maxSize = $v;
    }
    if (str_starts_with($a, '--max-age=')) {
        $v = (int)substr($a, 10);
        if ($v > 0) $maxAge = $v;
    }
}

$logDir    = __DIR__ . '/../logs';
$maxBytes  = $maxSize * 1024 * 1024;
$cutoff    = time() - ($maxAge * 86400);
$compressed = 0;
$deleted    = 0;
$errors     = 0;

if (!is_dir($logDir)) {
    echo "info: $logDir does not exist, nothing to rotate.\n";
    exit(0);
}

$items = new DirectoryIterator($logDir);
foreach ($items as $item) {
    if ($item->isDot() || !$item->isFile()) {
        continue;
    }

    $path  = $item->getPathname();
    $name  = $item->getFilename();
    $size  = $item->getSize();
    $mtime = $item->getMTime();

    /* ── Delete stale .gz archives ─────────────────────────── */
    if (str_ends_with($name, '.gz')) {
        if ($mtime < $cutoff) {
            echo ($dryRun ? 'would delete ' : 'deleting ') . $path . "\n";
            if (!$dryRun) {
                if (@unlink($path)) {
                    $deleted++;
                } else {
                    fwrite(STDERR, "error: could not delete $path\n");
                    $errors++;
                }
            } else {
                $deleted++;
            }
        }
        continue;
    }

    /* ── Compress oversized logs (.log, .txt, or no ext) ───── */
    if ($size > $maxBytes) {
        $gzPath = $path . '.' . gmdate('Ymd-His', $mtime) . '.gz';
        echo ($dryRun ? 'would compress ' : 'compressing ') . $path . ' -> ' . $gzPath . "\n";

        if (!$dryRun) {
            $data = @file_get_contents($path);
            if ($data === false) {
                fwrite(STDERR, "error: could not read $path\n");
                $errors++;
                continue;
            }
            $gzData = gzencode($data, 9);
            if ($gzData === false) {
                fwrite(STDERR, "error: gzencode failed for $path\n");
                $errors++;
                continue;
            }
            if (file_put_contents($gzPath, $gzData) === false) {
                fwrite(STDERR, "error: could not write $gzPath\n");
                $errors++;
                continue;
            }
            // Truncate original to reclaim space immediately
            $fh = fopen($path, 'wb');
            if ($fh) {
                ftruncate($fh, 0);
                fclose($fh);
            }
            $compressed++;
        } else {
            $compressed++;
        }
    }
}

$action = $dryRun ? 'would ' : '';

echo "done: {$action}compress $compressed file(s), {$action}delete $deleted archive(s)";
if ($errors > 0) {
    echo ", $errors error(s)";
}
echo "\n";
exit($errors > 0 ? 1 : 0);
