<?php
declare(strict_types=1);

/**
 * export_inventory.php — download the inventory as a CSV file.
 *
 * The old "Export CSV" button in home.php referenced a button that was never
 * rendered (dead JS), and its client-side path capped results at 200 rows.
 * This endpoint streams the real, full export from the server:
 *
 *   GET export_inventory.php                → every intake item
 *   GET export_inventory.php?scope=active   → only items that are NOT sold
 *
 * Optional filters mirror the SKU Lookup page and are all combined:
 *   sku, status, min_price, max_price
 *
 * The price filters use the same CAST(:p AS REAL) fix as lookup_preview.php
 * so SQLite compares prices numerically instead of lexicographically.
 */
require_once __DIR__ . '/config.php';
checkMaintenance();
ensureStorageWritable();

const EXPORT_DB_PATH = __DIR__ . '/data/intake.sqlite';

function exportCsvCell(mixed $value): string
{
    return (string)($value ?? '');
}

function exportEbayCategory(array $row): string
{
    $name = trim((string)($row['ebay_category'] ?? ''));
    return $name !== '' ? $name : trim((string)($row['ebay_category_path'] ?? ''));
}

function exportRowToCsv(array $row, array $columns): string
{
    $cells = [];
    foreach ($columns as $col) {
        if ($col === 'ebay_category') {
            $cells[] = exportCsvCell(exportEbayCategory($row));
        } else {
            $cells[] = exportCsvCell($row[$col] ?? '');
        }
    }
    // Escape each cell per RFC 4180 (quote fields containing , " or newlines,
    // double any embedded quotes).
    return implode(',', array_map(static function (string $cell): string {
        if (strpbrk($cell, ",\"\r\n") !== false) {
            return '"' . str_replace('"', '""', $cell) . '"';
        }
        return $cell;
    }, $cells)) . "\r\n";
}

$scope = strtolower(trim((string)($_GET['scope'] ?? 'all')));
if ($scope !== 'all' && $scope !== 'active') {
    $scope = 'all';
}

$sku = trim((string)($_GET['sku'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$minPrice = (string)($_GET['min_price'] ?? '');
$maxPrice = (string)($_GET['max_price'] ?? '');

if (!is_readable(EXPORT_DB_PATH)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Inventory database is not readable.';
    exit;
}

try {
    $pdo = pdoConnect(EXPORT_DB_PATH);

    // The intake page adds the eBay category columns on load, but this
    // endpoint can be hit directly (e.g. a bookmarked export link), so
    // self-heal the schema here too.
    $existingCols = array_column($pdo->query('PRAGMA table_info(intake_items)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['ebay_category' => 'TEXT', 'ebay_category_path' => 'TEXT', 'ebay_category_id' => 'TEXT'] as $exportCol => $exportColDef) {
        if (!in_array($exportCol, $existingCols, true)) {
            $pdo->exec("ALTER TABLE intake_items ADD COLUMN $exportCol $exportColDef");
        }
    }

    $conditions = [];
    $params = [];

    if ($scope === 'active') {
        // "Active" = anything not marked sold (dashboard "In Progress").
        $conditions[] = "(status IS NULL OR LOWER(TRIM(status)) != 'sold')";
    }

    if ($sku !== '') {
        $skuPrefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $sku) . '%';
        $skuAny = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $sku) . '%';
        $matchClause = "(sku IS NOT NULL AND sku <> '' AND sku LIKE :sku_prefix ESCAPE '\\')"
            . " OR (sku_normalized IS NOT NULL AND sku_normalized <> '' AND sku_normalized LIKE :sku_normalized_prefix ESCAPE '\\')"
            . " OR (what_is_it IS NOT NULL AND what_is_it <> '' AND what_is_it LIKE :sku_any ESCAPE '\\')";
        $conditions[] = '(' . $matchClause . ')';
        $params['sku_prefix'] = $skuPrefix;
        $params['sku_normalized_prefix'] = strtoupper($skuPrefix);
        $params['sku_any'] = $skuAny;
    }

    if ($status !== '') {
        // Statuses are stored inconsistently ('sold' vs legacy 'SOLD'), so
        // match case-insensitively like the active scope does.
        $conditions[] = 'LOWER(TRIM(status)) = LOWER(TRIM(:status))';
        $params['status'] = $status;
    }

    $effectivePrice = 'COALESCE(dispotech_price, ebay_price, 0)';
    if ($minPrice !== '' || $maxPrice !== '') {
        $priceConds = [];
        if ($minPrice !== '') {
            $priceConds[] = "$effectivePrice >= CAST(:min_price AS REAL)";
            $params['min_price'] = (float)$minPrice;
        }
        if ($maxPrice !== '') {
            $priceConds[] = "$effectivePrice <= CAST(:max_price AS REAL)";
            $params['max_price'] = (float)$maxPrice;
        }
        $conditions[] = '(' . implode(' AND ', $priceConds) . ')';
    }

    $exportCols = array_column($pdo->query('PRAGMA table_info(intake_items)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    $sql = 'SELECT * FROM intake_items';
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY updated_at DESC, id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build a human-friendly header row from the discovered columns.
    $headerLabels = array_map(static function (string $col): string {
        return match($col) {
            'id' => 'ID',
            'created_at' => 'Created',
            'updated_at' => 'Updated',
            'sku' => 'SKU',
            'sku_normalized' => 'SKU (Normalized)',
            'status' => 'Status',
            'what_is_it' => 'What is it?',
            'date_received' => 'Date Received',
            'source' => 'Source',
            'functional' => 'Functional',
            'condition' => 'Condition',
            'is_square' => 'Is Square',
            'care_if_square' => 'Care if Square',
            'cords_adapters' => 'Cords/Adapters',
            'keep_items_together' => 'Keep Items Together',
            'picture_taken' => 'Picture Taken',
            'power_on' => 'Power On',
            'brand_model' => 'Brand/Model',
            'ram' => 'RAM',
            'ssd_gb' => 'SSD (GB)',
            'cpu' => 'CPU',
            'os' => 'OS',
            'battery_health' => 'Battery Health',
            'graphics_card' => 'Graphics Card',
            'screen_resolution' => 'Screen Resolution',
            'diagnostics_test_ran' => 'Diagnostics Ran',
            'where_it_goes' => 'Where It Goes',
            'ebay_status' => 'eBay Status',
            'ebay_price' => 'eBay Price',
            'dispotech_price' => 'Dispotech Price',
            'ebay_category' => 'eBay Category',
            'ebay_category_path' => 'eBay Category Path',
            'ebay_category_id' => 'eBay Category ID',
            'in_ebay_room' => 'In eBay Room',
            'what_box' => 'What Box',
            'notes' => 'Notes',
            'quantity' => 'Qty',
            'wifi_card_installed' => 'WiFi Card Installed',
            'compatible_os' => 'Compatible OS',
            default => ucfirst(str_replace('_', ' ', $col)),
        };
    }, $exportCols);

    $suffix = $scope === 'active' ? 'active_' : '';
    $filename = 'inventory_' . $suffix . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    // UTF-8 BOM so Excel opens the file with correct encoding.
    echo "\xEF\xBB\xBF";
    echo implode(',', array_map('exportCsvCell', $headerLabels)) . "\r\n";
    foreach ($rows as $row) {
        echo exportRowToCsv($row, $exportCols);
    }
    exit;
} catch (Throwable $error) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Export failed: ' . $error->getMessage();
    exit;
}
