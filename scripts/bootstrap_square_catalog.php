<?php
declare(strict_types=1);

/**
 * Bootstrap the Square catalog by pushing every Pinksheet item to Square.
 *
 * Usage:
 *   php scripts/bootstrap_square_catalog.php                     # Push all items
 *   php scripts/bootstrap_square_catalog.php --dry-run           # Count only, no API calls
 *   php scripts/bootstrap_square_catalog.php --limit=50          # Push first 50 items
 *   php scripts/bootstrap_square_catalog.php --sku=DT-1001       # Push a single SKU
 *   php scripts/bootstrap_square_catalog.php --force             # Re-push even if already synced
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../square_sync.php';
require_once __DIR__ . '/../square_sync_queue.php';
require_once __DIR__ . '/../square_webhook_service.php';

$dryRun = in_array('--dry-run', $argv, true);
$force = in_array('--force', $argv, true);
$help = in_array('--help', $argv, true) || in_array('-h', $argv, true);
$singleSku = null;
$limit = 0;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i] ?? '';
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(0, (int)substr($arg, 7));
    } elseif (str_starts_with($arg, '--sku=')) {
        $singleSku = strtoupper(trim(substr($arg, 6)));
    }
}

if ($help) {
    echo "Usage:\n";
    echo "  php scripts/bootstrap_square_catalog.php                   Push all items\n";
    echo "  php scripts/bootstrap_square_catalog.php --dry-run         Count only, no API calls\n";
    echo "  php scripts/bootstrap_square_catalog.php --limit=50        Push first 50 items\n";
    echo "  php scripts/bootstrap_square_catalog.php --sku=DT-1001     Push a single SKU\n";
    echo "  php scripts/bootstrap_square_catalog.php --force           Re-push even if already synced\n";
    exit(0);
}

ensureStorageWritable();
$pdo = pdoConnect(__DIR__ . '/../data/intake.sqlite');
squareSyncEnsureSchema($pdo);
squareWebhookEnsureSchema($pdo);

$config = squareSyncConfig();
if (!$config['enabled']) {
    echo "ERROR: Square sync is not configured. Set SQUARE_ACCESS_TOKEN and SQUARE_LOCATION_ID in .env\n";
    exit(1);
}

if ($singleSku !== null) {
    $items = $pdo->prepare("SELECT * FROM intake_items WHERE sku_normalized = :sku ORDER BY id DESC LIMIT 1");
    $items->execute(['sku' => $singleSku]);
    $rows = array_filter([$items->fetch(PDO::FETCH_ASSOC)]);
} else {
    $sql = "SELECT * FROM intake_items WHERE sku_normalized IS NOT NULL AND TRIM(sku_normalized) <> ''";
    if (!$force) {
        $sql .= " AND sku_normalized NOT IN (SELECT sku_normalized FROM square_catalog_sync WHERE square_item_id IS NOT NULL AND last_error IS NULL)";
    }
    $sql .= " ORDER BY sku_normalized ASC";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

if ($limit > 0) {
    $rows = array_slice($rows, 0, $limit);
}

$total = count($rows);
echo "Found $total items to sync.\n";

if ($dryRun) {
    echo "Dry run — no API calls will be made.\n";
    exit(0);
}

$ok = 0;
$fail = 0;
$skip = 0;

foreach ($rows as $item) {
    $sku = strtoupper(trim((string)($item['sku_normalized'] ?? $item['sku'] ?? '')));
    if ($sku === '') {
        $skip++;
        continue;
    }

    echo "[$sku] ";
    $result = squareSyncItemBySku($pdo, $sku);

    if ($result['status'] === 'ok') {
        echo "OK — " . ($result['message'] ?? '') . "\n";
        $ok++;
    } elseif ($result['status'] === 'skipped' || $result['status'] === 'disabled') {
        echo "SKIP — " . ($result['message'] ?? '') . "\n";
        $skip++;
    } else {
        echo "FAIL — " . ($result['message'] ?? '') . "\n";
        $fail++;
    }
}

echo "\n=== Bootstrap Complete ===\n";
echo "Total: $total | OK: $ok | Skipped: $skip | Failed: $fail\n";

exit($fail > 0 ? 1 : 0);
