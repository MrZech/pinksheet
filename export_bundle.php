<?php
declare(strict_types=1);

/**
 * export_bundle.php — download the whole inventory as a ZIP organised in folders.
 *
 * Each intake item gets its own folder (named after its SKU) containing:
 *   - every photo attached to that SKU, named readably and in order
 *   - info.txt with the item's key fields
 * The archive root also contains inventory_YYYY-MM-DD.csv, the same flat
 * manifest produced by export_inventory.php.
 *
 *   GET export_bundle.php                → every intake item
 *   GET export_bundle.php?scope=active   → only items that are NOT sold
 *
 * The same optional filters as the CSV export are supported and combined:
 *   sku, status, min_price, max_price
 *
 * The ZIP is built with ZipArchive when the extension is available, and falls
 * back to a pure-PHP (store-only) writer otherwise, so the export works even
 * on minimal PHP builds without the zip extension.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/bundle_zip.php';
checkMaintenance();
ensureStorageWritable();

const BUNDLE_DB_PATH = __DIR__ . '/data/intake.sqlite';
const BUNDLE_PHOTO_DIR = __DIR__ . '/data/sku_photos';

function bundleCsvEscape(string $cell): string
{
    if (strpbrk($cell, ",\"\r\n") !== false) {
        return '"' . str_replace('"', '""', $cell) . '"';
    }
    return $cell;
}

function bundleCsvCell(mixed $value): string
{
    return (string)($value ?? '');
}

function bundleEbayCategory(array $row): string
{
    $name = trim((string)($row['ebay_category'] ?? ''));
    return $name !== '' ? $name : trim((string)($row['ebay_category_path'] ?? ''));
}

function bundleRowToCsv(array $row, array $columns): string
{
    $cells = [];
    foreach ($columns as $col) {
        if ($col === 'ebay_category') {
            $cells[] = bundleCsvCell(bundleEbayCategory($row));
        } else {
            $cells[] = bundleCsvCell($row[$col] ?? '');
        }
    }
    return implode(',', array_map('bundleCsvEscape', $cells)) . "\r\n";
}

/**
 * Filesystem-safe folder name for an item, preferring the operator-visible SKU.
 */
function bundleFolderName(array $row): string
{
    $sku = trim((string)($row['sku'] ?? ''));
    if ($sku === '') {
        $sku = trim((string)($row['sku_normalized'] ?? ''));
    }
    $folder = preg_replace('/[^A-Za-z0-9._-]+/', '_', $sku) ?? '';
    $folder = trim($folder, '._-');
    if ($folder === '') {
        $folder = 'UNASSIGNED-' . (int)($row['id'] ?? 0);
    }
    return $folder;
}

/**
 * Human-readable info.txt for one item folder.
 */
