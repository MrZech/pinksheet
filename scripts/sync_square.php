<?php
declare(strict_types=1);

// Sync one SKU or all active intake items to Square.
// Usage:
//   php scripts/sync_square.php SKU123
//   php scripts/sync_square.php --all

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../square_sync.php';

$arg = trim((string)($argv[1] ?? ''));
if ($arg === '') {
    fwrite(STDERR, "Usage: php scripts/sync_square.php <SKU|--all>\n");
    exit(2);
}

ensureStorageWritable();
$pdo = new PDO('sqlite:' . __DIR__ . '/../data/intake.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
squareSyncEnsureSchema($pdo);

if ($arg === '--all') {
    $skus = $pdo->query("SELECT sku_normalized FROM intake_items WHERE sku_normalized IS NOT NULL AND TRIM(sku_normalized) <> '' ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
} else {
    $skus = [strtoupper(trim($arg))];
}

$ok = true;
foreach ($skus as $sku) {
    $result = squareSyncItemBySku($pdo, (string)$sku);
    $status = (string)($result['status'] ?? 'unknown');
    echo '[' . strtoupper($status) . '] ' . $sku . ' - ' . (string)($result['message'] ?? '') . PHP_EOL;
    if ($status === 'error') {
        $ok = false;
    }
}

exit($ok ? 0 : 1);
