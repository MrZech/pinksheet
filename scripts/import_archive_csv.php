<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/import_archive_csv.php <csv-path> [--source=LegacyDB] [--table=table_name] [--dry-run]\n");
    exit(4);
}

function normalizeHeader(string $header): string
{
    return preg_replace('/[^a-z0-9]+/', '', strtolower(trim($header))) ?? '';
}

function normalizeSku(string $sku): string
{
    return strtoupper(trim($sku));
}

function firstValue(array $row, array $keys): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }
        $value = trim((string)$row[$key]);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function toFloatOrNull(string $value): ?float
{
    $clean = preg_replace('/[^0-9.\-]+/', '', trim($value));
    if ($clean === null || $clean === '') {
        return null;
    }
    if (!is_numeric($clean)) {
        return null;
    }
    return (float)$clean;
}

function normalizeDateValue(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    try {
        $dt = new DateTime($value);
        return $dt->format('Y-m-d');
    } catch (Exception $e) {
        return $value;
    }
}

function normalizeDateTimeValue(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    try {
        $dt = new DateTime($value);
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return $value;
    }
}

function ensureArchiveItemsTable(PDO $pdo): void
{
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

    $columns = $pdo->query('PRAGMA table_info(archive_items)')->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_map(static fn (array $column): string => (string)$column['name'], $columns);
    foreach ([
        'legacy_location_id TEXT',
        'legacy_category_id TEXT',
    ] as $definition) {
        $columnName = strtok($definition, ' ');
        if ($columnName !== false && !in_array($columnName, $columnNames, true)) {
            $pdo->exec('ALTER TABLE archive_items ADD COLUMN ' . $definition);
        }
    }

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_archive_items_sku_normalized ON archive_items (sku_normalized)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_archive_items_status_sold_at ON archive_items (status, sold_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_archive_items_legacy_source ON archive_items (legacy_source, legacy_table)");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_archive_items_legacy_identity ON archive_items (legacy_source, legacy_table, legacy_id)");
}

$csvPath = $argv[1];
$options = getopt('', ['source::', 'table::', 'dry-run']);
$dryRun = in_array('--dry-run', $argv, true) || array_key_exists('dry-run', $options);
$legacySource = trim((string)($options['source'] ?? ''));
if ($legacySource === '') {
    $legacySource = pathinfo($csvPath, PATHINFO_FILENAME);
}
$legacyTable = trim((string)($options['table'] ?? ''));

if (!is_file($csvPath) || !is_readable($csvPath)) {
    fwrite(STDERR, "CSV file is missing or not readable: $csvPath\n");
    exit(2);
}

ensureStorageWritable();

$pdo = new PDO('sqlite:' . __DIR__ . '/../data/intake.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
ensureArchiveItemsTable($pdo);

$fh = fopen($csvPath, 'rb');
if ($fh === false) {
    fwrite(STDERR, "Could not open CSV: $csvPath\n");
    exit(3);
}

$headerRow = fgetcsv($fh, 0, ',', '"', '');
if ($headerRow === false) {
    fwrite(STDERR, "CSV has no header row: $csvPath\n");
    exit(3);
}

$headers = array_map(static fn ($header): string => normalizeHeader((string)$header), $headerRow);
$inserted = 0;
$skipped = 0;
$processed = 0;

$insertSql = <<<SQL
INSERT OR IGNORE INTO archive_items (
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
SQL;
$insert = $pdo->prepare($insertSql);

while (($data = fgetcsv($fh, 0, ',', '"', '')) !== false) {
    if ($data === [null] || $data === []) {
        continue;
    }
    $processed++;
    $row = [];
    foreach ($headers as $idx => $header) {
        if ($header === '') {
            continue;
        }
        $row[$header] = $data[$idx] ?? '';
    }

    $sku = firstValue($row, ['sku', 'itemsku', 'productsku', 'stockcode', 'inventorysku']);
    $title = firstValue($row, ['title', 'whatisit', 'itemname', 'name', 'description', 'itemdescription']);
    $status = firstValue($row, ['status', 'itemstatus', 'soldstatus', 'legacystatus']);
    if ($status === '') {
        $status = 'Archived';
    }
    $soldAt = normalizeDateValue(firstValue($row, ['soldat', 'solddate', 'date_sold', 'datesold']));
    $soldPrice = toFloatOrNull(firstValue($row, ['soldprice', 'saleprice', 'price', 'finalprice']));
    $purchasePrice = toFloatOrNull(firstValue($row, ['purchaseprice', 'cost', 'buyprice', 'acquisitioncost']));
    $source = firstValue($row, ['source', 'originsource', 'camefrom', 'location']);
    $buyer = firstValue($row, ['buyer', 'customer', 'purchaser', 'soldto']);
    $notes = firstValue($row, ['notes', 'note', 'comment', 'comments', 'memo']);
    $legacyId = firstValue($row, ['legacyid', 'recordid', 'rowid', 'id', 'inventoryid']);
    $legacyLocationId = firstValue($row, ['locationid', 'legacylocationid', 'location']);
    $legacyCategoryId = firstValue($row, ['ebaycategoryid', 'categoryid', 'legacycategoryid']);
    $createdAt = normalizeDateTimeValue(firstValue($row, ['createdat', 'created', 'importedat', 'addedat']));
    $updatedAt = normalizeDateTimeValue(firstValue($row, ['updatedat', 'updated', 'modifiedat']));
    if ($createdAt === '') {
        $createdAt = gmdate('Y-m-d H:i:s');
    }
    if ($updatedAt === '') {
        $updatedAt = $createdAt;
    }
    $legacyPayload = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($legacyPayload === false) {
        $legacyPayload = '{}';
    }

    $params = [
        ':created_at' => $createdAt,
        ':updated_at' => $updatedAt,
        ':sku' => $sku,
        ':sku_normalized' => normalizeSku($sku),
        ':title' => $title,
        ':status' => $status,
        ':sold_at' => $soldAt,
        ':sold_price' => $soldPrice,
        ':purchase_price' => $purchasePrice,
        ':source' => $source,
        ':buyer' => $buyer,
        ':notes' => $notes,
        ':legacy_source' => $legacySource,
        ':legacy_table' => $legacyTable,
        ':legacy_id' => $legacyId,
        ':legacy_location_id' => $legacyLocationId,
        ':legacy_category_id' => $legacyCategoryId,
        ':legacy_payload' => $legacyPayload,
    ];

    if (!$dryRun) {
        $insert->execute($params);
        if ($insert->rowCount() > 0) {
            $inserted++;
        } else {
            $skipped++;
        }
    }
}

fclose($fh);

if ($dryRun) {
    echo "Dry run complete. Rows seen: {$processed}\n";
    exit(0);
}

echo "Import complete. Rows seen: {$processed}, inserted: {$inserted}, skipped: {$skipped}\n";
