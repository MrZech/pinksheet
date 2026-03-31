<?php
declare(strict_types=1);

// Simple idempotent migration/repair helper for pinksheet.
// Run with: php scripts/migrate.php

const DB_PATH = __DIR__ . '/../data/intake.sqlite';
const PHOTO_DIR = __DIR__ . '/../data/sku_photos';
const CHUNK_DIR = __DIR__ . '/../data/chunks';
const LOG_DIR = __DIR__ . '/../logs';

function ensureDir(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
    }
}

ensureDir(dirname(DB_PATH));
ensureDir(PHOTO_DIR);
ensureDir(CHUNK_DIR);
ensureDir(LOG_DIR);

$pdo = new PDO('sqlite:' . DB_PATH, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pdo->exec("PRAGMA journal_mode=WAL");
$pdo->exec("PRAGMA synchronous=NORMAL");

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS intake_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    sku TEXT,
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
    where_it_goes TEXT,
    ebay_status TEXT,
    ebay_price REAL,
    dispotech_price REAL,
    in_ebay_room TEXT,
    what_box TEXT,
    notes TEXT,
    sku_normalized TEXT
);
SQL);

$columns = $pdo->query("PRAGMA table_info(intake_items)")->fetchAll(PDO::FETCH_ASSOC);
$names = array_column($columns, 'name');

if (!in_array('sku_normalized', $names, true)) {
    $pdo->exec("ALTER TABLE intake_items ADD COLUMN sku_normalized TEXT");
}
if (!in_array('os', $names, true)) {
    $pdo->exec("ALTER TABLE intake_items ADD COLUMN os TEXT");
}

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_intake_items_sku_normalized ON intake_items (sku_normalized)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_intake_items_status_updated ON intake_items (status, updated_at)");
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS sku_photos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sku_normalized TEXT NOT NULL,
    original_name TEXT NOT NULL,
    stored_name TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    file_size INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
SQL);
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_sku_photos_sku_normalized ON sku_photos (sku_normalized)");

echo "Migration completed. Directories ensured and schema normalized.\n";
