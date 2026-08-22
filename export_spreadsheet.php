<?php
declare(strict_types=1);

/**
 * export_spreadsheet.php — download the inventory as an Excel workbook with
 * the photos embedded right in the sheet.
 *
 * One row per SKU (columns: SKU, Status, What is it?, eBay Category, Qty,
 * Dispotech Price, eBay Price, Updated) and every photo for that SKU shown
 * as an anchored thumbnail in the Photos column, so the boss can open one
 * file and look through the whole inventory — no Google API, no service
 * account, nothing external required. The file opens in Excel, LibreOffice,
 * Apple Numbers, and Google Sheets (drag it into sheets.google.com).
 *
 *   GET export_spreadsheet.php                → every intake item
 *   GET export_spreadsheet.php?scope=active   → only items that are NOT sold
 *
 * Thumbnails are reused from the same on-disk cache as photo.php?thumb=1
 * (data/thumbs/), so repeat exports only pay the cost of zipping.
 *
 * The workbook is assembled from the pure part builders in lib/sheet_xlsx.php
 * and written with ZipArchive when available, or the pure-PHP ZIP writer
 * otherwise (streamed straight to the browser), mirroring export_bundle.php.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/bundle_zip.php';
require_once __DIR__ . '/lib/sheet_xlsx.php';
checkMaintenance();
ensureStorageWritable();

const SHEET_DB_PATH = __DIR__ . '/data/intake.sqlite';
const SHEET_PHOTO_DIR = __DIR__ . '/data/sku_photos';
const SHEET_THUMBS_DIR = __DIR__ . '/data/thumbs';
const SHEET_PHOTO_GAP_EMU = 4 * SHEET_PX_TO_EMU; // 4 px between stacked photos

function sheetEbayCategory(array $row): string
{
    $name = trim((string)($row['ebay_category'] ?? ''));
    return $name !== '' ? $name : trim((string)($row['ebay_category_path'] ?? ''));
}

/**
 * One 9-cell row (A–I) for an item; the Photos column stays empty — images
 * are anchored there by the drawing part.
 *
 * @return array<int, string|int|float>
 */
function sheetColLabel(string $col): string
{
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
}

function sheetRowForItem(array $row, array $columns): array
{
    $cells = [];
    foreach ($columns as $col) {
        if ($col === 'ebay_category') {
            $cells[] = sheetEbayCategory($row);
        } elseif ($col === 'quantity') {
            $qty = trim((string)($row['quantity'] ?? ''));
            $cells[] = $qty !== '' ? (int)$qty : '';
        } elseif ($col === 'dispotech_price' || $col === 'ebay_price') {
            $val = trim((string)($row[$col] ?? ''));
            $cells[] = $val !== '' ? (float)$val : '';
        } else {
            $cells[] = (string)($row[$col] ?? '');
        }
    }
    $cells[] = ''; // Photos column (always last)
    return $cells;
}

$scope = strtolower(trim((string)($_GET['scope'] ?? 'all')));
if ($scope !== 'all' && $scope !== 'active') {
    $scope = 'all';
}

if (!is_readable(SHEET_DB_PATH)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Inventory database is not readable.';
    exit;
}

