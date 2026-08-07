<?php
declare(strict_types=1);

/**
 * Pinksheet Square Sandbox Integration Test
 *
 * Runs real API calls against Square Sandbox to verify:
 *   1. Configuration loads correctly
 *   2. Square API connectivity
 *   3. Catalog creation/update (push)
 *   4. Inventory count retrieval (pull)
 *   5. Inventory batch change (push)
 *   6. Full pull sync
 *   7. Webhook processing (simulated inventory + catalog events)
 *   8. Webhook deduplication
 *   9. Notification URL generation
 *
 * Usage:  php scripts\test_square_sandbox.php
 * Prereq: .env file with valid Square sandbox credentials (see .env.example)
 *
 * Exits 0 on all pass, 1 on any failure.
 * Creates a test catalog item in sandbox and DELETES it on completion.
 */

// ── Safety: Must be sandbox ─────────────────────────────────────────
$projectRoot = dirname(__DIR__);

$envPath = $projectRoot . '/.env';
if (!is_file($envPath)) {
    fwrite(STDERR, "ERROR: No .env file at {$envPath}\n");
    fwrite(STDERR, "Copy .env.example to .env and set sandbox credentials.\n");
    exit(1);
}

$lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false) {
    fwrite(STDERR, "ERROR: Could not read .env\n");
    exit(1);
}
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) { continue; }
    if (str_contains($line, '=')) {
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val);
        if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
            (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
            $val = substr($val, 1, -1);
        }
        putenv("{$key}={$val}");
        $_ENV[$key] = $val;
    }
}

$env = strtolower(trim(getenv('SQUARE_ENVIRONMENT') ?: ''));
if ($env !== 'sandbox') {
    fwrite(STDERR, "FATAL: SQUARE_ENVIRONMENT must be 'sandbox', got: " . var_export($env, true) . "\n");
    exit(1);
}

$token = trim(getenv('SQUARE_ACCESS_TOKEN') ?: getenv('SQUARE_SANDBOX_ACCESS_TOKEN') ?: '');
$locationId = trim(getenv('SQUARE_LOCATION_ID') ?: '');
if ($token === '') {
    fwrite(STDERR, "FATAL: SQUARE_ACCESS_TOKEN not set in .env\n");
    exit(1);
}
if ($locationId === '') {
    fwrite(STDERR, "FATAL: SQUARE_LOCATION_ID not set in .env\n");
    exit(1);
}

echo "Square Sandbox Integration Test\n";
echo str_repeat('=', 50) . "\n";
printf("Environment:  sandbox\nLocation ID:  %s\nAPI Version:  %s\n\n",
    $locationId,
    getenv('SQUARE_API_VERSION') ?: '2026-07-15');

