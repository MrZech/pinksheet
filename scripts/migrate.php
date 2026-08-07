<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

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

$pdo = pdoConnect(DB_PATH);

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
if (!in_array('reviewed', $names, true)) {
    $pdo->exec("ALTER TABLE intake_items ADD COLUMN reviewed INTEGER NOT NULL DEFAULT 0");
}
if (!in_array('diagnostics_test_ran', $names, true)) {
    $pdo->exec("ALTER TABLE intake_items ADD COLUMN diagnostics_test_ran INTEGER NOT NULL DEFAULT 0");
}
if (!in_array('wifi_card_installed', $names, true)) {
    $pdo->exec("ALTER TABLE intake_items ADD COLUMN wifi_card_installed INTEGER NOT NULL DEFAULT 0");
}
if (!in_array('compatible_os', $names, true)) {
    $pdo->exec("ALTER TABLE intake_items ADD COLUMN compatible_os TEXT");
}
if (!in_array('quantity', $names, true)) {
    $pdo->exec("ALTER TABLE intake_items ADD COLUMN quantity INTEGER NOT NULL DEFAULT 1");
}

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_intake_items_sku_normalized ON intake_items (sku_normalized)");
$pdo->exec("UPDATE intake_items SET sku_normalized = UPPER(TRIM(COALESCE(sku, ''))) WHERE sku_normalized IS NULL OR sku_normalized = ''");
$pdo->exec("CREATE TABLE IF NOT EXISTS intake_deleted AS SELECT * FROM intake_items WHERE 0");
$archiveColumns = [];
foreach ($pdo->query("PRAGMA table_info(intake_deleted)") as $col) {
    $archiveColumns[(string)$col['name']] = true;
}
foreach ($pdo->query("PRAGMA table_info(intake_items)") as $col) {
    $name = (string)$col['name'];
    if ($name === 'id' || isset($archiveColumns[$name])) {
        continue;
    }
    $type = trim((string)($col['type'] ?? ''));
    $definition = 'ALTER TABLE intake_deleted ADD COLUMN ' . $name;
    if ($type !== '') {
        $definition .= ' ' . $type;
    }
    $pdo->exec($definition);
    $archiveColumns[$name] = true;
}
if (!isset($archiveColumns['deleted_at'])) {
    $pdo->exec("ALTER TABLE intake_deleted ADD COLUMN deleted_at TEXT");
}
$dupStmt = $pdo->query("
    SELECT sku_normalized
    FROM intake_items
    WHERE sku_normalized IS NOT NULL AND TRIM(sku_normalized) <> ''
    GROUP BY sku_normalized
    HAVING COUNT(*) > 1
");
$duplicateSkus = $dupStmt->fetchAll(PDO::FETCH_COLUMN);
foreach ($duplicateSkus as $duplicateSku) {
    $rowsStmt = $pdo->prepare("SELECT * FROM intake_items WHERE sku_normalized = :sku ORDER BY updated_at DESC, id DESC");
    $rowsStmt->execute(['sku' => $duplicateSku]);
    $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) < 2) {
        continue;
    }
    $keepIndex = 0;
    foreach ($rows as $index => $row) {
        $combined = strtolower(trim((string)($row['what_is_it'] ?? '') . ' ' . (string)($row['notes'] ?? '')));
        if (str_contains($combined, 'refurb')) {
            $keepIndex = $index;
            break;
        }
    }
    $sampleColumns = array_keys($rows[0]);
    $sampleColumns[] = 'deleted_at';
    $archiveInsertSql = 'INSERT INTO intake_deleted (' . implode(',', $sampleColumns) . ') VALUES (' . implode(',', array_map(static fn($c) => ':' . $c, $sampleColumns)) . ')';
    $pdo->beginTransaction();
    try {
        $archiveInsert = $pdo->prepare($archiveInsertSql);
        foreach ($rows as $index => $row) {
            if ($index === $keepIndex) {
                continue;
            }
            $row['deleted_at'] = (new DateTime('now'))->format('c');
            $archiveInsert->execute($row);
            $pdo->prepare('DELETE FROM intake_items WHERE id = :id')->execute(['id' => $row['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_intake_items_sku_normalized_unique ON intake_items (sku_normalized) WHERE sku_normalized IS NOT NULL AND TRIM(sku_normalized) <> ''");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_intake_items_status_updated ON intake_items (status, updated_at)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_intake_items_status ON intake_items (status)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_intake_items_updated_at ON intake_items (updated_at)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_intake_items_what_is_it ON intake_items (what_is_it)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_intake_items_updated_id ON intake_items (updated_at, id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_intake_items_created_at ON intake_items (created_at)");
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS archive_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    sku TEXT,
    sku_normalized TEXT,
    title TEXT,
    status TEXT,
    sold_at TEXT,
    sold_price REAL,
    purchase_price REAL,
    source TEXT,
    buyer TEXT,
    notes TEXT,
    legacy_source TEXT,
    legacy_table TEXT,
    legacy_id TEXT,
    legacy_location_id TEXT,
    legacy_category_id TEXT,
    legacy_payload TEXT NOT NULL
);
SQL);
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_archive_items_sku_normalized ON archive_items (sku_normalized)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_archive_items_status_sold_at ON archive_items (status, sold_at)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_archive_items_legacy_source ON archive_items (legacy_source, legacy_table)");
$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_archive_items_legacy_identity ON archive_items (legacy_source, legacy_table, legacy_id)");
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
$skuPhotoColumns = $pdo->query("PRAGMA table_info(sku_photos)")->fetchAll(PDO::FETCH_ASSOC);
$skuPhotoNames = array_column($skuPhotoColumns, 'name');
if (!in_array('sort_order', $skuPhotoNames, true)) {
    $pdo->exec("ALTER TABLE sku_photos ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0");
}
if (!in_array('is_thumb', $skuPhotoNames, true)) {
    $pdo->exec("ALTER TABLE sku_photos ADD COLUMN is_thumb INTEGER NOT NULL DEFAULT 0");
}
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_sku_photos_sku_normalized ON sku_photos (sku_normalized)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_sku_photos_sku_thumb ON sku_photos (sku_normalized, is_thumb, id)");

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS square_catalog_sync (
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
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
SQL);

require_once __DIR__ . '/../square_webhook_service.php';
squareWebhookEnsureSchema($pdo);

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS script_cache (
    sku_normalized TEXT PRIMARY KEY,
    sku_display TEXT NOT NULL,
    prompt_text TEXT,
    chatgpt_text TEXT,
    final_text TEXT,
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
SQL);
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_script_cache_updated_at ON script_cache (updated_at)");

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS intake_drafts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sku_normalized TEXT NOT NULL,
    payload TEXT NOT NULL,
    version INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
SQL);
$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_intake_drafts_sku ON intake_drafts (sku_normalized)");

echo "Migration completed. Directories ensured and schema normalized.\n";
