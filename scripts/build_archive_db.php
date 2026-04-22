<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

if (!is_dir(__DIR__ . '/../data')) {
    mkdir(__DIR__ . '/../data', 0777, true);
}

$sourceDb = __DIR__ . '/../data/intake.sqlite';
$archiveDb = __DIR__ . '/../data/archive.sqlite';

if (!is_file($sourceDb)) {
    fwrite(STDERR, "Source database missing: $sourceDb\n");
    exit(2);
}

$source = new PDO('sqlite:' . $sourceDb, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$archive = new PDO('sqlite:' . $archiveDb, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$archive->exec(<<<'SQL'
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

$archive->exec("CREATE INDEX IF NOT EXISTS idx_archive_items_sku_normalized ON archive_items (sku_normalized)");
$archive->exec("CREATE INDEX IF NOT EXISTS idx_archive_items_status_sold_at ON archive_items (status, sold_at)");
$archive->exec("CREATE INDEX IF NOT EXISTS idx_archive_items_legacy_source ON archive_items (legacy_source, legacy_table)");
$archive->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_archive_items_legacy_identity ON archive_items (legacy_source, legacy_table, legacy_id)");

$sourceRows = $source->query('SELECT * FROM archive_items')->fetchAll(PDO::FETCH_ASSOC);
$archive->beginTransaction();
$archive->exec('DELETE FROM archive_items');

$insert = $archive->prepare(<<<'SQL'
INSERT INTO archive_items (
    id,
    created_at,
    updated_at,
    sku,
    sku_normalized,
    title,
    status,
    sold_at,
    sold_price,
    purchase_price,
    source,
    buyer,
    notes,
    legacy_source,
    legacy_table,
    legacy_id,
    legacy_location_id,
    legacy_category_id,
    legacy_payload
) VALUES (
    :id,
    :created_at,
    :updated_at,
    :sku,
    :sku_normalized,
    :title,
    :status,
    :sold_at,
    :sold_price,
    :purchase_price,
    :source,
    :buyer,
    :notes,
    :legacy_source,
    :legacy_table,
    :legacy_id,
    :legacy_location_id,
    :legacy_category_id,
    :legacy_payload
)
SQL);

foreach ($sourceRows as $row) {
    $insert->execute([
        ':id' => $row['id'],
        ':created_at' => $row['created_at'],
        ':updated_at' => $row['updated_at'],
        ':sku' => $row['sku'],
        ':sku_normalized' => $row['sku_normalized'],
        ':title' => $row['title'],
        ':status' => $row['status'],
        ':sold_at' => $row['sold_at'],
        ':sold_price' => $row['sold_price'],
        ':purchase_price' => $row['purchase_price'],
        ':source' => $row['source'],
        ':buyer' => $row['buyer'],
        ':notes' => $row['notes'],
        ':legacy_source' => $row['legacy_source'],
        ':legacy_table' => $row['legacy_table'],
        ':legacy_id' => $row['legacy_id'],
        ':legacy_location_id' => $row['legacy_location_id'] ?? null,
        ':legacy_category_id' => $row['legacy_category_id'] ?? null,
        ':legacy_payload' => $row['legacy_payload'],
    ]);
}

$archive->exec("DELETE FROM sqlite_sequence WHERE name = 'archive_items'");
$archive->exec("INSERT INTO sqlite_sequence(name, seq) SELECT 'archive_items', COALESCE(MAX(id), 0) FROM archive_items");
$archive->commit();

echo "Built archive database at {$archiveDb} from " . count($sourceRows) . " rows.\n";