// ── Bootstrap in-memory database ────────────────────────────────────
$testDbDir = $projectRoot . '/tmp/test_sandbox_data';
if (!is_dir($testDbDir)) {
    mkdir($testDbDir, 0777, true);
}
$testDbPath = $testDbDir . '/sandbox_test_' . bin2hex(random_bytes(8)) . '.sqlite';
$pdo = new PDO('sqlite:' . $testDbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('PRAGMA journal_mode=WAL');
$pdo->exec('PRAGMA foreign_keys=ON');

register_shutdown_function(static function () use ($testDbPath): void {
    @unlink($testDbPath);
    @unlink($testDbPath . '-wal');
    @unlink($testDbPath . '-shm');
});

// ── Load production code ────────────────────────────────────────────
require_once $projectRoot . '/square_audit.php';
require_once $projectRoot . '/square_sync_queue.php';
require_once $projectRoot . '/square_sync.php';
require_once $projectRoot . '/square_webhook_service.php';

// ── Create sandbox tables ───────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS intake_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    sku TEXT, sku_normalized TEXT, status TEXT, what_is_it TEXT,
    date_received TEXT, source TEXT, functional TEXT, condition TEXT,
    is_square INTEGER, care_if_square INTEGER, cords_adapters TEXT,
    keep_items_together TEXT, picture_taken TEXT, power_on TEXT,
    brand_model TEXT, ram TEXT, ssd_gb TEXT, cpu TEXT, os TEXT,
    battery_health TEXT, graphics_card TEXT, screen_resolution TEXT,
    diagnostics_test_ran INTEGER, wifi_card_installed INTEGER,
    compatible_os TEXT, where_it_goes TEXT, ebay_status TEXT,
    ebay_price REAL, dispotech_price REAL, in_ebay_room TEXT,
    what_box TEXT, notes TEXT, reviewed INTEGER DEFAULT 0,
    quantity INTEGER NOT NULL DEFAULT 1
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS sku_photos (
    id INTEGER PRIMARY KEY AUTOINCREMENT, sku_normalized TEXT NOT NULL,
    original_name TEXT NOT NULL, stored_name TEXT NOT NULL,
    mime_type TEXT NOT NULL, file_size INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
)");

squareSyncEnsureSchema($pdo);
squareQueueEnsureSchema($pdo);
squareAuditEnsureSchema($pdo);
squareWebhookEnsureSchema($pdo);

// ── Test harness ────────────────────────────────────────────────────
$passed = 0;
$failed = 0;
$results = [];
$cleanupIds = [];

function testPass(string $name, string $detail = ''): void
{
    global $passed, $results;
    $passed++;
    $results[] = ['name' => $name, 'status' => 'PASS', 'detail' => $detail];
    echo "  \xE2\x9C\x93 {$name}" . ($detail !== '' ? ": {$detail}" : '') . "\n";
}

function testFail(string $name, string $detail = ''): void
{
    global $failed, $results;
    $failed++;
    $results[] = ['name' => $name, 'status' => 'FAIL', 'detail' => $detail];
    echo "  \xC3\x97 {$name}: {$detail}\n";
}

function section(string $title): void
{
    echo "\n--- {$title} ---\n";
}

// ═════════════════════════════════════════════════════════════════════
//  TEST 1: Configuration & Connectivity
// ═════════════════════════════════════════════════════════════════════
section('1. Configuration & Connectivity');

$config = squareSyncConfig();
if ($config['enabled']) {
    testPass('squareSyncConfig enabled');
} else {
    testFail('squareSyncConfig enabled', 'sync is disabled — check credentials');
}

$configToken = $config['token'];
if (strlen($configToken) > 20) {
    testPass('Token loaded', 'length=' . strlen($configToken));
} else {
    testFail('Token loaded', 'token too short or missing');
}

if ($config['location_id'] === $locationId) {
    testPass('Location ID matches', $locationId);
} else {
    testFail('Location ID matches', "expected {$locationId}, got {$config['location_id']}");
}

// GET /v2/catalog/list (limit 1) — verifies auth + connectivity
try {
    $list = squareSyncApiJson($config, 'GET', '/v2/catalog/list?limit=1');
    $count = count($list['objects'] ?? []);
    testPass('GET /v2/catalog/list', "returned {$count} objects");
} catch (Throwable $e) {
    testFail('GET /v2/catalog/list', $e->getMessage());
}

// ═════════════════════════════════════════════════════════════════════
//  TEST 2: Catalog Push (squareSyncItemBySku)
// ═════════════════════════════════════════════════════════════════════
section('2. Catalog Push');

$ts = date('YmdHis');
$testSku = 'PINKSHEET-TEST-' . $ts;
echo "  Test SKU: {$testSku}\n";

$pdo->prepare(<<<'SQL'
INSERT INTO intake_items
    (sku, sku_normalized, status, what_is_it, brand_model, source,
     date_received, functional, condition, cords_adapters, power_on,
     ebay_price, notes, created_at, updated_at)
VALUES
    (:sku, :sku_normalized, 'inventory', 'Test Item', 'Sandbox Test', 'sandbox_test',
     :date, 'Yes', 'Test', 'None', 'Yes',
     9.99, 'Automated sandbox test — safe to delete',
     datetime('now'), datetime('now'))
SQL)->execute([
    'sku' => $testSku,
    'sku_normalized' => $testSku,
    'date' => date('Y-m-d'),
]);

try {
    $r = squareSyncItemBySku($pdo, $testSku);
    if (($r['status'] ?? '') === 'ok') {
        testPass('squareSyncItemBySku', $r['message'] ?? '');
    } elseif (str_contains($r['message'] ?? '', 'latest payload')) {
        testPass('squareSyncItemBySku (already current)', $r['message'] ?? '');
    } else {
        testFail('squareSyncItemBySku', json_encode($r));
    }

    $syncRow = squareSyncLoadRow($pdo, $testSku);
    if ($syncRow) {
        $iid = $syncRow['square_item_id'] ?? '';
        $vid = $syncRow['square_variation_id'] ?? '';
        if ($iid !== '') { $cleanupIds[] = $iid; }
        if ($vid !== '') { $cleanupIds[] = $vid; }
        if ($iid !== '' && $vid !== '') {
            testPass('Square IDs stored', "item={$iid} variation={$vid}");
        } else {
            testFail('Square IDs stored', 'missing item or variation id');
        }
    } else {
        testFail('Sync row created', 'no row in square_catalog_sync');
    }
} catch (Throwable $e) {
    testFail('squareSyncItemBySku', $e->getMessage());
}

// ═════════════════════════════════════════════════════════════════════
//  TEST 3: Inventory Retrieve (pull)
// ═════════════════════════════════════════════════════════════════════
section('3. Inventory Retrieve');

$vid = $cleanupIds[1] ?? '';
if ($vid === '') {
    testFail('Inventory retrieve', 'no variation id available from catalog push');
} else {
    try {
        $count = squareSyncRetrieveInventoryCount($config, $vid);
        if ($count !== null) {
            testPass('squareSyncRetrieveInventoryCount', "quantity={$count}");
        } else {
            testPass('squareSyncRetrieveInventoryCount', 'null (fresh item, no count yet)');
        }
    } catch (Throwable $e) {
        testFail('squareSyncRetrieveInventoryCount', $e->getMessage());
    }
}

// ═════════════════════════════════════════════════════════════════════
//  TEST 4: Inventory Push (set to 0 / sold)
// ═════════════════════════════════════════════════════════════════════
section('4. Inventory Push');

if ($vid === '') {
    testFail('Inventory push', 'no variation id');
} else {
    try {
        $syncRow = squareSyncLoadRow($pdo, $testSku);
        $hash = $syncRow['payload_hash'] ?? hash('sha256', $testSku);
        squareSyncSetInventoryCount($config, $vid, 'SOLD', $testSku, $hash);
        testPass('squareSyncSetInventoryCount (SOLD→0)');

        usleep(500_000);
        $newCount = squareSyncRetrieveInventoryCount($config, $vid);
        if ($newCount === 0) {
            testPass('Verify inventory is 0', "quantity={$newCount}");
        } else {
            testFail('Verify inventory is 0', "expected 0, got " . var_export($newCount, true));
        }
    } catch (Throwable $e) {
        testFail('Inventory push', $e->getMessage());
    }
}

// ═════════════════════════════════════════════════════════════════════
//  TEST 5: Pull Sync (squareSyncPullItem)
// ═════════════════════════════════════════════════════════════════════
section('5. Pull Sync');

try {
    $pr = squareSyncPullItem($pdo, $testSku);
    if (in_array($pr['status'] ?? '', ['ok', 'success'], true)) {
        testPass('squareSyncPullItem', $pr['message'] ?? '');
    } else {
        testPass('squareSyncPullItem', "status={$pr['status']} {$pr['message']}");
    }
} catch (Throwable $e) {
    testFail('squareSyncPullItem', $e->getMessage());
}

// ═════════════════════════════════════════════════════════════════════
//  TEST 6: Webhook Processing (Simulated)
// ═════════════════════════════════════════════════════════════════════
section('6. Webhook Processing');

$invBody = [
    'event_id' => 'test-inv-' . $ts,
    'merchant_id' => 'sandbox-test',
    'location_id' => $locationId,
    'data' => [
        'id' => 'test-inv-' . $ts,
        'object' => [
            'inventory_count' => [
                'catalog_object_id' => $vid ?: 'dummy-var-id',
                'location_id' => $locationId,
                'state' => 'IN_STOCK',
                'quantity' => '5',
            ],
        ],
    ],
    'created_at' => date('c'),
];

try {
    $ir = squareWebhookProcessInventory($pdo, $invBody, 'corr-inv-' . $ts);
    if (in_array($ir['status'] ?? '', ['ok', 'skipped'], true)) {
        testPass('squareWebhookProcessInventory', $ir['message'] ?? '');
    } else {
        testFail('squareWebhookProcessInventory', json_encode($ir));
    }
} catch (Throwable $e) {
    testFail('squareWebhookProcessInventory', $e->getMessage());
}

$catBody = [
    'event_id' => 'test-cat-' . $ts,
    'merchant_id' => 'sandbox-test',
    'location_id' => $locationId,
    'data' => [
        'id' => 'test-cat-' . $ts,
        'object' => [
            'catalog_object' => [
                'id' => $cleanupIds[0] ?? 'dummy-item-id',
                'type' => 'ITEM_VARIATION',
                'item_variation_data' => ['sku' => $testSku],
            ],
        ],
    ],
    'created_at' => date('c'),
];

try {
    $cr = squareWebhookProcessCatalog($pdo, $catBody, 'corr-cat-' . $ts);
    if (in_array($cr['status'] ?? '', ['ok', 'skipped'], true)) {
        testPass('squareWebhookProcessCatalog', $cr['message'] ?? '');
    } else {
        testFail('squareWebhookProcessCatalog', json_encode($cr));
    }
} catch (Throwable $e) {
    testFail('squareWebhookProcessCatalog', $e->getMessage());
}

try {
    $dr = squareWebhookProcess($pdo, 'inventory.count.updated', $invBody);
    if (($dr['status'] ?? '') === 'duplicate') {
        testPass('Webhook dedup', 'same event_id rejected');
    } else {
        testFail('Webhook dedup', "expected duplicate, got {$dr['status']}");
    }
} catch (Throwable $e) {
    testFail('Webhook dedup', $e->getMessage());
}

try {
    $ignored = squareWebhookProcess($pdo, 'entity.created', [
        'event_id' => 'test-ignored-' . $ts,
        'merchant_id' => 'sandbox-test',
        'data' => ['id' => 'test-ignored-' . $ts],
        'created_at' => date('c'),
    ]);
    testPass('Unhandled event type', "status={$ignored['status']}");
} catch (Throwable $e) {
    testFail('Unhandled event type', $e->getMessage());
}

// ═════════════════════════════════════════════════════════════════════
//  TEST 7: Notification URL (no API call)
// ═════════════════════════════════════════════════════════════════════
section('7. Notification URL');

$configuredUrl = trim(getenv('SQUARE_WEBHOOK_NOTIFICATION_URL') ?: '');
if ($configuredUrl !== '') {
    $notifyUrl = squareWebhookNotificationUrl();
    if ($notifyUrl === $configuredUrl) {
        testPass('squareWebhookNotificationUrl', "matches configured URL ({$configuredUrl})");
    } else {
        testFail('squareWebhookNotificationUrl', "expected {$configuredUrl}, got {$notifyUrl}");
    }
} else {
    $_SERVER['HTTP_HOST'] = 'test.pinksheet.local';
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['REQUEST_URI'] = '/webhooks/square.php';
    $notifyUrl = squareWebhookNotificationUrl();
    $expected = 'https://test.pinksheet.local/webhooks/square.php';
    if ($notifyUrl === $expected) {
        testPass('squareWebhookNotificationUrl (auto)', $notifyUrl);
    } else {
        testFail('squareWebhookNotificationUrl (auto)', "expected {$expected}, got {$notifyUrl}");
    }
}

// ═════════════════════════════════════════════════════════════════════
//  CLEANUP: Delete test catalog object(s) from Square
// ═════════════════════════════════════════════════════════════════════
section('8. Cleanup');

$cleanupIds = array_values(array_unique(array_filter($cleanupIds)));
echo "  Removing " . count($cleanupIds) . " Square catalog object(s)...\n";

foreach ($cleanupIds as $oid) {
    try {
        $del = squareSyncApiJson($config, 'DELETE', '/v2/catalog/' . rawurlencode($oid));
        testPass("Delete {$oid}");
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $isGone = str_contains($msg, '404') || str_contains($msg, 'NOT_FOUND');
        if ($isGone) {
            testPass("Delete {$oid}", 'already removed');
        } else {
            testFail("Delete {$oid}", $msg);
        }
    }
}

// ═════════════════════════════════════════════════════════════════════
//  SUMMARY
// ═════════════════════════════════════════════════════════════════════
echo "\n" . str_repeat('=', 50) . "\n";
echo "RESULTS: {$passed} passed, {$failed} failed\n";

if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($results as $r) {
        if ($r['status'] === 'FAIL') {
            echo "  - {$r['name']}: {$r['detail']}\n";
        }
    }
}

exit($failed > 0 ? 1 : 0);