function bundleInfoText(array $row, int $photoCount, array $columns): string
{
    $lines = ['Photos: ' . $photoCount];
    foreach ($columns as $col) {
        $label = match($col) {
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
        $value = $col === 'ebay_category' ? bundleEbayCategory($row) : (string)($row[$col] ?? '');
        $lines[] = $label . ': ' . $value;
    }
    return implode("\n", $lines) . "\n";
}

function bundlePhotoLabel(int $index, string $originalName): string
{
    $base = pathinfo($originalName, PATHINFO_FILENAME);
    $base = sanitizeFilename($base);
    return sprintf('%02d_%s', $index, $base !== '' ? $base : 'photo');
}

$scope = strtolower(trim((string)($_GET['scope'] ?? 'all')));
if ($scope !== 'all' && $scope !== 'active') {
    $scope = 'all';
}

$sku = trim((string)($_GET['sku'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$minPrice = (string)($_GET['min_price'] ?? '');
$maxPrice = (string)($_GET['max_price'] ?? '');

if (!is_readable(BUNDLE_DB_PATH)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Inventory database is not readable.';
    exit;
}

try {
    // A full export can legitimately take a while (large photo libraries);
    // do not let PHP kill it mid-stream, and do not let zlib re-encode a
    // download that is being streamed.
    @set_time_limit(0);
    @ini_set('zlib.output_compression', 'Off');

    // Concurrency guard: never stack two bundle exports. On a single-worker
    // server a second export request would queue behind the first and stall
    // every other page, so reject it immediately with a fast 429 instead.
    $bundleLockPath = __DIR__ . '/data/.bundle_export.lock';
    $bundleLock = @fopen($bundleLockPath, 'c');
    $bundleLockHeld = false;
    if ($bundleLock !== false) {
        $bundleLockHeld = flock($bundleLock, LOCK_EX | LOCK_NB);
    }
    if ($bundleLock !== false && !$bundleLockHeld) {
        http_response_code(429);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'An export is already running. Please wait a moment and try again.';
        exit;
    }

    $pdo = pdoConnect(BUNDLE_DB_PATH);

    // Self-heal the eBay category columns, matching export_inventory.php so a
    // bookmarked export link works against an older database.
    $bundleCols = array_column($pdo->query('PRAGMA table_info(intake_items)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['ebay_category' => 'TEXT', 'ebay_category_path' => 'TEXT', 'ebay_category_id' => 'TEXT'] as $bundleCol => $bundleColDef) {
        if (!in_array($bundleCol, $bundleCols, true)) {
            $pdo->exec("ALTER TABLE intake_items ADD COLUMN $bundleCol $bundleColDef");
        }
    }

    $conditions = [];
    $params = [];

    if ($scope === 'active') {
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

    $sql = 'SELECT * FROM intake_items';
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY updated_at DESC, id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // One folder per SKU. When history holds several rows for the same
    // normalized SKU, use the most recent row and never duplicate its photos.
    $items = [];
    $order = [];
    foreach ($rows as $row) {
        $norm = strtoupper(trim((string)($row['sku_normalized'] ?? '')));
        if ($norm === '') {
            $norm = strtoupper(trim((string)($row['sku'] ?? '')));
        }
        $key = $norm !== '' ? $norm : '__no_sku_' . (int)($row['id'] ?? 0);
        if (!isset($items[$key])) {
            $items[$key] = $row;
            $order[] = $key;
        }
    }

    // Load photo metadata once and group it by normalized SKU.
    $photosBySku = [];
    $photoStmt = $pdo->query('SELECT sku_normalized, original_name, stored_name FROM sku_photos ORDER BY id ASC');
    foreach ($photoStmt->fetchAll(PDO::FETCH_ASSOC) as $photoRow) {
        $norm = strtoupper(trim((string)($photoRow['sku_normalized'] ?? '')));
        if ($norm !== '') {
            $photosBySku[$norm][] = $photoRow;
        }
    }

    $suffix = $scope === 'active' ? 'active_' : '';
    $date = date('Y-m-d');
    $csvName = 'inventory_' . $suffix . $date . '.csv';
    $zipName = 'inventory_' . $suffix . 'photos_' . $date . '.zip';

    // Discover all columns for the CSV header and rows.
    $exportCols = array_column($pdo->query('PRAGMA table_info(intake_items)')->fetchAll(PDO::FETCH_ASSOC), 'name');
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

    // UTF-8 BOM so Excel opens the manifest with correct encoding.
    $csv = "\xEF\xBB\xBF";
    $csv .= implode(',', array_map('bundleCsvCell', $headerLabels)) . "\r\n";
    foreach ($order as $key) {
        $csv .= bundleRowToCsv($items[$key], $exportCols);
    }

    // Collect every archive entry first, then write them with ZipArchive when
    // available or the pure-PHP fallback writer otherwise.
    $entries = [];
    $entries[] = ['name' => $csvName, 'content' => $csv];

    $usedFolders = [];
    foreach ($order as $key) {
        $row = $items[$key];

        // Deduplicate filesystem folder names (two different SKUs can sanitise
        // to the same string, e.g. "A/B" and "A B").
        $folder = bundleFolderName($row);
        $candidate = $folder;
        $n = 2;
        while (isset($usedFolders[strtolower($candidate)])) {
            $candidate = $folder . '-' . $n;
            $n++;
        }
        $usedFolders[strtolower($candidate)] = true;
        $folder = $candidate;

        $entries[] = ['name' => $folder . '/', 'is_dir' => true];

        $norm = strtoupper(trim((string)($row['sku_normalized'] ?? '')));
        if ($norm === '') {
            $norm = strtoupper(trim((string)($row['sku'] ?? '')));
        }

        $photoIndex = 0;
        $photoCount = 0;
        if ($norm !== '' && isset($photosBySku[$norm])) {
            foreach ($photosBySku[$norm] as $photoRow) {
                $storedName = basename((string)($photoRow['stored_name'] ?? ''));
                $diskPath = BUNDLE_PHOTO_DIR . '/' . normalizedSkuDirectory($norm) . '/' . $storedName;
                if ($storedName === '' || !is_file($diskPath)) {
                    continue;
                }
                $ext = strtolower(pathinfo($storedName, PATHINFO_EXTENSION));
                $photoIndex++;
                $label = bundlePhotoLabel($photoIndex, (string)($photoRow['original_name'] ?? ''));
                $entries[] = [
                    'name' => $folder . '/' . $label . ($ext !== '' ? '.' . $ext : ''),
                    'path' => $diskPath,
                ];
                $photoCount++;
            }
        }

        $entries[] = ['name' => $folder . '/info.txt', 'content' => bundleInfoText($row, $photoCount, $exportCols)];
    }

    if (class_exists('ZipArchive')) {
        // Zip extension available: build to a temp file (compressed), then
        // stream the finished archive.
        $tmpZip = tempnam(sys_get_temp_dir(), 'inventory_export_');
        if ($tmpZip === false) {
            throw new RuntimeException('Could not create a temporary file for the export.');
        }
        $tmpZipPath = $tmpZip . '.zip';
        @unlink($tmpZip);
        bundleWriteWithZipArchive($tmpZipPath, $entries);

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipName . '"');
        header('Content-Length: ' . (string)filesize($tmpZipPath));
        header('Cache-Control: no-store');

        $out = fopen($tmpZipPath, 'rb');
        if ($out === false) {
            @unlink($tmpZipPath);
            throw new RuntimeException('Could not read the generated ZIP archive.');
        }
        fpassthru($out);
        fclose($out);
        @unlink($tmpZipPath);
    } else {
        // No zip extension: stream a store-only ZIP straight to the browser.
        // Bytes start flowing immediately, so a big export can never sit
        // silently building (which trips proxy read timeouts and stalls
        // single-worker servers like the built-in `php -S`).
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipName . '"');
        header('Cache-Control: no-store');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();

        $out = fopen('php://output', 'wb');
        if ($out === false) {
            throw new RuntimeException('Could not stream the ZIP archive.');
        }
        bundleWritePureZipToStream($out, $entries);
        fclose($out);
    }

    if ($bundleLock !== false && $bundleLockHeld) {
        flock($bundleLock, LOCK_UN);
        fclose($bundleLock);
    }
    exit;
} catch (Throwable $error) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Export failed: ' . $error->getMessage();
    exit;
}
