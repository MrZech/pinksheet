<?php
declare(strict_types=1);

/*
 * Pinksheet Test Bootstrap
 *
 * Sets up a sandboxed SQLite in-memory database, patches constants,
 * and provides helper functions so test files can require production
 * code without hitting the real filesystem or database.
 */

// ── 1. Detect test environment ──────────────────────────────────────────
define('TESTING_ROOT', __DIR__ . '/..');
$testDbDir = TESTING_ROOT . '/tmp/test_data';
$testDbPath = $testDbDir . '/intake.sqlite';

if (!is_dir($testDbDir)) {
    mkdir($testDbDir, 0777, true);
}

// ── 2. Override constants BEFORE production config loads ────────────────
// The production config.php defines constants unconditionally (const).
// We define them here first so when config.php runs, the "const"
// declaration triggers a fatal error *unless* we isolate per-test.
// Instead of fighting that, each test file that needs production code
// should use a require-once guard that skips the problematic lines.
//
// Our strategy: provide a dedicated helper that copies the production
// schema into the sandbox DB so tests can run queries against a real
// SQLite file without altering the true database.

/**
 * Create a fresh sandbox SQLite database with the intake schema.
 * Returns the PDO instance.
 */
function createSandboxDatabase(): PDO
{
    $path = TESTING_ROOT . '/tmp/test_data/intake_' . bin2hex(random_bytes(8)) . '.sqlite';
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');

    $pdo->exec("CREATE TABLE IF NOT EXISTS intake_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now')),
        sku TEXT,
        sku_normalized TEXT,
        status TEXT,
        what_is_it TEXT,
        date_received TEXT,
        source TEXT,
        functional TEXT,
        condition TEXT,
        is_square INTEGER,
        care_if_square INTEGER,
        cords_adapters TEXT,
        keep_items_together TEXT,
        picture_taken TEXT,
        power_on TEXT,
        brand_model TEXT,
        ram TEXT,
        ssd_gb TEXT,
        cpu TEXT,
        os TEXT,
        battery_health TEXT,
        graphics_card TEXT,
        screen_resolution TEXT,
        diagnostics_test_ran INTEGER,
        wifi_card_installed INTEGER,
        compatible_os TEXT,
        where_it_goes TEXT,
        ebay_status TEXT,
        ebay_price REAL,
        dispotech_price REAL,
        in_ebay_room TEXT,
        what_box TEXT,
        notes TEXT,
        reviewed INTEGER DEFAULT 0
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sku_photos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sku_normalized TEXT NOT NULL,
        original_name TEXT NOT NULL,
        stored_name TEXT NOT NULL,
        mime_type TEXT NOT NULL,
        file_size INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS intake_deleted AS SELECT * FROM intake_items WHERE 0");
    $pdo->exec('ALTER TABLE intake_deleted ADD COLUMN deleted_at TEXT');

    $pdo->exec("CREATE TABLE IF NOT EXISTS intake_drafts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sku_normalized TEXT NOT NULL,
        payload TEXT NOT NULL,
        version INTEGER NOT NULL DEFAULT 1,
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_intake_drafts_sku ON intake_drafts (sku_normalized)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS script_cache (
        sku_normalized TEXT PRIMARY KEY,
        sku_display TEXT NOT NULL,
        prompt_text TEXT,
        chatgpt_text TEXT,
        final_text TEXT,
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS square_catalog_sync (
        sku_normalized TEXT PRIMARY KEY,
        square_item_id TEXT,
        square_item_version INTEGER,
        square_variation_id TEXT,
        square_variation_version INTEGER,
        square_image_id TEXT,
        square_image_photo_id INTEGER,
        payload_hash TEXT,
        last_synced_at TEXT,
        last_error TEXT,
        last_sale_sync_at TEXT,
        last_inventory_sync TEXT,
        sync_enabled INTEGER NOT NULL DEFAULT 1,
        last_origin TEXT,
        last_correlation_id TEXT,
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    // Add quantity column if not present
    $cols2 = $pdo->query("PRAGMA table_info(intake_items)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('quantity', $cols2, true)) {
        $pdo->exec("ALTER TABLE intake_items ADD COLUMN quantity INTEGER NOT NULL DEFAULT 1");
    }
    // Keep intake_deleted schema in sync with intake_items for soft-delete INSERT
    $delCols = $pdo->query("PRAGMA table_info(intake_deleted)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('quantity', $delCols, true)) {
        $pdo->exec("ALTER TABLE intake_deleted ADD COLUMN quantity INTEGER NOT NULL DEFAULT 1");
    }

    return $pdo;
}

/**
 * Simulate an HTTP request by setting $_SERVER, $_POST, $_GET globals.
 * Returns an output-capture buffer.
 */
function simulateRequest(string $method, string $uri, array $params = [], array $headers = []): string
{
    $_SERVER['REQUEST_METHOD'] = strtoupper($method);
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    foreach ($headers as $k => $v) {
        $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
    }

    if (strtoupper($method) === 'GET') {
        $_GET = $params;
        $_POST = [];
    } else {
        $_POST = $params;
        $_GET = [];
    }

    ob_start();
    return $uri;
}

/**
 * Clean up all sandbox database files.
 */
function cleanSandboxDatabases(): void
{
    $files = glob(TESTING_ROOT . '/tmp/test_data/intake_*.sqlite');
    if ($files !== false) {
        foreach ($files as $f) {
            @unlink($f);
            $wal = $f . '-wal';
            $shm = $f . '-shm';
            if (is_file($wal)) { @unlink($wal); }
            if (is_file($shm)) { @unlink($shm); }
        }
    }
}

// ── 4. Register shutdown clean-up ──────────────────────────────────────
register_shutdown_function('cleanSandboxDatabases');
