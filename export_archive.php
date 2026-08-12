<?php
declare(strict_types=1);

/**
 * export_archive.php — download archive history as a CSV file.
 *
 * Mirrors export_inventory.php for the read-only archive. All filters used on
 * the Archive page (archive.php) are supported and combined:
 *
 *   GET export_archive.php                        → every archive row
 *   GET export_archive.php?q=foo&status=Sold      → matching rows
 *
 * Filters: q, status, source, legacy_source, sold_from, sold_to
 *
 * The CSV is streamed with a UTF-8 BOM (Excel-safe), RFC 4180 quoting, and a
 * dated attachment filename.
 */
require_once __DIR__ . '/config.php';
checkMaintenance();
ensureStorageWritable();

const EXPORT_ARCHIVE_DB_PATH = __DIR__ . '/data/archive.sqlite';
const EXPORT_ARCHIVE_FALLBACK_DB_PATH = __DIR__ . '/data/intake.sqlite';

function exportArchiveResolveDbPath(): string
{
    if (is_file(EXPORT_ARCHIVE_DB_PATH)) {
        return EXPORT_ARCHIVE_DB_PATH;
    }
    return EXPORT_ARCHIVE_FALLBACK_DB_PATH;
}

function exportArchiveCsvCell(mixed $value): string
{
    return (string)($value ?? '');
}

function exportArchiveRowToCsv(array $row): string
{
    $cells = [
        exportArchiveCsvCell($row['sku'] ?? ''),
        exportArchiveCsvCell($row['title'] ?? ''),
        exportArchiveCsvCell($row['status'] ?? ''),
        exportArchiveCsvCell($row['sold_at'] ?? ''),
        exportArchiveCsvCell($row['sold_price'] ?? ''),
        exportArchiveCsvCell($row['purchase_price'] ?? ''),
        exportArchiveCsvCell($row['source'] ?? ''),
        exportArchiveCsvCell($row['buyer'] ?? ''),
        exportArchiveCsvCell($row['legacy_source'] ?? ''),
        exportArchiveCsvCell($row['legacy_table'] ?? ''),
        exportArchiveCsvCell($row['legacy_id'] ?? ''),
        exportArchiveCsvCell($row['notes'] ?? ''),
        exportArchiveCsvCell($row['created_at'] ?? ''),
        exportArchiveCsvCell($row['updated_at'] ?? ''),
    ];
    return implode(',', array_map(static function (string $cell): string {
        if (strpbrk($cell, ",\"\r\n") !== false) {
            return '"' . str_replace('"', '""', $cell) . '"';
        }
        return $cell;
    }, $cells)) . "\r\n";
}

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$sourceFilter = trim((string)($_GET['source'] ?? ''));
$legacySourceFilter = trim((string)($_GET['legacy_source'] ?? ''));
$soldFrom = trim((string)($_GET['sold_from'] ?? ''));
$soldTo = trim((string)($_GET['sold_to'] ?? ''));

$archiveDbPath = exportArchiveResolveDbPath();
if (!is_readable($archiveDbPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Archive database is not readable.';
    exit;
}

try {
    $pdo = pdoConnect($archiveDbPath);

    // Same table the Archive page reads from (created lazily there).
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
    legacy_payload TEXT NOT NULL
);
SQL);

    $where = [];
    $params = [];

    if ($q !== '') {
        $prefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], strtolower($q)) . '%';
        $skuPrefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], strtoupper($q)) . '%';
        $any = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], strtolower($q)) . '%';
        $where[] = '(sku_normalized LIKE :sku_prefix OR lower(COALESCE(sku, \'\')) LIKE :q_prefix OR lower(COALESCE(title, \'\')) LIKE :q_prefix OR lower(COALESCE(status, \'\')) LIKE :q_prefix OR lower(COALESCE(source, \'\')) LIKE :q_prefix OR lower(COALESCE(buyer, \'\')) LIKE :q_prefix OR lower(COALESCE(legacy_source, \'\')) LIKE :q_prefix OR lower(COALESCE(legacy_table, \'\')) LIKE :q_prefix OR lower(COALESCE(legacy_id, \'\')) LIKE :q_prefix OR lower(COALESCE(notes, \'\')) LIKE :q_any)';
        $params[':sku_prefix'] = $skuPrefix;
        $params[':q_prefix'] = $prefix;
        $params[':q_any'] = $any;
    }
    if ($statusFilter !== '') {
        $where[] = 'lower(COALESCE(status, \'\')) = lower(:status)';
        $params[':status'] = $statusFilter;
    }
    if ($sourceFilter !== '') {
        $where[] = 'lower(COALESCE(source, \'\')) = lower(:source)';
        $params[':source'] = $sourceFilter;
    }
    if ($legacySourceFilter !== '') {
        $where[] = 'lower(COALESCE(legacy_source, \'\')) = lower(:legacy_source)';
        $params[':legacy_source'] = $legacySourceFilter;
    }
    if ($soldFrom !== '') {
        $where[] = 'date(COALESCE(sold_at, created_at, updated_at)) >= date(:sold_from)';
        $params[':sold_from'] = $soldFrom;
    }
    if ($soldTo !== '') {
        $where[] = 'date(COALESCE(sold_at, created_at, updated_at)) <= date(:sold_to)';
        $params[':sold_to'] = $soldTo;
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $sql = '
        SELECT sku, title, status, sold_at, sold_price, purchase_price,
               source, buyer, legacy_source, legacy_table, legacy_id,
               notes, created_at, updated_at
        FROM archive_items
        ' . $whereSql . '
        ORDER BY COALESCE(sold_at, updated_at, created_at) DESC, id DESC
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $filename = 'archive_' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    // UTF-8 BOM so Excel opens the file with correct encoding.
    echo "\xEF\xBB\xBF";
    echo "SKU,Title,Status,Sold / Date,Sale Price,Purchase Price,Source,Buyer,Legacy Source,Legacy Table,Legacy ID,Notes,Created,Updated\r\n";
    foreach ($rows as $row) {
        echo exportArchiveRowToCsv($row);
    }
    exit;
} catch (Throwable $error) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Export failed: ' . $error->getMessage();
    exit;
}
