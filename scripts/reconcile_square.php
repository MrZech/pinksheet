<?php
declare(strict_types=1);

// Run Square inventory reconciliation.
// Usage:
//   php scripts/reconcile_square.php                          Full reconciliation
//   php scripts/reconcile_square.php --dry-run                Dry-run (no repairs)
//   php scripts/reconcile_square.php --no-catalog             Local checks only

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../square_sync.php';
require_once __DIR__ . '/../lib/reconciliation/scheduler.php';

$dryRun = in_array('--dry-run', $argv, true);
$noCatalog = in_array('--no-catalog', $argv, true);
$help = in_array('--help', $argv, true) || in_array('-h', $argv, true);

if ($help) {
    echo "Usage:" . PHP_EOL;
    echo "  php scripts/reconcile_square.php                      Full reconciliation (detect + repair)" . PHP_EOL;
    echo "  php scripts/reconcile_square.php --dry-run            Dry run — detect only, no repairs" . PHP_EOL;
    echo "  php scripts/reconcile_square.php --no-catalog         Skip Square catalog fetch, local checks only" . PHP_EOL;
    exit(0);
}

ensureStorageWritable();
$pdo = pdoConnect(__DIR__ . '/../data/intake.sqlite');
squareSyncEnsureSchema($pdo);

echo "Starting reconciliation..." . PHP_EOL;
if ($dryRun) echo "[DRY RUN — no repairs will be executed]" . PHP_EOL;
if ($noCatalog) echo "[Local checks only — skipping Square catalog fetch]" . PHP_EOL;

$start = microtime(true);
$result = reconRun($pdo, 'scheduled', $dryRun, !$noCatalog);
$elapsed = microtime(true) - $start;

echo PHP_EOL;
echo "=== Reconciliation Results ===" . PHP_EOL;
echo "Status: " . $result['status'] . PHP_EOL;
echo "Devices checked: " . $result['checked'] . PHP_EOL;
echo "Issues detected: " . $result['detected'] . PHP_EOL;
echo "  Auto-repaired: " . $result['repaired'] . PHP_EOL;
echo "  Failed: " . $result['failed'] . PHP_EOL;
echo "  Manual review: " . $result['manual'] . PHP_EOL;
echo "Runtime: " . round($elapsed, 2) . "s" . PHP_EOL;

if ($result['status'] === 'failed') {
    echo "ERROR: " . ($result['error'] ?? 'Unknown error') . PHP_EOL;
    exit(1);
}

exit(0);
