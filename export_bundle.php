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
 */
require_once __DIR__ . '/config.php';
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

function bundleRowToCsv(array $row): string
{
    $cells = [
        bundleCsvCell($row['sku'] ?? ''),
        bundleCsvCell($row['status'] ?? ''),
        bundleCsvCell($row['what_is_it'] ?? ''),
        bundleCsvCell(bundleEbayCategory($row)),
        bundleCsvCell($row['quantity'] ?? 1),
        bundleCsvCell($row['dispotech_price'] ?? ''),
        bundleCsvCell($row['ebay_price'] ?? ''),
        bundleCsvCell($row['updated_at'] ?? ''),
    ];
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
function bundleInfoText(array $row, int $photoCount): string
{
    $lines = [
        'SKU: ' . (string)($row['sku'] ?? ''),
        'Status: ' . (string)($row['status'] ?? ''),
        'What is it?: ' . (string)($row['what_is_it'] ?? ''),
        'eBay Category: ' . bundleEbayCategory($row),
        'Qty: ' . (string)($row['quantity'] ?? 1),
        'Dispotech Price: ' . (string)($row['dispotech_price'] ?? ''),
        'eBay Price: ' . (string)($row['ebay_price'] ?? ''),
        'Updated: ' . (string)($row['updated_at'] ?? ''),
        'Photos: ' . $photoCount,
    ];
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

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ZIP support is not available on this server.';
    exit;
}

try {
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

    $sql = 'SELECT id, sku, sku_normalized, status, what_is_it, ebay_category, ebay_category_path, quantity, dispotech_price, ebay_price, updated_at
            FROM intake_items';
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

    // UTF-8 BOM so Excel opens the manifest with correct encoding.
    $csv = "\xEF\xBB\xBF";
    $csv .= "SKU,Status,What is it?,eBay Category,Qty,Dispotech Price,eBay Price,Updated\r\n";
    foreach ($order as $key) {
        $csv .= bundleRowToCsv($items[$key]);
    }

    $tmpZip = tempnam(sys_get_temp_dir(), 'inventory_export_');
    if ($tmpZip === false) {
        throw new RuntimeException('Could not create a temporary file for the export.');
    }
    $tmpZipPath = $tmpZip . '.zip';
    @unlink($tmpZip);

    $zip = new ZipArchive();
    if ($zip->open($tmpZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmpZipPath);
        throw new RuntimeException('Could not create the ZIP archive.');
    }

    $zip->addFromString($csvName, $csv);

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

        $zip->addEmptyDir($folder);

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
                $zipNameInFolder = $label . ($ext !== '' ? '.' . $ext : '');
                if ($zip->addFile($diskPath, $folder . '/' . $zipNameInFolder)) {
                    $photoCount++;
                }
            }
        }

        $zip->addFromString($folder . '/info.txt', bundleInfoText($row, $photoCount));
    }

    $zip->close();

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
    exit;
} catch (Throwable $error) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Export failed: ' . $error->getMessage();
    exit;
}