try {
    @set_time_limit(0);
    @ini_set('zlib.output_compression', 'Off');

    // Same concurrency guard as the ZIP export: never stack two heavy
    // exports on the single worker.
    $sheetLockPath = __DIR__ . '/data/.bundle_export.lock';
    $sheetLock = @fopen($sheetLockPath, 'c');
    $sheetLockHeld = false;
    if ($sheetLock !== false) {
        $sheetLockHeld = flock($sheetLock, LOCK_EX | LOCK_NB);
    }
    if ($sheetLock !== false && !$sheetLockHeld) {
        http_response_code(429);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'An export is already running. Please wait a moment and try again.';
        exit;
    }

    $pdo = pdoConnect(SHEET_DB_PATH);

    // Self-heal the eBay category columns, matching the export endpoints.
    $sheetCols = array_column($pdo->query('PRAGMA table_info(intake_items)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['ebay_category' => 'TEXT', 'ebay_category_path' => 'TEXT', 'ebay_category_id' => 'TEXT'] as $sheetCol => $sheetColDef) {
        if (!in_array($sheetCol, $sheetCols, true)) {
            $pdo->exec("ALTER TABLE intake_items ADD COLUMN $sheetCol $sheetColDef");
        }
    }

    $sql = 'SELECT * FROM intake_items';
    if ($scope === 'active') {
        $sql .= " WHERE (status IS NULL OR LOWER(TRIM(status)) != 'sold')";
    }
    $sql .= ' ORDER BY updated_at DESC, id DESC';

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    // One row per SKU (deduplicate history rows for the same SKU), and group
    // photos by normalized SKU — same semantics as the ZIP export.
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

    $photosBySku = [];
    $photoStmt = $pdo->query('SELECT id, sku_normalized, stored_name FROM sku_photos ORDER BY id ASC');
    foreach ($photoStmt->fetchAll(PDO::FETCH_ASSOC) as $photoRow) {
        $norm = strtoupper(trim((string)($photoRow['sku_normalized'] ?? '')));
        if ($norm !== '') {
            $photosBySku[$norm][] = $photoRow;
        }
    }

    if (!is_dir(SHEET_THUMBS_DIR)) {
        @mkdir(SHEET_THUMBS_DIR, 0777, true);
    }

    // Discover all columns and build human-friendly headers.
    $exportCols = array_column($pdo->query('PRAGMA table_info(intake_items)')->fetchAll(PDO::FETCH_ASSOC), 'name');

    // Build the sheet model: rows (header first), image anchors, row heights.
    $modelRows = [array_merge(array_map('sheetColLabel', $exportCols), ['Photos'])];
    $images = [];
    $rowHeights = [];
    $imageIndex = 0;

    foreach ($order as $key) {
        $row = $items[$key];
        $modelRows[] = sheetRowForItem($row, $exportCols);
        $dataRow0 = count($modelRows) - 1;

        $norm = strtoupper(trim((string)($row['sku_normalized'] ?? '')));
        if ($norm === '') {
            $norm = strtoupper(trim((string)($row['sku'] ?? '')));
        }

        $yOff = 0;
        $totalHpx = 0;
        $photoCount = 0;
        if ($norm !== '' && isset($photosBySku[$norm])) {
            foreach ($photosBySku[$norm] as $photoRow) {
                $storedName = basename((string)($photoRow['stored_name'] ?? ''));
                $diskPath = SHEET_PHOTO_DIR . '/' . normalizedSkuDirectory($norm) . '/' . $storedName;
                if ($storedName === '' || !is_file($diskPath)) {
                    continue;
                }
                $thumb = sheetThumbnail($diskPath, SHEET_THUMBS_DIR, (int)($photoRow['id'] ?? 0));
                if ($thumb === null) {
                    continue;
                }
                [$thumbPath, $tw, $th] = $thumb;
                $imageIndex++;
                $wEmu = $tw * SHEET_PX_TO_EMU;
                $hEmu = $th * SHEET_PX_TO_EMU;
                $images[] = [
                    'row'    => $dataRow0,
                    'yOffEmu' => $yOff,
                    'wEmu'   => $wEmu,
                    'hEmu'   => $hEmu,
                    'rId'    => $imageIndex,
                    'path'   => $thumbPath,
                ];
                $yOff += $hEmu + SHEET_PHOTO_GAP_EMU;
                $totalHpx += $th;
                $photoCount++;
            }
        }

        if ($photoCount > 0) {
            // px → points (×0.75), plus inter-photo gaps and a little padding.
            $rowHeights[$dataRow0] = round($totalHpx * 0.75 + ($photoCount - 1) * 3 + 8, 1);
        }
    }

    // Dynamic column widths: narrow for IDs/flags, wide for text fields.
    $colWidths = [];
    foreach ($exportCols as $idx => $col) {
        $colWidths[$idx + 1] = match($col) {
            'sku', 'sku_normalized' => 18,
            'status', 'functional', 'condition', 'os', 'power_on', 'picture_taken', 'is_square', 'care_if_square', 'in_ebay_room', 'wifi_card_installed', 'diagnostics_test_ran' => 13,
            'what_is_it', 'brand_model', 'cpu', 'graphics_card', 'ebay_category_path', 'where_it_goes', 'compatible_os' => 28,
            'ebay_category', 'ebay_category_id', 'ebay_status' => 20,
            'ram', 'ssd_gb', 'screen_resolution', 'battery_health' => 16,
            'dispotech_price', 'ebay_price' => 14,
            'quantity' => 6,
            'date_received', 'created_at', 'updated_at' => 19,
            'notes', 'cords_adapters', 'keep_items_together', 'what_box' => 30,
            'source' => 16,
            default => 15,
        };
    }
    $colWidths[count($exportCols) + 1] = 30; // Photos column

    // Assemble the workbook parts (media thumbnails are streamed from the
    // shared photo.php cache, so they are not part of this map).
    $parts = [
        '[Content_Types].xml'              => sheetBuildContentTypes($imageIndex > 0),
        '_rels/.rels'                      => sheetBuildRootRels(),
        'docProps/core.xml'                => sheetBuildCoreProps(),
        'docProps/app.xml'                 => sheetBuildAppProps(),
        'xl/workbook.xml'                  => sheetBuildWorkbook(),
        'xl/_rels/workbook.xml.rels'       => sheetBuildWorkbookRels(),
        'xl/theme/theme1.xml'              => sheetBuildTheme(),
        'xl/styles.xml'                    => sheetBuildStyles(),
        'xl/worksheets/sheet1.xml'         => sheetBuildSheetXml($modelRows, $imageIndex > 0, $rowHeights, $colWidths),
    ];
    if ($imageIndex > 0) {
        $parts['xl/worksheets/_rels/sheet1.xml.rels'] = sheetBuildSheetRels();
        $parts['xl/drawings/drawing1.xml']            = sheetBuildDrawing($images);
        $parts['xl/drawings/_rels/drawing1.xml.rels'] = sheetBuildDrawingRels($imageIndex);
    }

    $entries = [];
    foreach ($parts as $name => $content) {
        $entries[] = ['name' => $name, 'content' => $content];
    }
    foreach ($images as $img) {
        $entries[] = ['name' => 'xl/media/image' . $img['rId'] . '.jpg', 'path' => $img['path']];
    }

    $filename = 'inventory_photos_' . date('Y-m-d') . '.xlsx';

    if (class_exists('ZipArchive')) {
        // Zip extension available: build to a temp file, then stream it.
        $tmpZip = tempnam(sys_get_temp_dir(), 'inventory_sheet_');
        if ($tmpZip === false) {
            throw new RuntimeException('Could not create a temporary file for the export.');
        }
        $tmpZipPath = $tmpZip . '.zip';
        @unlink($tmpZip);
        bundleWriteWithZipArchive($tmpZipPath, $entries);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string)filesize($tmpZipPath));
        header('Cache-Control: no-store');

        $out = fopen($tmpZipPath, 'rb');
        if ($out === false) {
            @unlink($tmpZipPath);
            throw new RuntimeException('Could not read the generated spreadsheet.');
        }
        fpassthru($out);
        fclose($out);
        @unlink($tmpZipPath);
    } else {
        // No zip extension: stream a store-only XLSX straight to the browser.
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();

        $out = fopen('php://output', 'wb');
        if ($out === false) {
            throw new RuntimeException('Could not stream the spreadsheet.');
        }
        bundleWritePureZipToStream($out, $entries);
        fclose($out);
    }

    if ($sheetLock !== false && $sheetLockHeld) {
        flock($sheetLock, LOCK_UN);
        fclose($sheetLock);
    }
    exit;
} catch (Throwable $error) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Export failed: ' . $error->getMessage();
    exit;
}
