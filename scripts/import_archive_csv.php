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

// Parse flags manually: PHP's getopt() stops at the first positional argument,
// so the documented "csv --source=... --table=..." usage would silently drop
// flags placed after the CSV path.
$options = [];
foreach (array_slice($argv, 2) as $arg) {
    if ($arg === '--dry-run') {
        $options['dry-run'] = true;
    } elseif (str_starts_with($arg, '--source=')) {
        $options['source'] = substr($arg, strlen('--source='));
    } elseif (str_starts_with($arg, '--table=')) {
        $options['table'] = substr($arg, strlen('--table='));
    }
}
$dryRun = array_key_exists('dry-run', $options);

$defaultLegacySource = trim((string)($options['source'] ?? ''));
if ($defaultLegacySource === '') {
    $defaultLegacySource = pathinfo($csvPath, PATHINFO_FILENAME);
}
$defaultLegacyTable = trim((string)($options['table'] ?? ''));

if (!is_file($csvPath) || !is_readable($csvPath)) {
    fwrite(STDERR, "CSV file is missing or not readable: $csvPath\n");
    exit(2);
}

ensureStorageWritable();

$pdo = pdoConnect(__DIR__ . '/../data/intake.sqlite');
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
$expectedColumnCount = count($headers);
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

$columnMismatches = 0;
$cellTruncations = 0;

while (($data = fgetcsv($fh, 0, ',', '"', '')) !== false) {
    if ($data === [null] || $data === []) {
        continue;
    }
    $processed++;

    // Column count parity check
    $fieldCount = count($data);
    if ($fieldCount !== $expectedColumnCount) {
        fwrite(STDERR, "WARNING: row $processed has $fieldCount columns, expected $expectedColumnCount; skipping.\n");
        $columnMismatches++;
        $skipped++;
        continue;
    }

    $row = [];
    foreach ($headers as $idx => $header) {
        if ($header === '') {
            continue;
        }
        $value = $data[$idx] ?? '';
        // Cell field size limits
        if (mb_strlen($value) > 4096) {
            $value = mb_substr($value, 0, 4093) . '...';
            $cellTruncations++;
        }
        $row[$header] = $value;
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
    $rowLegacySource = firstValue($row, ['legacysource', 'legacy_source']);
    $rowLegacyTable = firstValue($row, ['legacytable', 'legacy_table']);
    // CLI --source/--table are the defaults; a per-row legacy_source/legacy_table
    // column wins when the CSV provides one.
    $legacySource = $rowLegacySource !== '' ? $rowLegacySource : $defaultLegacySource;
    $legacyTable = $rowLegacyTable !== '' ? $rowLegacyTable : $defaultLegacyTable;
    $legacyPayload = firstValue($row, ['legacypayload', 'legacy_payload']);
    if ($legacyPayload === '') {
        // Preserve the full raw CSV row (original column names) for auditing
        // and troubleshooting, as documented in docs/archive.md.
        $rawRow = [];
        foreach ($headerRow as $idx => $originalHeader) {
            $rawRow[trim((string)$originalHeader)] = $data[$idx] ?? '';
        }
        $legacyPayload = json_encode($rawRow, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($legacyPayload === false) {
            $legacyPayload = '';
        }
    }
    $normalizedSku = normalizeSku($sku);

    try {
        $insert->execute([
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
            'sku' => $sku,
            'sku_normalized' => $normalizedSku,
            'title' => mb_substr($title, 0, 1024),
            'status' => mb_substr($status, 0, 255),
            'sold_at' => $soldAt,
            'sold_price' => $soldPrice,
            'purchase_price' => $purchasePrice,
            'source' => mb_substr($source, 0, 255),
            'buyer' => mb_substr($buyer, 0, 255),
            'notes' => mb_substr($notes, 0, 4096),
            'legacy_source' => mb_substr((string)$legacySource, 0, 255),
            'legacy_table' => mb_substr((string)$legacyTable, 0, 255),
            'legacy_id' => (string)$legacyId,
            'legacy_location_id' => (string)$legacyLocationId,
            'legacy_category_id' => (string)$legacyCategoryId,
            'legacy_payload' => mb_substr((string)$legacyPayload, 0, 4096),
        ]);
        $inserted++;
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR inserting row $processed: " . $e->getMessage() . "\n");
        $skipped++;
    }
}

fclose($fh);

if ($columnMismatches > 0 || $cellTruncations > 0) {
    fwrite(STDERR, "SUMMARY: $columnMismatches row(s) skipped due to column count mismatch, $cellTruncations cell(s) truncated to 4096 chars.\n");
}

if ($dryRun) {
    echo "Dry run complete. Rows seen: {$processed}\n";
    exit(0);
}

echo "Import complete. Rows seen: {$processed}, inserted: {$inserted}, skipped: {$skipped}\n";
