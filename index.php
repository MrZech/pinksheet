<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/square_sync.php';
checkMaintenance();
ensureStorageWritable();

// Collect non-blocking server-health warnings so the UI can display a banner.
$serverWarnings = [];
if (!function_exists('finfo_open') && !function_exists('mime_content_type')) {
    $serverWarnings[] = 'File info extension is missing. Photo upload MIME validation will use extension-based checks only. Enable php_fileinfo in php.ini for best results.';
}

// Simple intake sheet app backed by SQLite



const DB_DIR = __DIR__ . '/data';
const DB_PATH = __DIR__ . '/data/intake.sqlite';
const LOOKUP_LOG_DIR = __DIR__ . '/logs';
const LOOKUP_LOG_PATH = LOOKUP_LOG_DIR . '/lookup.csv';
const CLEAR_DRAFT_PARAM = 'clear_draft';
const PHOTO_UPLOAD_DIR = DB_DIR . '/sku_photos';
const MAX_SKU_PHOTOS_PER_UPLOAD = 200;
const MAX_SKU_PHOTO_BYTES = 100 * 1024 * 1024;
const ALLOWED_PHOTO_MIME_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
];
$currentPage = 'intake';

if (!is_dir(DB_DIR)) {
    mkdir(DB_DIR, 0777, true);
}
if (!is_dir(PHOTO_UPLOAD_DIR)) {
    mkdir(PHOTO_UPLOAD_DIR, 0777, true);
}

try {
    $pdo = pdoConnect(DB_PATH);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Database connection failed: ' . $e->getMessage() . "\nCheck that data/intake.sqlite is writable and SQLite is enabled.";
    exit;
}

$pdo->beginTransaction();
try {
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
    diagnostics_test_ran INTEGER,
    where_it_goes TEXT,
    ebay_status TEXT,
    ebay_price REAL,
    dispotech_price REAL,
    in_ebay_room TEXT,
    what_box TEXT,
    notes TEXT,
    quantity INTEGER NOT NULL DEFAULT 1
);
SQL);
$intakeColumnNames = array_column($pdo->query('PRAGMA table_info(intake_items)')->fetchAll(PDO::FETCH_ASSOC), 'name');
foreach ([
    'sku_normalized' => 'TEXT',
    'wifi_card_installed' => 'INTEGER NOT NULL DEFAULT 0',
    'compatible_os' => 'TEXT',
    'ebay_category' => 'TEXT',
    'ebay_category_path' => 'TEXT',
    'ebay_category_id' => 'TEXT',
] as $column => $definition) {
    if (!in_array($column, $intakeColumnNames, true)) {
        $pdo->exec("ALTER TABLE intake_items ADD COLUMN $column $definition");
    }
}
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS sku_photos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sku_normalized TEXT NOT NULL,
    original_name TEXT NOT NULL,
    stored_name TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    file_size INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    is_thumb INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0
);
SQL);
$photoColumnNames = array_column($pdo->query('PRAGMA table_info(sku_photos)')->fetchAll(PDO::FETCH_ASSOC), 'name');
foreach ([
    'sort_order' => 'INTEGER NOT NULL DEFAULT 0',
    'is_thumb' => 'INTEGER NOT NULL DEFAULT 0',
] as $column => $definition) {
    if (!in_array($column, $photoColumnNames, true)) {
        $pdo->exec("ALTER TABLE sku_photos ADD COLUMN $column $definition");
    }
}
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_sku_photos_sku_normalized ON sku_photos (sku_normalized)");

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_intake_items_sku_normalized ON intake_items (sku_normalized)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_intake_items_status ON intake_items (status)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_intake_items_updated_at ON intake_items (updated_at)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_intake_items_what_is_it ON intake_items (what_is_it)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_intake_items_updated_id ON intake_items (updated_at, id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_intake_items_created_at ON intake_items (created_at)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_sku_photos_sku_thumb ON sku_photos (sku_normalized, is_thumb, id)");
$schemaVersion = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
if ($schemaVersion < 1) {
    $pdo->exec("UPDATE intake_items SET sku_normalized = UPPER(TRIM(COALESCE(sku, ''))) WHERE sku_normalized IS NULL OR sku_normalized = ''");
    $pdo->exec('PRAGMA user_version = 1');
}
$pdo->exec("CREATE TABLE IF NOT EXISTS intake_deleted AS SELECT * FROM intake_items WHERE 0");
$schemaVersion = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
if ($schemaVersion < 2) {
$dupStmt = $pdo->query("
    SELECT sku_normalized
    FROM intake_items
    WHERE sku_normalized IS NOT NULL AND TRIM(sku_normalized) <> ''
    GROUP BY sku_normalized
    HAVING COUNT(*) > 1
");
$duplicateSkus = $dupStmt->fetchAll(PDO::FETCH_COLUMN);
if ($duplicateSkus) {
    $archiveInsertSql = null;
    foreach ($duplicateSkus as $duplicateSku) {
        $rowsStmt = $pdo->prepare("SELECT * FROM intake_items WHERE sku_normalized = :sku ORDER BY updated_at DESC, id DESC");
        $rowsStmt->execute(['sku' => $duplicateSku]);
        $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) < 2) {
            continue;
        }

        $keepIndex = 0;
        $refurbIndex = null;
        foreach ($rows as $index => $row) {
            $combined = strtolower(trim((string)($row['what_is_it'] ?? '') . ' ' . (string)($row['notes'] ?? '')));
            if (str_contains($combined, 'refurb')) {
                $refurbIndex = $index;
                break;
            }
        }
        if ($refurbIndex !== null) {
            $keepIndex = $refurbIndex;
        }

        if (!isset($archiveInsertSql)) {
            $sampleColumns = array_keys($rows[0]);
            $sampleColumns[] = 'deleted_at';
            $archiveInsertSql = 'INSERT INTO intake_deleted (' . implode(',', $sampleColumns) . ') VALUES (' . implode(',', array_map(static fn($c) => ':' . $c, $sampleColumns)) . ')';
        }
        $archiveInsert = $pdo->prepare($archiveInsertSql);
        foreach ($rows as $index => $row) {
            if ($index === $keepIndex) {
                continue;
            }
            $row['deleted_at'] = (new DateTime('now'))->format('c');
            $archiveInsert->execute($row);
            $pdo->prepare('DELETE FROM intake_items WHERE id = :id')->execute(['id' => $row['id']]);
        }
    }
}
    $pdo->exec('PRAGMA user_version = 2');
}
$schemaVersion = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
if ($schemaVersion < 3) {
    $cols = $pdo->query("PRAGMA table_info(intake_items)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('quantity', $cols, true)) {
        $pdo->exec("ALTER TABLE intake_items ADD COLUMN quantity INTEGER NOT NULL DEFAULT 1");
    }
    // Sync intake_deleted schema
    $delCols = $pdo->query("PRAGMA table_info(intake_deleted)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('quantity', $delCols, true)) {
        $pdo->exec("ALTER TABLE intake_deleted ADD COLUMN quantity INTEGER NOT NULL DEFAULT 1");
    }
    $pdo->exec('PRAGMA user_version = 3');
}
$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_intake_items_sku_normalized_unique ON intake_items (sku_normalized) WHERE sku_normalized IS NOT NULL AND TRIM(sku_normalized) <> ''");
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

$pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}
squareSyncEnsureSchema($pdo);

function ensureArchiveTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS intake_deleted AS SELECT * FROM intake_items WHERE 0");
}

function loadSkuPhotos(PDO $pdo, string $skuNormalized): array
{
    if ($skuNormalized === '') {
        return [];
    }
    $stmt = $pdo->prepare('SELECT id, original_name, mime_type, file_size, created_at FROM sku_photos WHERE sku_normalized = :sku_normalized ORDER BY sort_order ASC, id ASC');
    $stmt->execute(['sku_normalized' => $skuNormalized]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function loadLatestPhotoId(PDO $pdo, string $skuNormalized): ?int
{
    if ($skuNormalized === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id FROM sku_photos WHERE sku_normalized = :sku_normalized ORDER BY is_thumb DESC, id DESC LIMIT 1');
    $stmt->execute(['sku_normalized' => $skuNormalized]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

// Write CSV entries for SKU/status lookups to analyze search trends later.
function logLookup(string $sku, string $status): void
{
    if ($sku === '' && $status === '') {
        return;
    }
    if (!is_dir(LOOKUP_LOG_DIR)) {
        @mkdir(LOOKUP_LOG_DIR, 0777, true);
    }
    $fields = [
        (new DateTime())->format('c'),
        $sku,
        $status,
        $_SERVER['REMOTE_ADDR'] ?? 'cli',
    ];
    $line = implode(',', array_map(static fn (string $value): string => '"' . str_replace('"', '""', $value) . '"', $fields));
    @file_put_contents(LOOKUP_LOG_PATH, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function statusOptions(): array
{
    return ['intake', 'ebay draft', 'ebay review', 'ebay listed', 'dispo tech store', 'sold'];
}

function baseWhatIsItOptions(): array
{
    return ['Laptop', 'Desktop', 'Mini PC'];
}

$whatIsItOptions = baseWhatIsItOptions();

$existingWhatIsIt = $pdo->query("SELECT DISTINCT what_is_it FROM intake_items WHERE what_is_it IS NOT NULL AND TRIM(what_is_it) <> '' ORDER BY what_is_it ASC LIMIT 120")->fetchAll(PDO::FETCH_COLUMN);
foreach ($existingWhatIsIt as $label) {
    $label = trim((string)$label);
    if ($label !== '' && !in_array($label, $whatIsItOptions, true)) {
        $whatIsItOptions[] = $label;
    }
}

$saved = isset($_GET['saved']);
$squareSyncStatus = trim((string)($_GET['square_sync'] ?? ''));
// Preserve save mode across redirect; prefer GET (after redirect) but capture POST so we can include it.
$saveMode = trim($_GET['save_mode'] ?? ($_POST['save_mode'] ?? ''));
$errors = [];
$photoWarnings = [];
$statusOptions = statusOptions();
$lookupSku = trim($_GET['sku'] ?? '');
$lookupSkuNormalized = normalizeSku($lookupSku);
$lookupStatus = trim($_GET['status'] ?? '');
$copySkuPrefill = trim($_GET['copy_sku'] ?? '');
if ($lookupStatus !== '' && !in_array($lookupStatus, $statusOptions, true)) {
    $lookupStatus = '';
}
$deleteMessage = isset($_GET['deleted']) ? (int)$_GET['deleted'] : null;
$clearDraft = isset($_GET[CLEAR_DRAFT_PARAM]);
logLookup($lookupSku, $lookupStatus);
$currentItem = null;
$duplicateCount = 0;
$serverUploadLimitBytes = min(
    iniBytes((string)ini_get('upload_max_filesize')) ?: MAX_SKU_PHOTO_BYTES,
    iniBytes((string)ini_get('post_max_size')) ?: MAX_SKU_PHOTO_BYTES
);
$effectivePhotoLimitBytes = min(MAX_SKU_PHOTO_BYTES, $serverUploadLimitBytes);
$postLimitBytes = iniBytes((string)ini_get('post_max_size')) ?: ($effectivePhotoLimitBytes * MAX_SKU_PHOTOS_PER_UPLOAD);

if ($lookupSkuNormalized !== '') {
    $stmt = $pdo->prepare('SELECT * FROM intake_items WHERE sku_normalized = :sku_normalized ORDER BY id DESC LIMIT 1');
    $stmt->execute(['sku_normalized' => $lookupSkuNormalized]);
    $currentItem = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM intake_items WHERE sku_normalized = :sku_normalized');
    $countStmt->execute(['sku_normalized' => $lookupSkuNormalized]);
    $duplicateCount = (int)$countStmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
    if ($contentLength > 0 && $contentLength > $postLimitBytes && !isset($_POST['bulk_update']) && !isset($_POST['delete_photo_id'])) {
        $errors[] = 'Upload failed: total request size exceeded server limit of ' . humanBytes($postLimitBytes) . '. Try fewer/smaller photos or raise post_max_size.';
    }
    if (isset($_POST['delete_photo_id'])) {
        $photoId = (int)$_POST['delete_photo_id'];
        $photo = null;
        if ($photoId > 0) {
            $stmt = $pdo->prepare('SELECT sku_normalized, stored_name FROM sku_photos WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $photoId]);
            $photo = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($photo) {
            $skuDir = normalizedSkuDirectory((string)($photo['sku_normalized'] ?? ''));
            $storedName = basename((string)($photo['stored_name'] ?? ''));
            $filePath = PHOTO_UPLOAD_DIR . '/' . $skuDir . '/' . $storedName;
            $pdo->prepare('DELETE FROM sku_photos WHERE id = :id')->execute(['id' => $photoId]);
            if (is_file($filePath)) {
                @unlink($filePath);
            }
            $photoWarnings[] = 'Photo deleted.';
        } else {
            $photoWarnings[] = 'Photo could not be found to delete.';
        }
        $redirect = $_SERVER['PHP_SELF'];
        $redirectSku = trim((string)($_POST['sku'] ?? ''));
        if ($redirectSku !== '') {
            $redirect .= '?sku=' . urlencode($redirectSku);
        }
        if ($photoWarnings) {
            $redirect .= ($redirectSku === '' ? '?' : '&') . 'photo_notice=' . urlencode($photoWarnings[0]);
        }
        header('Location: ' . $redirect);
        exit;
    }

        $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
        $sku = trim($_POST['sku'] ?? '');
        $priceRaw = $_POST['price'] ?? ($_POST['dispotech_price'] ?? ($_POST['ebay_price'] ?? ''));
        $price = ($priceRaw !== '' && is_numeric($priceRaw)) ? (float)$priceRaw : null;
        $data = [
            'id' => $id,
            'sku' => $sku,
            'sku_normalized' => normalizeSku($sku),
            // New intake items default to Intake status so they always show on
            // the Status Board; existing items keep whatever status they had.
            'status' => trim($_POST['status'] ?? '') !== '' ? trim($_POST['status']) : 'intake',
            'what_is_it' => trim($_POST['what_is_it'] ?? ''),
            'date_received' => trim($_POST['date_received'] ?? ''),
            'source' => trim($_POST['source'] ?? ''),
            'functional' => trim($_POST['functional'] ?? ''),
            'condition' => trim($_POST['condition'] ?? ''),
            'is_square' => isset($_POST['is_square']) ? 1 : 0,
            'care_if_square' => isset($_POST['care_if_square']) ? 1 : 0,
            'cords_adapters' => trim($_POST['cords_adapters'] ?? ''),
            'keep_items_together' => trim($_POST['keep_items_together'] ?? ''),
            'picture_taken' => trim($_POST['picture_taken'] ?? ''),
            'power_on' => trim($_POST['power_on'] ?? ''),
            'brand_model' => trim($_POST['brand_model'] ?? ''),
            'ram' => trim($_POST['ram'] ?? ''),
            'ssd_gb' => trim($_POST['ssd_gb'] ?? ''),
            'cpu' => trim($_POST['cpu'] ?? ''),
            'os' => trim($_POST['os'] ?? ''),
            'battery_health' => trim($_POST['battery_health'] ?? ''),
            'graphics_card' => trim($_POST['graphics_card'] ?? ''),
            'screen_resolution' => trim($_POST['screen_resolution'] ?? ''),
            'diagnostics_test_ran' => isset($_POST['diagnostics_test_ran']) ? 1 : 0,
            'wifi_card_installed' => isset($_POST['wifi_card_installed']) ? 1 : 0,
            'compatible_os' => trim($_POST['compatible_os'] ?? ''),
            'ebay_category' => trim($_POST['ebay_category'] ?? ''),
            'ebay_category_path' => trim($_POST['ebay_category_path'] ?? ''),
            'ebay_category_id' => trim($_POST['ebay_category_id'] ?? ''),
            'where_it_goes' => trim($_POST['where_it_goes'] ?? ''),
            'ebay_status' => trim($_POST['ebay_status'] ?? ''),
            // Single canonical Price field. We keep both DB columns in sync for backwards compatibility.
            'ebay_price' => $price,
            'dispotech_price' => $price,
            'in_ebay_room' => trim($_POST['in_ebay_room'] ?? ''),
            'what_box' => trim($_POST['what_box'] ?? ''),
            'notes' => trim($_POST['notes'] ?? ''),
            'quantity' => max(1, (int)($_POST['quantity'] ?? 1)),
        ];
        $pendingPhotoUploads = [];

        $uploadedPhotos = normalizeUploadedFiles((array)($_FILES['sku_photos'] ?? []));
        if ($uploadedPhotos) {
            if (count($uploadedPhotos) > MAX_SKU_PHOTOS_PER_UPLOAD) {
                $photoWarnings[] = 'You can upload up to ' . MAX_SKU_PHOTOS_PER_UPLOAD . ' photos at once; extra files were ignored.';
                $uploadedPhotos = array_slice($uploadedPhotos, 0, MAX_SKU_PHOTOS_PER_UPLOAD);
            }
            foreach ($uploadedPhotos as $upload) {
                $originalDisplayName = (string)($upload['name'] ?? 'photo');
                if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if (($upload['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_INI_SIZE) {
                    $photoWarnings[] = $originalDisplayName . ' exceeded server upload_max_filesize of ' . humanBytes($serverUploadLimitBytes) . ' and was skipped.';
                    continue;
                }
                if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                    $photoWarnings[] = $originalDisplayName . ' failed to upload and was skipped (upload error).';
                    continue;
                }
                if (($upload['size'] ?? 0) <= 0 || ($upload['size'] ?? 0) > $effectivePhotoLimitBytes) {
                    $photoWarnings[] = $originalDisplayName . ' is outside the size limit (' . humanBytes($effectivePhotoLimitBytes) . ') and was skipped.';
                    continue;
                }
                if (!is_uploaded_file((string)($upload['tmp_name'] ?? ''))) {
                    $photoWarnings[] = $originalDisplayName . ' looked invalid and was skipped.';
                    continue;
                }
                $pendingPhotoUploads[] = [
                    'name'     => sanitizeFilename($originalDisplayName),
                    'tmp_name' => (string)$upload['tmp_name'],
                    'size'     => (int)$upload['size'],
                    'error'    => UPLOAD_ERR_OK,
                ];
            }
        }

        if (!$errors) {
            $updateStmt = $pdo->prepare(<<<'SQL'
UPDATE intake_items SET
    sku = :sku,
    sku_normalized = :sku_normalized,
    status = :status,
    what_is_it = :what_is_it,
    date_received = :date_received,
    source = :source,
    functional = :functional,
    condition = :condition,
    is_square = :is_square,
    care_if_square = :care_if_square,
    cords_adapters = :cords_adapters,
    keep_items_together = :keep_items_together,
    picture_taken = :picture_taken,
    power_on = :power_on,
    brand_model = :brand_model,
    ram = :ram,
    ssd_gb = :ssd_gb,
    cpu = :cpu,
    os = :os,
    battery_health = :battery_health,
    graphics_card = :graphics_card,
    screen_resolution = :screen_resolution,
    diagnostics_test_ran = :diagnostics_test_ran,
    wifi_card_installed = :wifi_card_installed,
    compatible_os = :compatible_os,
    ebay_category = :ebay_category,
    ebay_category_path = :ebay_category_path,
    ebay_category_id = :ebay_category_id,
    where_it_goes = :where_it_goes,
    ebay_status = :ebay_status,
    ebay_price = :ebay_price,
    dispotech_price = :dispotech_price,
    in_ebay_room = :in_ebay_room,
    what_box = :what_box,
    notes = :notes,
    quantity = :quantity,
    updated_at = datetime('now')
WHERE id = :id;
SQL);
            $saveMode = 'updated';
            if ($id) {
                $updateStmt->execute($data);
            } else {
                $existingStmt = $pdo->prepare('SELECT id FROM intake_items WHERE sku_normalized = :sku_normalized ORDER BY id DESC LIMIT 1');
                $existingStmt->execute(['sku_normalized' => $data['sku_normalized']]);
                $existingId = (int)($existingStmt->fetchColumn() ?: 0);
                if ($existingId > 0) {
                    $data['id'] = $existingId;
                    $updateStmt->execute($data);
                } else {
                    $stmt = $pdo->prepare(<<<'SQL'
INSERT INTO intake_items (
    sku, sku_normalized, status, what_is_it, date_received, source,
    functional, condition, is_square, care_if_square,
    cords_adapters, keep_items_together, picture_taken,
    power_on, brand_model, ram, ssd_gb, cpu, os, battery_health,
    graphics_card, screen_resolution, diagnostics_test_ran, wifi_card_installed, compatible_os, ebay_category, ebay_category_path, ebay_category_id, where_it_goes,
    ebay_status, ebay_price, dispotech_price, in_ebay_room,
    what_box, notes, quantity, updated_at
) VALUES (
    :sku, :sku_normalized, :status, :what_is_it, :date_received, :source,
    :functional, :condition, :is_square, :care_if_square,
    :cords_adapters, :keep_items_together, :picture_taken,
    :power_on, :brand_model, :ram, :ssd_gb, :cpu, :os, :battery_health,
    :graphics_card, :screen_resolution, :diagnostics_test_ran, :wifi_card_installed, :compatible_os, :ebay_category, :ebay_category_path, :ebay_category_id, :where_it_goes,
    :ebay_status, :ebay_price, :dispotech_price, :in_ebay_room,
    :what_box, :notes, :quantity, datetime('now')
);
SQL);
                    $insertData = $data;
                    unset($insertData['id']);
                    $stmt->execute($insertData);
                    $saveMode = 'created';
                }
            }

            if ($pendingPhotoUploads) {
                $maxSort = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM sku_photos WHERE sku_normalized = :sku');
                $maxSort->execute(['sku' => $data['sku_normalized']]);
                $nextSort = (int)$maxSort->fetchColumn() + 1;
                $insertPhotoStmt = $pdo->prepare(<<<'SQL'
INSERT INTO sku_photos (sku_normalized, original_name, stored_name, mime_type, file_size, created_at, sort_order)
VALUES (:sku_normalized, :original_name, :stored_name, :mime_type, :file_size, datetime('now'), :sort_order);
SQL);
                foreach ($pendingPhotoUploads as $upload) {
                    $result = processUploadedPhoto(
                        $upload,
                        $data['sku_normalized']
                    );
                    if (!$result['ok']) {
                        $photoWarnings[] = ($result['message'] ?? 'A photo could not be processed') . '; item was saved.';
                        continue;
                    }
                    $insertPhotoStmt->execute([
                        'sku_normalized' => $data['sku_normalized'],
                        'original_name' => $upload['name'],
                        'stored_name' => $result['stored_name'],
                        'mime_type' => $result['mime_type'],
                        'file_size' => $result['file_size'],
                        'sort_order' => $nextSort++,
                    ]);
                }
            }

            if (!$errors) {
                $squareSyncResult = squareSyncItemBySku($pdo, $data['sku_normalized']);
                if ($data['sku_normalized'] !== '') {
                    $pdo->prepare('DELETE FROM intake_drafts WHERE sku_normalized = :sku_normalized')->execute([
                        'sku_normalized' => $data['sku_normalized'],
                    ]);
                }
                if (isset($_POST['save_and_new'])) {
                    header('Location: intake.php?clear_draft=1&saved=1&save_mode=' . urlencode($saveMode));
                    exit;
                }
                $redirect = $_SERVER['PHP_SELF'] . '?saved=1&save_mode=' . urlencode($saveMode);
                $redirect .= '&square_sync=' . urlencode((string)($squareSyncResult['status'] ?? 'skipped'));
                if ($data['sku'] !== '') {
                    $redirect .= '&sku=' . urlencode($data['sku']);
                }
                header('Location: ' . $redirect);
                exit;
            }
        }
}

$formData = $_POST;
if (!$formData && $currentItem) {
    $formData = $currentItem;
}
if (!$formData && $lookupSku !== '') {
    $formData = ['sku' => $lookupSku];
}
$activeSkuNormalized = normalizeSku(trim((string)($formData['sku'] ?? '')));
if ($activeSkuNormalized === '') {
    $activeSkuNormalized = $lookupSkuNormalized;
}
$skuPhotos = loadSkuPhotos($pdo, $activeSkuNormalized);
$printThumbId = loadLatestPhotoId($pdo, $activeSkuNormalized);
$toastMessage = '';
if ($saved) {
    if ($saveMode === 'created') {
        $toastMessage = 'Saved as new SKU record.';
    } else {
        $toastMessage = 'Saved and synced to this SKU.';
    }
    if ($squareSyncStatus === 'ok') {
        $toastMessage .= ' Square updated.';
    } elseif ($squareSyncStatus === 'error') {
        $toastMessage .= ' Square sync failed; check logs/square_sync.log.';
    } elseif ($squareSyncStatus === 'disabled') {
        $toastMessage .= ' Square sync is not configured.';
    }
}
if (isset($_GET['photo_notice']) && trim((string)$_GET['photo_notice']) !== '') {
    $photoWarnings[] = trim((string)$_GET['photo_notice']);
}

function checked(string $name, string $value, array $formData): string
{
    return (($formData[$name] ?? '') === $value) ? 'checked' : '';
}
$isPartial = ($_GET['partial'] ?? '') === '1';
$currentPage = 'intake';
if (!$isPartial):
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dispo.Tech Intake Sheet</title>
  <link rel="stylesheet" href="assets/style.css?v=<?= getAssetVersion() ?>">
  <script src="assets/menu.js?v=<?= getAssetVersion() ?>" defer></script>
  <link rel="stylesheet" media="print" href="assets/print.css?v=<?= getAssetVersion() ?>">
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <script>window.CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;</script>
  <script src="assets/theme.js?v=<?= getAssetVersion() ?>" defer></script>
  <script src="assets/app.js?v=<?= getAssetVersion() ?>" defer></script>
  <script src="assets/ebay-category.js?v=<?= getAssetVersion() ?>" defer></script>
  <script src="assets/nav.js?v=<?= getAssetVersion() ?>" defer></script>
  <script src="assets/command-palette.js?v=<?= getAssetVersion() ?>" defer></script>
  <script src="assets/qz-tray.js?v=<?= getAssetVersion() ?>" defer></script>
  <style>
    :root {
      --mobile-tap: 44px;
      --gap-mobile: 12px;
      --gap-tablet: 16px;
    }
    /* Mobile-first: single column, stacked layout */
    .sheet.intake .form-columns {
      grid-template-columns: 1fr !important;
    }
    .form-col-left,
    .form-col-right {
      grid-column: 1 / -1 !important;
    }
    .row {
      grid-template-columns: 1fr !important;
    }
    .sheet-header {
      flex-direction: column;
      gap: var(--gap-mobile);
    }
    .sheet-header-right {
      flex-wrap: wrap;
      gap: 8px;
    }
    input,
    select,
    textarea,
    button,
    .button-link {
      min-height: var(--mobile-tap);
      box-sizing: border-box;
    }
    input[type="checkbox"],
    input[type="radio"] {
      min-height: auto;
    }
    input[type="text"],
    input[type="date"],
    input[type="number"],
    select,
    textarea {
      width: 100%;
    }
    .conjoined {
      grid-template-columns: 1fr;
    }
    .conjoined .segment {
      border-right: none;
      border-bottom: 1px solid var(--border-color, #d5dce6);
    }
    .conjoined .segment:last-child {
      border-bottom: none;
    }
    .actions {
      justify-content: stretch;
    }
    .actions button,
    .actions .button-link {
      flex: 1;
      text-align: center;
    }
    /* Tablet breakpoint */
    @media (min-width: 640px) {
      .row {
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)) !important;
      }
      .sheet-header {
        flex-direction: row;
        align-items: flex-start;
      }
      .conjoined {
        grid-template-columns: repeat(2, 1fr);
      }
      .conjoined .segment {
        border-right: 1px solid var(--border-color, #d5dce6);
        border-bottom: none;
      }
      .conjoined .segment:last-child {
        border-right: none;
      }
    }
    /* Desktop breakpoint */
    @media (min-width: 1024px) {
      .sheet.intake .form-columns {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
      }
      .form-col-left {
        grid-column: 1 !important;
      }
      .form-col-right {
        grid-column: 2 !important;
      }
      .page {
        max-width: 1400px;
        margin-left: auto;
        margin-right: auto;
      }
    }
    /* Mobile mode toggle: force single-column at any viewport */
    .sheet.intake.mobile-mode .form-columns {
      grid-template-columns: 1fr !important;
    }
    .sheet.intake.mobile-mode .form-col-left,
    .sheet.intake.mobile-mode .form-col-right {
      grid-column: 1 / -1 !important;
    }
    .sheet.intake.mobile-mode .row {
      grid-template-columns: 1fr !important;
    }
    .sheet.intake.mobile-mode .sheet-header {
      flex-direction: column;
      gap: var(--gap-mobile);
    }
    .sheet.intake.mobile-mode .conjoined {
      grid-template-columns: 1fr;
    }
    .sheet.intake.mobile-mode .conjoined .segment {
      border-right: none;
      border-bottom: 1px solid var(--border-color, #d5dce6);
    }
    .sheet.intake.mobile-mode .conjoined .segment:last-child {
      border-bottom: none;
    }
    .sheet.intake.mobile-mode input,
    .sheet.intake.mobile-mode select,
    .sheet.intake.mobile-mode textarea,
    .sheet.intake.mobile-mode button,
    .sheet.intake.mobile-mode .button-link {
      min-height: var(--mobile-tap);
      box-sizing: border-box;
    }
    .sheet.intake.mobile-mode input[type="checkbox"],
    .sheet.intake.mobile-mode input[type="radio"] {
      min-height: auto;
    }
    .sheet.intake.mobile-mode input[type="text"],
    .sheet.intake.mobile-mode input[type="date"],
    .sheet.intake.mobile-mode input[type="number"],
    .sheet.intake.mobile-mode select,
    .sheet.intake.mobile-mode textarea {
      width: 100%;
    }
    .sheet.intake.mobile-mode .actions {
      justify-content: stretch;
    }
    .sheet.intake.mobile-mode .actions button,
    .sheet.intake.mobile-mode .actions .button-link {
      flex: 1;
      text-align: center;
    }
    /* Hide clutter in mobile mode */
    .sheet.intake.mobile-mode .breadcrumbs,
    .sheet.intake.mobile-mode .print-toggle,
    .sheet.intake.mobile-mode .print-summary,
    .sheet.intake.mobile-mode .updated,
    .sheet.intake.mobile-mode .copy-sku,
    .sheet.intake.mobile-mode .intake-qr-wrap,
    .sheet.intake.mobile-mode .form-col-right,
    .sheet.intake.mobile-mode .draft-restore-wrap,
    .sheet.intake.mobile-mode #print-sticker-btn,
    .sheet.intake.mobile-mode .row:has([name="date_received"]) {
      display: none !important;
    }
    /* Location field visible on desktop and mobile */
    .mobile-location {
      display: block;
    }
  </style>
</head>
<body>
  <div class="layout-wrapper">
  <div class="app-menu">
      <button type="button" class="menu-toggle" aria-expanded="false" aria-controls="global-menu" id="menu-toggle">
        <span class="hamburger" aria-hidden="true"></span>
        <span class="menu-label">Menu</span>
      </button>
      <nav class="menu-panel" id="global-menu" aria-hidden="true">
        <ul class="menu-links">
          <li><a class="menu-link <?php echo $currentPage === 'home' ? 'is-active' : ''; ?>" href="home.php">Dashboard</a></li>
          <li><a class="menu-link <?php echo $currentPage === 'intake' ? 'is-active' : ''; ?>" href="intake.php?clear_draft=1" data-new-intake>Intake</a></li>
          <li><a class="menu-link <?php echo $currentPage === 'kanban' ? 'is-active' : ''; ?>" href="kanban.php">Status Board</a></li>
          <li><a class="menu-link <?php echo $currentPage === 'lookup' ? 'is-active' : ''; ?>" href="lookup.php">SKU Lookup</a></li>
          <li><a class="menu-link <?php echo $currentPage === 'archive' ? 'is-active' : ''; ?>" href="archive.php">Archive</a></li>
          <li><a class="menu-link <?php echo $currentPage === 'script' ? 'is-active' : ''; ?>" href="prompt_builder.php">Script Builder</a></li>
        </ul>
      </nav>
    </div>
    <div id="content-area">
<?php endif; /* end outer shell */ ?>
  <main class="page">
    <div id="save-toast" class="toast" role="status" aria-live="polite"
      data-active="<?php echo $saved ? '1' : '0'; ?>"
      data-message="<?php echo h($toastMessage); ?>">
    </div>

    <section class="sheet intake">
      <div class="sheet-scale" id="sheet-scale">
        <div class="sheet-content" id="sheet-content">
      <header class="sheet-header">
        <div>
          <div class="updated">Last updated: <span><?php echo date('Y-m-d'); ?></span></div>
          <label class="print-toggle">
            <input type="checkbox" id="print-pink">
            <span>Print pink</span>
          </label>
        </div>
        <div class="sheet-header-right">
          <button type="button" class="print-button" id="print-button">Print</button>
          <button type="button" class="theme-toggle" id="theme-toggle">Dark mode</button>
          <button type="button" class="button-link" id="mobile-mode-toggle">Mobile</button>
          <a class="button-link new-intake-cta" href="intake.php?clear_draft=1" data-new-intake>New Intake</a>
        </div>
        <div class="status">
          <?php $formStatus = trim((string)($formData['status'] ?? '')); ?>
          <label>
            <span>Status:</span>
            <!-- Status is editable from the dropdown. New items default to
                 Intake so they always appear on the Status Board. A legacy
                 status that is no longer a lane keeps its raw value as a
                 selected option so it is not silently lost on save. -->
            <select name="status" form="intake-form">
              <?php
                $currentStatus = $formStatus !== '' ? $formStatus : 'intake';
                if (!in_array($currentStatus, $statusOptions, true)) {
                    echo '<option value="' . h($currentStatus) . '" selected>' . h(statusLabel($currentStatus)) . '</option>';
                }
                foreach ($statusOptions as $opt):
              ?>
                <option value="<?php echo h($opt); ?>"<?php echo $currentStatus === $opt ? ' selected' : ''; ?>><?php echo h(statusLabel($opt)); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span>Price:</span>
            <input type="number"
                   step="0.01"
                   name="price"
                   form="intake-form"
                   value="<?php echo h(isset($formData['dispotech_price']) ? (string)$formData['dispotech_price'] : (isset($formData['ebay_price']) ? (string)$formData['ebay_price'] : '')); ?>">
          </label>
          <label>
            <span>Qty:</span>
            <input type="number" name="quantity" form="intake-form" min="1" step="1"
                   value="<?php echo isset($formData['quantity']) ? h((string)(int)$formData['quantity']) : '1'; ?>">
          </label>
        </div>
        <?php
          $printSku = trim((string)($formData['sku'] ?? $activeSkuNormalized));
          $printStatus = trim((string)($formData['status'] ?? ''));
          if ($printStatus === '') {
              $printStatus = 'intake';
          }
          $printPrice = isset($formData['dispotech_price']) && $formData['dispotech_price'] !== ''
            ? $formData['dispotech_price']
            : (isset($formData['ebay_price']) && $formData['ebay_price'] !== '' ? $formData['ebay_price'] : null);
        ?>
        <div class="print-summary" aria-hidden="true">
          <div class="print-summary-label">SKU</div>
          <div class="print-summary-value"><?php echo h($printSku !== '' ? $printSku : '—'); ?></div>
          <div class="print-summary-label">Status</div>
          <div class="print-summary-value"><?php echo h($printStatus !== '' ? statusLabel($printStatus) : 'Select'); ?></div>
          <div class="print-summary-label">Price</div>
          <div class="print-summary-value"><?php echo $printPrice !== null ? '$' . number_format((float)$printPrice, 2) : '—'; ?></div>
          <div class="print-summary-label">Qty</div>
          <div class="print-summary-value"><?php echo isset($formData['quantity']) ? (int)$formData['quantity'] : 1; ?></div>
        </div>
      </header>
      <nav class="breadcrumbs" aria-label="Breadcrumb">
        <a href="home.php">Home</a>
        <span>Intake Sheet</span>
      </nav>

      <h1>Dispo.Tech Tracker Intake Sheet</h1>

      <?php if ($saved): ?>
        <p class="success">
          <?php if ($saveMode === 'created'): ?>
            Saved as new SKU record.
          <?php else: ?>
            Saved and synced to this SKU.
          <?php endif; ?>
        </p>
      <?php endif; ?>

<?php if ($errors): ?>
  <div class="error-box">
    <?php foreach ($errors as $error): ?>
      <p class="error"><?php echo h($error); ?></p>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php if ($serverWarnings): ?>
  <div class="warning-box">
    <?php foreach ($serverWarnings as $sw): ?>
      <p class="warning"><?php echo h($sw); ?></p>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php if ($deleteMessage !== null): ?>
  <p class="<?php echo $deleteMessage > 0 ? 'success' : 'warning'; ?>">
    <?php echo $deleteMessage > 0 ? 'Deleted ' . $deleteMessage . ' record(s).' : 'Delete failed.'; ?>
  </p>
<?php endif; ?>
<?php if ($photoWarnings): ?>
  <div class="warning-box">
    <?php foreach ($photoWarnings as $warning): ?>
      <p class="warning"><?php echo h($warning); ?></p>
    <?php endforeach; ?>
    <p class="hint">Item is saved even if photos were skipped.</p>
  </div>
<?php endif; ?>

      <?php if ($lookupSkuNormalized !== '' && $duplicateCount > 1): ?>
        <p class="warning">This SKU has <?php echo $duplicateCount; ?> records in history. Saving updates the newest one.</p>
      <?php endif; ?>

      <p class="error client-error" id="client-error" hidden>Please fill in SKU before saving.</p>

      <div class="status-bar" id="status-bar">
        <span class="autosave-status" id="autosave-status" hidden>Autosave ready</span>
        <span class="status-chip warn" id="server-draft-banner" hidden>Restored server draft</span>
      </div>
      <div class="intake-progress" id="intake-progress" aria-live="polite" hidden>
        <span class="intake-progress-label" id="intake-progress-label"></span>
        <div class="intake-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="Required fields completed">
          <span id="intake-progress-fill"></span>
        </div>
      </div>

      <form id="photo-delete-form" method="post" class="visually-hidden"><?= csrf_field() ?>
        <input type="hidden" name="delete_photo_id" id="delete-photo-id">
        <input type="hidden" name="sku" id="delete-photo-sku" value="<?php echo h($activeSkuNormalized); ?>">
      </form>

          <form id="intake-form" method="post" enctype="multipart/form-data" class="form-grid"><?= csrf_field() ?>
            <input type="hidden" id="clear-draft" value="<?php echo $clearDraft ? '1' : '0'; ?>">
            <input type="hidden" id="draft-dismiss" value="<?php echo $saved ? '1' : '0'; ?>">
            <input type="hidden" id="has-server-record" value="<?php echo $currentItem ? '1' : '0'; ?>">
            <input type="hidden" id="has-lookup-sku" value="<?php echo $lookupSkuNormalized !== '' ? '1' : '0'; ?>">
            <div class="draft-restore-wrap">
              <button type="button" class="button-link subtle" id="restore-draft-button" hidden>Restore last draft</button>
              <span class="hint" id="restore-hint" hidden>Appears after a clear if a draft is saved locally.</span>
            </div>
            <input type="hidden" name="id" value="<?php echo h(isset($formData['id']) ? (string)$formData['id'] : ''); ?>">
            <div class="form-columns">
              <!-- LEFT COLUMN: top fields + photos -->
              <div class="form-col-left">
              <div class="row">
                <label>SKU
                  <input type="text" name="sku" value="<?php echo h($formData['sku'] ?? ''); ?>" required autofocus>
                </label>
                <div class="copy-sku">
                  <input type="text" id="copy-sku-input" placeholder="Copy fields from SKU">
                  <div class="copy-actions">
                    <button type="button" class="ghost" id="copy-sku-button">Copy fields</button>
                    <button type="button" class="ghost subtle" id="find-sku-button">Find SKU</button>
                  </div>
                  <span class="hint" id="copy-sku-status" hidden></span>
                </div>
                <div class="intake-qr-wrap" id="intake-qr-wrap"<?= $activeSkuNormalized === '' ? ' hidden' : '' ?>>
                  <div class="intake-qr-render" id="intake-qr-render"
                       data-sku="<?php echo h($activeSkuNormalized); ?>"
                       data-url="<?php $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') || (isset($_SERVER['HTTP_CF_VISITOR']) && str_contains($_SERVER['HTTP_CF_VISITOR'], '"scheme":"https"')); $protocol = $isHttps ? 'https' : 'http'; $host = $_SERVER['HTTP_HOST'] ?? 'localhost'; echo h($protocol . '://' . $host . '/card.php?sku=' . urlencode($activeSkuNormalized)); ?>">
                  </div>
                  <span class="intake-qr-label">QR code</span>
                </div>
              <?php
            $currentWhat = trim((string)($formData['what_is_it'] ?? ''));
            $whatOptionsList = $whatIsItOptions;
            if ($currentWhat !== '' && !in_array($currentWhat, $whatOptionsList, true)) {
                $whatOptionsList[] = $currentWhat;
            }
          ?>
              <label>What is it?
                <input type="text" id="what-is-it-input" name="what_is_it"
                       value="<?php echo h($currentWhat); ?>"
                       list="what-is-it-datalist" required
                       placeholder="Type or select an item type">
                <datalist id="what-is-it-datalist">
                  <?php foreach ($whatOptionsList as $opt): ?>
                    <option value="<?php echo h($opt); ?>">
                  <?php endforeach; ?>
                </datalist>
          </label>
              <label class="ebay-category-field">eBay Category
                <span class="ebay-category-combo">
                  <input type="text" name="ebay_category" id="ebay-category-input" value="<?php echo h($formData['ebay_category'] ?? ''); ?>" autocomplete="off" placeholder="Type or pick an eBay category">
                  <ul class="ebay-combo-menu" id="ebay-category-menu" role="listbox" hidden></ul>
                </span>
                <input type="hidden" name="ebay_category_path" value="<?php echo h($formData['ebay_category_path'] ?? ''); ?>">
                <input type="hidden" name="ebay_category_id" value="<?php echo h($formData['ebay_category_id'] ?? ''); ?>">
                <span class="hint">Matches eBay's live electronics categories.</span>
              </label>
            </div>
            <div class="row mobile-location">
              <label>Location / Where it goes
                <input type="text" name="where_it_goes" value="<?php echo h($formData['where_it_goes'] ?? ''); ?>">
              </label>
            </div>
            <p class="error client-error" id="what-error" hidden>Please enter a value for "What is it?".</p>

            <div class="row">
              <label>Date Received
                <input type="date" name="date_received" value="<?php echo h($formData['date_received'] ?? ''); ?>">
              </label>
              <label>Where did it come from?
                <input type="text" name="source" value="<?php echo h($formData['source'] ?? ''); ?>">
              </label>
            </div>

          <div class="section sku-photos" id="sku-photos">
            <h2>SKU Photos</h2>
            <div class="sku-photo-dropzone" id="sku-photo-dropzone" tabindex="0" role="button" aria-label="Add photos for this SKU">
              <label>Add photos for this SKU
                <input type="file" name="sku_photos[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple capture="environment" id="sku-photo-input">
              </label>
              <p class="hint">Drop, paste, or click to add images. You can also paste screenshots or use your camera on mobile.</p>
              <p class="photo-selection-hint" id="photo-selection-hint" hidden></p>
            </div>
            <div class="sku-photo-preview" id="sku-photo-preview" hidden>
              <p class="hint">Queued — these attach to the SKU automatically once you enter it.</p>
              <div class="sku-photo-grid" id="sku-photo-preview-list" aria-live="polite"></div>
            </div>
            <div id="photo-upload-messages" class="upload-messages" aria-live="polite"></div>
            <p class="hint">Photos attach to the SKU as soon as you enter it.</p>
            <p class="hint">Per-photo limit: <?php echo h(humanBytes($effectivePhotoLimitBytes)); ?>.</p>
            <?php if ($activeSkuNormalized === ''): ?>
              <p class="hint">Enter a SKU first to keep photos grouped with that specific item.</p>
            <?php elseif (!$skuPhotos): ?>
              <p class="hint">No photos saved for SKU <?php echo h($activeSkuNormalized); ?> yet.</p>
            <?php else: ?>
              <div class="inline-actions">
                <button type="button" class="ghost button" id="download-all-btn">Download all as PNG</button>
              </div>
              <div class="sku-photo-grid">
                <?php foreach ($skuPhotos as $photo): ?>
                  <div class="sku-photo-item" draggable="true" data-photo-id="<?php echo isset($photo['id']) ? (int)$photo['id'] : 0; ?>">
                    <a class="sku-photo-link" href="photo.php?id=<?php echo isset($photo['id']) ? (int)$photo['id'] : 0; ?>" target="_blank" rel="noopener" title="Open photo in new tab">
                      <span class="sku-photo-badge">SKU <?php echo h($activeSkuNormalized); ?></span>
                      <img src="photo.php?id=<?php echo isset($photo['id']) ? (int)$photo['id'] : 0; ?>&amp;thumb=1"
                           data-full-src="photo.php?id=<?php echo isset($photo['id']) ? (int)$photo['id'] : 0; ?>"
                           alt="Photo for SKU <?php echo h($activeSkuNormalized); ?> — <?php echo h($photo['original_name'] ?? 'Photo'); ?>"
                           loading="lazy">
                    </a>
                    <div class="sku-photo-meta">
                      <span class="sku-photo-name"><?php echo h($photo['original_name'] ?? 'Photo'); ?></span>
                      <?php if (isset($photo['file_size'])): ?>
                        <span class="sku-photo-size"><?php echo round(((int)$photo['file_size']) / 1024, 1); ?> KB</span>
                      <?php endif; ?>
                    </div>
                    <div class="sku-photo-actions">
                      <button type="button"
                              class="ghost danger js-delete-photo"
                              data-photo-id="<?php echo isset($photo['id']) ? (int)$photo['id'] : 0; ?>">
                        Delete
                      </button>
                      <button type="button"
                              class="ghost js-set-thumb"
                              data-photo-id="<?php echo isset($photo['id']) ? (int)$photo['id'] : 0; ?>"
                              data-photo-sku="<?php echo h($activeSkuNormalized); ?>">
                        Set thumbnail
                      </button>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
          </div><!-- end .form-col-left -->

          <!-- RIGHT COLUMN: D1 then D2 -->
          <div class="form-col-right">
            <div class="section">
              <h2>(D1) Intake Tasks</h2>
              <div class="row">
                <fieldset>
                <legend>Functional</legend>
                <label><input type="radio" name="functional" value="Yes" <?php echo checked('functional','Yes', $formData); ?>> Yes</label>
                <label><input type="radio" name="functional" value="No" <?php echo checked('functional','No', $formData); ?>> No</label>
                <label><input type="radio" name="functional" value="Unknown" <?php echo checked('functional','Unknown', $formData); ?>> Unknown</label>
              </fieldset>
              <label>Condition
                <select name="condition">
                  <option value="">Select</option>
                  <?php foreach (['Good','Great','Excellent','Unicorn'] as $opt): ?>
                    <option value="<?php echo $opt; ?>" <?php echo (($formData['condition'] ?? '') === $opt) ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              </div>

              <div class="row">
                <div class="conjoined">
                <label class="segment">
                  <input type="checkbox" name="is_square" <?php echo !empty($formData['is_square']) ? 'checked' : ''; ?>>
                  <span>Is it a Square item?</span>
                </label>
                <label class="segment">
                  <input type="checkbox" name="care_if_square" <?php echo !empty($formData['care_if_square']) ? 'checked' : ''; ?>>
                  <span>Do we care about this item?</span>
                </label>
              </div>
              </div>

              <div class="row">
                <fieldset>
                <legend>Cords / adapters included?</legend>
                <label><input type="radio" name="cords_adapters" value="Yes" <?php echo checked('cords_adapters','Yes', $formData); ?>> Yes</label>
                <label><input type="radio" name="cords_adapters" value="No" <?php echo checked('cords_adapters','No', $formData); ?>> No</label>
              </fieldset>
              <label class="inline">
                <?php $squareChecked = !empty($formData['is_square']) || !empty($formData['care_if_square']); ?>
                <input type="checkbox" name="square_and_care" <?php echo $squareChecked ? 'checked' : ''; ?>>
                <span>Mark as Square priority (checks both above)</span>
              </label>
              <fieldset>
                <legend>Keep items together?</legend>
                <label><input type="radio" name="keep_items_together" value="Yes" <?php echo checked('keep_items_together','Yes', $formData); ?>> Yes</label>
                <label><input type="radio" name="keep_items_together" value="No" <?php echo checked('keep_items_together','No', $formData); ?>> No</label>
              </fieldset>
              <fieldset>
                <legend>Picture</legend>
                <label><input type="radio" name="picture_taken" value="Yes" <?php echo checked('picture_taken','Yes', $formData); ?>> Yes</label>
                <label><input type="radio" name="picture_taken" value="No" <?php echo checked('picture_taken','No', $formData); ?>> No</label>
              </fieldset>
            </div>
          </div>

          <div class="section">
            <h2>(D2) Description Tasks</h2>
            <div class="row">
              <fieldset>
                <legend>Does it power on and stay on?</legend>
                <label><input type="radio" name="power_on" value="Yes" <?php echo checked('power_on','Yes', $formData); ?>> Yes</label>
                <label><input type="radio" name="power_on" value="No" <?php echo checked('power_on','No', $formData); ?>> No</label>
              </fieldset>
              <label>Brand & Model Number
                <input type="text" name="brand_model" value="<?php echo h($formData['brand_model'] ?? ''); ?>">
              </label>
            </div>

            <div class="row">
              <label>RAM
                <input type="text" name="ram" value="<?php echo h($formData['ram'] ?? ''); ?>">
              </label>
              <label>SSD GB
                <input type="text" name="ssd_gb" value="<?php echo h($formData['ssd_gb'] ?? ''); ?>">
              </label>
              <label>CPU
                <input type="text" name="cpu" value="<?php echo h($formData['cpu'] ?? ''); ?>">
              </label>
              <label>OS
                <input type="text" name="os" value="<?php echo h($formData['os'] ?? ''); ?>">
              </label>
            </div>

            <div class="row">
              <div class="compat-os-group">
                <span class="compat-os-label">Compatible OS</span>
                <input type="hidden" name="compatible_os" id="compatible-os-input" value="<?php echo h($formData['compatible_os'] ?? ''); ?>">
                <div class="compat-os-buttons">
                  <?php
                    $currentCompatOs = trim((string)($formData['compatible_os'] ?? ''));
                    $osOptions = ['Windows 10', 'Windows 11', 'Linux'];
                  ?>
                  <?php foreach ($osOptions as $osOpt): ?>
                    <button type="button"
                            class="compat-os-btn<?php echo $currentCompatOs === $osOpt ? ' is-active' : ''; ?>"
                            data-os="<?php echo h($osOpt); ?>">
                      <?php echo h($osOpt); ?>
                    </button>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>

            <div class="row">
              <label>Battery Health
                <input type="text" name="battery_health" value="<?php echo h($formData['battery_health'] ?? ''); ?>">
              </label>
              <label>Graphics Card
                <input type="text" name="graphics_card" value="<?php echo h($formData['graphics_card'] ?? ''); ?>">
              </label>
              <label>Screen Resolution
                <input type="text" name="screen_resolution" value="<?php echo h($formData['screen_resolution'] ?? ''); ?>">
              </label>
            </div>
            <div class="row">
              <label class="inline small-checkbox">
                <input type="checkbox" name="diagnostics_test_ran" <?php echo !empty($formData['diagnostics_test_ran']) ? 'checked' : ''; ?>>
                <span>Diagnostics test was ran</span>
              </label>
              <label class="inline small-checkbox">
                <input type="checkbox" name="wifi_card_installed" <?php echo !empty($formData['wifi_card_installed']) ? 'checked' : ''; ?>>
                <span>Wifi card is installed</span>
              </label>
            </div>
          </div>
          </div><!-- end .form-col-right -->

            <div class="section notes">
              <h2>Notes</h2>
              <textarea name="notes" rows="5"><?php echo h($formData['notes'] ?? ''); ?></textarea>
            </div>

            <div class="actions">
              <label class="inline save-and-new">
                <input type="checkbox" name="save_and_new" id="save-and-new">
                <span>Start a new record after saving</span>
              </label>
              <button type="submit">Save Intake Item</button>
              <button type="button" class="ghost button" id="print-sticker-btn" data-label-preset="compact" title="Generate a small SKU sticker label">Print Sticker</button>
            </div>
          </div><!-- end .form-columns -->
        </form>

      <!-- PRINT LAYOUT: four rigid horizontal rows (visible only in @media print) -->
      <div class="print-grid" aria-hidden="true">

        <!-- Row 1: Accent Header Block -->
        <div class="print-row print-header-row">
          <div class="print-header-left">
            <div class="print-thumb-cell">
              <?php if ($printThumbId): ?>
                <img src="photo.php?id=<?php echo $printThumbId; ?>" alt="">
              <?php endif; ?>
            </div>
            <div class="print-sku-stack">
              <div class="print-row-item">
                <span class="print-label">SKU</span>
                <span class="print-value"><?php echo h($printSku !== '' ? $printSku : '—'); ?></span>
              </div>
              <div class="print-row-item">
                <span class="print-label">Price</span>
                <span class="print-value"><?php echo $printPrice !== null ? '$' . number_format((float)$printPrice, 2) : '—'; ?></span>
              </div>
              <div class="print-row-item">
                <span class="print-label">Status</span>
                <span class="print-value"><?php echo h($printStatus !== '' ? statusLabel($printStatus) : 'Select'); ?></span>
              </div>
            </div>
          </div>
          <div class="print-header-right">
            <div class="print-qr-cell" data-sku="<?php echo h($activeSkuNormalized); ?>"></div>
          </div>
        </div>

        <!-- Row 2: D1 / D2 Split Panels (populated by JS at print time) -->
        <div class="print-row print-fields-row">
          <div class="print-d1-panel" id="print-d1-panel"></div>
          <div class="print-d2-panel" id="print-d2-panel"></div>
        </div>

        <!-- Row 3: Notes Panel (populated by JS at print time) -->
        <div class="print-row print-notes-row">
          <h2>Notes</h2>
          <div class="print-notes-content" id="print-notes-content"></div>
        </div>

        <!-- Row 4: Asset Snapshot Gallery (populated by JS at print time) -->
        <div class="print-row print-gallery-row" id="print-gallery-row"></div>

      </div>

        </div>
      </div>
    </section>
  </main>
  <script>
    (function () {
      var extractPhotoIdFromSrc = function (src) {
        var match = String(src || '').match(/[?&]id=(\d+)/);
        return match ? match[1] : '';
      };
      var waitForImages = function (root, callback) {
        var images = Array.prototype.slice.call(root.querySelectorAll('img'));
        if (!images.length) {
          callback();
          return;
        }

        var remaining = images.length;
        var done = false;
        var finish = function () {
          if (done) return;
          done = true;
          callback();
        };
        var markLoaded = function () {
          remaining -= 1;
          if (remaining <= 0) {
            finish();
          }
        };

        images.forEach(function (img) {
          if (img.complete && img.naturalWidth > 0) {
            markLoaded();
            return;
          }
          img.addEventListener('load', markLoaded, { once: true });
          img.addEventListener('error', markLoaded, { once: true });
        });

        setTimeout(finish, 1800);
      };
      /* ── Print: populate the on-page print grid, then window.print() ──
         Printing runs directly on this page instead of a hidden iframe.
         Some browsers / embedded webviews print the top-level frame rather
         than the iframe, which produced a blank page with only the title.
         Printing the main page also makes Ctrl+P / browser print menus
         work.  print.css hides all screen chrome in print media and shows
         only the .print-grid. */

      var ensureQrLibLoaded = function () {
        if (window.QRCode) return Promise.resolve();
        return new Promise(function (resolve, reject) {
          var existing = document.querySelector('script[data-print-qr-lib="1"]');
          if (existing) {
            existing.addEventListener('load', resolve, { once: true });
            existing.addEventListener('error', reject, { once: true });
            return;
          }
          var s = document.createElement('script');
          s.src = 'assets/qrcode.min.js?v=<?php echo getAssetVersion(); ?>';
          s.dataset.printQrLib = '1';
          s.onload = resolve;
          s.onerror = reject;
          document.body.appendChild(s);
        });
      };

      var populatePrintGrid = function () {
        var grid = document.querySelector('.print-grid');
        if (!grid) return;

        // Populate D1 panel from live form
        var d1Panel = grid.querySelector('#print-d1-panel');
        var liveD1 = document.querySelector('.form-col-right .section:first-child');
        if (d1Panel && liveD1) {
          var d1Clone = liveD1.cloneNode(true);
          d1Clone.querySelectorAll('input, textarea, select').forEach(function (f) {
            f.setAttribute('readonly', 'readonly');
            if (f.tagName === 'INPUT' && f.type !== 'checkbox' && f.type !== 'radio') {
              f.setAttribute('tabindex', '-1');
            }
          });
          var compatButtons = d1Clone.querySelector('.compat-os-buttons');
          if (compatButtons) compatButtons.parentNode.removeChild(compatButtons);
          d1Panel.innerHTML = '';
          d1Panel.appendChild(d1Clone);
        }

        // Populate D2 panel from live form
        var d2Panel = grid.querySelector('#print-d2-panel');
        var liveD2 = document.querySelector('.form-col-right .section:nth-child(2)');
        if (d2Panel && liveD2) {
          var d2Clone = liveD2.cloneNode(true);
          d2Clone.querySelectorAll('input, textarea, select').forEach(function (f) {
            f.setAttribute('readonly', 'readonly');
            if (f.tagName === 'INPUT' && f.type !== 'checkbox' && f.type !== 'radio') {
              f.setAttribute('tabindex', '-1');
            }
          });
          d2Panel.innerHTML = '';
          d2Panel.appendChild(d2Clone);
        }

        // Populate notes
        var notesContent = grid.querySelector('#print-notes-content');
        var liveNotes = document.querySelector('textarea[name="notes"]');
        if (notesContent && liveNotes) {
          notesContent.textContent = liveNotes.value && liveNotes.value.trim() ? liveNotes.value : ' ';
        }

        // Copy field values from live form into cloned panels
        var liveForm = document.getElementById('intake-form');
        if (liveForm) {
          grid.querySelectorAll('input[name], textarea[name], select[name]').forEach(function (dest) {
            var name = dest.getAttribute('name');
            if (!name) return;
            var src = liveForm.querySelector('[name="' + name + '"]');
            if (!src) return;
            if (dest.tagName === 'INPUT') {
              if (dest.type === 'checkbox') {
                dest.checked = src.checked;
              } else if (dest.type === 'radio') {
                dest.checked = src.checked && dest.value === src.value;
              } else {
                dest.value = src.value;
              }
            } else {
              dest.value = src.value;
            }
          });
        }

        // Build gallery from first 4 supplementary photos (skip thumbnail)
        var galleryRow = grid.querySelector('#print-gallery-row');
        if (!galleryRow) return;
        // populatePrintGrid() runs on the Print button click AND on the
        // browser's beforeprint / print media-query events, so always clear
        // the row first or every photo gets duplicated (tripled on browsers
        // that fire both events) in the printed output.
        galleryRow.innerHTML = '';
        var thumbImgEl = grid.querySelector('.print-thumb-cell img');
        var thumbId = thumbImgEl ? extractPhotoIdFromSrc(thumbImgEl.getAttribute('src')) : '';
        var photoItems = document.querySelectorAll('.section.sku-photos .sku-photo-item');
        var count = 0;
        photoItems.forEach(function (item) {
          if (count >= 4) return;
          if (item.classList.contains('is-preview')) return;
          var img = item.querySelector('.sku-photo-link img');
          if (!img) return;
          var src = img.getAttribute('src') || '';
          if (!src) return;
          var photoId = extractPhotoIdFromSrc(src);
          if (thumbId && photoId && String(photoId) === String(thumbId)) return;
          var figure = document.createElement('figure');
          figure.className = 'print-gallery-item';
          var gi = document.createElement('img');
          gi.src = src;
          gi.alt = '';
          figure.appendChild(gi);
          galleryRow.appendChild(figure);
          count++;
        });
      };

      var renderPrintQr = function () {
        var qrCell = document.querySelector('.print-qr-cell');
        if (!qrCell) return Promise.resolve();
        var sku = qrCell.getAttribute('data-sku') || '';
        if (!sku) return Promise.resolve();
        return ensureQrLibLoaded().then(function () {
          var protocol = window.location.protocol === 'https:' ? 'https' : 'http';
          var url = protocol + '://' + window.location.host + '/intake.php?sku=' + encodeURIComponent(sku);
          try {
            qrCell.innerHTML = '';
            new QRCode(qrCell, {
              text: url,
              width: 120,
              height: 120,
              colorDark: '#0f172a',
              colorLight: '#ffffff',
              correctLevel: QRCode.CorrectLevel.H
            });
          } catch (e) {}
        });
      };

      var printPage = function () {
        populatePrintGrid();
        renderPrintQr().finally(function () {
          waitForImages(document, function () {
            setTimeout(function () {
              window.print();
            }, 120);
          });
        });
      };

      var printButton = document.getElementById('print-button');
      if (printButton) {
        printButton.addEventListener('click', function () {
          printPage();
        });
      }

      // Keep Ctrl+P / browser print menus working: populate the grid
      // whenever the browser enters print preview.
      var onBeforePrint = function () {
        populatePrintGrid();
        renderPrintQr().catch(function () {});
      };
      if (window.matchMedia) {
        try {
          var printMql = window.matchMedia('print');
          var onPrintChange = function (mql) {
            if (mql.matches) { onBeforePrint(); }
          };
          if (printMql.addEventListener) {
            printMql.addEventListener('change', onPrintChange);
          } else if (printMql.addListener) {
            printMql.addListener(onPrintChange);
          }
        } catch (e) {}
      }
      window.addEventListener('beforeprint', onBeforePrint);

      var whatInput = document.getElementById('what-is-it-input');
      var whatError = document.getElementById('what-error');
      if (whatInput) {
        whatInput.addEventListener('change', function () {
          if (whatError) {
            whatError.hidden = true;
          }
        });
      }

      var intakeLinks = document.querySelectorAll('[data-new-intake]');
      if (intakeLinks.length) {
        var clearIntakeDraft = function () {
          try {
            localStorage.removeItem('intakeDraftV1');
          } catch (e) {}
        };
        intakeLinks.forEach(function (link) {
          link.addEventListener('click', clearIntakeDraft);
        });
      }
      var form = document.getElementById('intake-form');
      if (form) {
        var draftKey = 'intakeDraftV1';
        var backupKey = 'intakeDraftBackupV1';
        var errorEl = document.getElementById('client-error');
        var clearDraft = document.getElementById('clear-draft');
        var restoreBtn = document.getElementById('restore-draft-button');
        var restoreHint = document.getElementById('restore-hint');
      var copySkuInput = document.getElementById('copy-sku-input');
      var copySkuButton = document.getElementById('copy-sku-button');
      var copySkuStatus = document.getElementById('copy-sku-status');
      var findSkuButton = document.getElementById('find-sku-button');
      var findSkuModal = document.getElementById('find-sku-modal');
      var findSkuBackdrop = document.getElementById('find-sku-backdrop');
      var findSkuClose = document.getElementById('find-sku-close');
      var findSkuQuery = document.getElementById('find-sku-query');
      var findSkuResults = document.getElementById('find-sku-results');
      var copySkuPrefill = '<?php echo h($copySkuPrefill); ?>';
        var applyDraftObject = function (draft) {
          if (!draft) return;
          Object.keys(draft).forEach(function (name) {
            var value = draft[name];
            var fields = form.querySelectorAll('[name="' + name + '"]');
            fields.forEach(function (field) {
              if (field.type === 'radio') {
                field.checked = (field.value === value);
                return;
              }
              if (field.type === 'checkbox') {
                field.checked = !!value;
                return;
              }
              if (field.type === 'file') {
                return;
              }
              field.value = value;
            });
          });
        };

        var applyDataFiltered = function (data) {
          if (!data) return;
          var filtered = Object.assign({}, data);
          delete filtered.id;
          delete filtered.sku;
          delete filtered.sku_normalized;
          delete filtered.created_at;
          delete filtered.updated_at;
          applyDraftObject(filtered);
        };
        var fetchSkuData = function (sku, cb) {
          fetch('copy_item.php?sku=' + encodeURIComponent(sku))
            .then(function (r) { return r.json(); })
            .then(function (data) { cb(null, data); })
            .catch(function (err) { cb(err); });
        };
        var openCopyModal = function () {
          var sku = (copySkuInput && copySkuInput.value || '').trim();
          if (!sku) {
            copySkuStatus.hidden = false;
            copySkuStatus.textContent = 'Enter a SKU to copy.';
            copySkuStatus.className = 'hint error';
            return;
          }
          copySkuStatus.hidden = false;
          copySkuStatus.textContent = 'Loading...';
          copySkuStatus.className = 'hint';
          fetchSkuData(sku, function (err, data) {
            if (err || !data || data.status !== 'ok' || !data.data) {
              copySkuStatus.hidden = false;
              copySkuStatus.textContent = data && data.message ? data.message : 'Could not load that SKU.';
              copySkuStatus.className = 'hint error';
              return;
            }
            applyDataFiltered(data.data);
            copySkuStatus.hidden = false;
            copySkuStatus.textContent = 'Copied fields; SKU and photos left blank.';
            copySkuStatus.className = 'hint ok';
          });
        };
        if (copySkuButton && copySkuInput) {
          copySkuButton.addEventListener('click', function () {
            openCopyModal();
          });
          copySkuInput.addEventListener('keypress', function (evt) {
            if (evt.key === 'Enter') {
              evt.preventDefault();
              openCopyModal();
            }
          });
        }
        var openFindModal = function () {
          if (!findSkuModal) return;
          findSkuModal.hidden = false;
          if (findSkuQuery) {
            findSkuQuery.value = '';
            findSkuQuery.focus();
          }
          if (findSkuResults) findSkuResults.innerHTML = '';
        };
        var closeFindModal = function () {
          if (findSkuModal) findSkuModal.hidden = true;
        };
        if (findSkuButton) {
          findSkuButton.addEventListener('click', openFindModal);
        }
        if (findSkuBackdrop) findSkuBackdrop.addEventListener('click', closeFindModal);
        if (findSkuClose) findSkuClose.addEventListener('click', closeFindModal);
        if (findSkuQuery && findSkuResults) {
          var findTimer = null;
          findSkuQuery.addEventListener('input', function () {
            clearTimeout(findTimer);
            var q = findSkuQuery.value.trim();
            if (q.length < 2) {
              findSkuResults.innerHTML = '';
              return;
            }
            findTimer = setTimeout(function () {
              fetch('suggestions.php?q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (items) {
                  findSkuResults.innerHTML = '';
                  items.slice(0, 15).forEach(function (item) {
                    var li = document.createElement('li');
                    li.textContent = item.value || item.label || '';
                    li.addEventListener('click', function () {
                      if (copySkuInput) copySkuInput.value = li.textContent;
                      closeFindModal();
                      openCopyModal();
                    });
                    findSkuResults.appendChild(li);
                  });
                })
                .catch(function () {
                  findSkuResults.innerHTML = '<li>Could not load suggestions.</li>';
                });
            }, 200);
          });
        }
        if (copySkuPrefill) {
          if (copySkuInput) copySkuInput.value = copySkuPrefill;
          openCopyModal();
        }
        var applyDraft = function (raw) {
          if (!raw) return;
          try {
            var draft = JSON.parse(raw);
            applyDraftObject(draft);
          } catch (e) {}
        };
        if (clearDraft && clearDraft.value === '1') {
          try {
            var existingDraft = localStorage.getItem(draftKey);
            if (existingDraft) {
              localStorage.setItem(backupKey, existingDraft);
            }
            localStorage.removeItem(draftKey);
          } catch (e) {}
        }

        var applyRequiredState = function (name, missing) {
          var el = form.querySelector('[name="' + name + '"]');
          if (el) {
            el.classList.toggle('required-missing', missing);
            el.setAttribute('aria-invalid', missing ? 'true' : 'false');
          }
        };

        var progressWrap = document.getElementById('intake-progress');
        var progressLabel = document.getElementById('intake-progress-label');
        var progressFill = document.getElementById('intake-progress-fill');
        var requiredFields = [
          { name: 'sku', label: 'SKU' },
          { name: 'what_is_it', label: 'What is it?' }
        ];
        var updateRequiredProgress = function () {
          var complete = 0;
          var missingLabels = [];
          requiredFields.forEach(function (required) {
            var field = form.querySelector('[name="' + required.name + '"]');
            var value = field ? (field.value || '').trim() : '';
            var missing = value === '';
            applyRequiredState(required.name, missing);
            if (missing) missingLabels.push(required.label);
            else complete += 1;
          });
          var percent = Math.round((complete / requiredFields.length) * 100);
          if (progressWrap) progressWrap.hidden = false;
          if (progressLabel) {
            progressLabel.textContent = missingLabels.length
              ? complete + ' of ' + requiredFields.length + ' required fields complete — missing: ' + missingLabels.join(', ')
              : 'Required fields complete — ready to save';
          }
          if (progressFill) {
            progressFill.style.width = percent + '%';
            var track = progressFill.parentNode;
            if (track) track.setAttribute('aria-valuenow', String(percent));
          }
        };
        form.addEventListener('input', updateRequiredProgress);
        form.addEventListener('change', updateRequiredProgress);
        updateRequiredProgress();

        // Offer restore if we have a backup and the form is mostly empty.
        var formLooksEmpty = function () {
          var fields = form.querySelectorAll('input[name], select[name], textarea[name]');
          for (var i = 0; i < fields.length; i++) {
            var f = fields[i];
            if (f.type === 'radio' || f.type === 'checkbox') {
              if (f.checked) return false;
              continue;
            }
            if (f.type === 'file') continue;
            if ((f.value || '').trim() !== '') return false;
          }
          return true;
        };
        if (restoreBtn) {
          try {
            var backupDraft = localStorage.getItem(backupKey);
            if (backupDraft && formLooksEmpty()) {
              restoreBtn.hidden = false;
              if (restoreHint) { restoreHint.hidden = false; }
              restoreBtn.addEventListener('click', function () {
                applyDraft(backupDraft);
                localStorage.setItem(draftKey, backupDraft);
                restoreBtn.hidden = true;
                if (restoreHint) { restoreHint.hidden = true; }
                showToast('Draft restored');
            });
            }
          } catch (e) {}
        }

        /* Restore a server-side autosave draft when a SKU is present.
           The June refactor dropped this fetch — the server-draft-banner
           element stayed in the markup but was never shown, so unsaved
           autosave work was silently lost on reload.  Re-add it: fetch the
           draft for the SKU, apply it to the form, and seed appState so a
           later edit merges with the restored draft instead of overwriting
           it with a single field. */
        var serverDraftBanner = document.getElementById('server-draft-banner');
        var fetchServerDraft = function () {
          if (!form || !serverDraftBanner) return;
          var skuInput = form.querySelector('[name="sku"]');
          var skuVal = (skuInput && skuInput.value || '').trim();
          if (!skuVal) return;
          fetch('autosave.php?sku=' + encodeURIComponent(skuVal))
            .then(function (resp) { return resp.json(); })
            .then(function (resp) {
              if (!resp || resp.ok !== true || !resp.has_draft || !resp.data) return;
              applyDraftObject(resp.data);
              var serialized = JSON.stringify(resp.data);
              try { localStorage.setItem(draftKey, serialized); } catch (e) {}
              if (window.updateState) {
                Object.keys(resp.data).forEach(function (name) {
                  if (name === 'sku_normalized') return;
                  window.updateState(name, resp.data[name]);
                });
              }
              serverDraftBanner.textContent = 'Restored server draft';
              serverDraftBanner.hidden = false;
            })
            .catch(function () {});
        };
        fetchServerDraft();

        var setCopyStatus = function (text, tone) {
          if (!copySkuStatus) return;
          copySkuStatus.hidden = false;
          copySkuStatus.textContent = text;
          copySkuStatus.classList.remove('ok', 'warn', 'err');
          if (tone) copySkuStatus.classList.add(tone);
        };
        form.addEventListener('submit', function (event) {
          var skuField = form.querySelector('[name="sku"]');
          var sku = ((skuField || {}).value || '').trim().toUpperCase();
          if (skuField) {
            skuField.value = sku;
          }
          var missingSku = sku === '';
          var whatVal = (whatInput && whatInput.value.trim()) || '';
          updateRequiredProgress();
          if (missingSku || whatVal === '') {
            event.preventDefault();
            if (errorEl) {
              errorEl.hidden = false;
            }
            if (whatError && whatVal === '') {
              whatError.hidden = false;
            }
            showToast('Fill SKU and "What is it?" before saving.');
            return;
          }
          try { localStorage.removeItem('intakeDraftV1'); } catch (e) {}
        });

        /* Ctrl/Cmd + Enter saves immediately. */
        form.addEventListener('keydown', function (e) {
          if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            var submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) { submitBtn.click(); } else { form.submit(); }
          }
        });

        /* SKU uppercase conversion — runs independently of the centralized
           auto-save engine.   Every keystroke normalises to upper case. */
        var skuInput = form.querySelector('[name="sku"]');
        if (skuInput) {
          skuInput.addEventListener('input', function () {
            var upper = (this.value || '').toUpperCase();
            if (this.value !== upper) {
              var pos = this.selectionStart;
              this.value = upper;
              if (typeof pos === 'number') {
                this.selectionStart = this.selectionEnd = pos;
              }
            }
            applyRequiredState('sku', false);
            if (errorEl) errorEl.hidden = true;
          });
        }
      }

      var photoInput = document.getElementById('sku-photo-input');
      var photoDropzone = document.getElementById('sku-photo-dropzone');
      var previewContainer = document.getElementById('sku-photo-preview');
      var previewList = document.getElementById('sku-photo-preview-list');
      var deleteForm = document.getElementById('photo-delete-form');
      var deleteInput = document.getElementById('delete-photo-id');
      var deleteSku = document.getElementById('delete-photo-sku');
      // Scope to the intake form: photo-delete-form's hidden input[name=sku]
      // precedes it in the DOM, so a global query would read the wrong field.
      var skuField = document.querySelector('#intake-form input[name="sku"]');
      var isUploading = false;
      var intakeForm = document.getElementById('intake-form');
      var submitButton = document.querySelector('button[type="submit"]');
      if (intakeForm) {
        intakeForm.addEventListener('submit', function (e) {
          if (isUploading) {
            e.preventDefault();
            pushUploadMessage('Please wait for photo uploads to complete before saving.', 'error');
          }
        });
      }
      var uploadMessages = document.getElementById('photo-upload-messages');
      var photoSelectionHint = document.getElementById('photo-selection-hint');
      var pushUploadMessage = function (text, type) {
        if (!uploadMessages) return;
        var div = document.createElement('div');
        div.className = 'msg ' + (type === 'error' ? 'err' : 'ok');
        div.textContent = text;
        uploadMessages.appendChild(div);
      };
      var MAX_DIM = 1600;
      var RESIZE_THRESHOLD = 2 * 1024 * 1024; // 2MB
      var TARGET_QUALITY = 0.82;
      var resizeIfNeeded = function (file, done) {
        if (!file || !file.type || !file.type.startsWith('image/') || file.size < RESIZE_THRESHOLD) {
          done(file);
          return;
        }
        var reader = new FileReader();
        reader.onload = function (e) {
          var img = new Image();
          img.onload = function () {
            var w = img.width;
            var h = img.height;
            var scale = Math.min(1, MAX_DIM / Math.max(w, h));
            if (scale >= 1) {
              done(file);
              return;
            }
            var canvas = document.createElement('canvas');
            canvas.width = Math.round(w * scale);
            canvas.height = Math.round(h * scale);
            var ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            canvas.toBlob(function (blob) {
              if (!blob) {
                done(file);
                return;
              }
              var resized = new File([blob], file.name.replace(/\.(png|webp|gif)$/i, '.jpg'), { type: 'image/jpeg', lastModified: Date.now() });
              done(resized);
            }, 'image/jpeg', TARGET_QUALITY);
          };
          img.onerror = function () { done(file); };
          img.src = e.target.result;
        };
        reader.onerror = function () { done(file); };
        reader.readAsDataURL(file);
      };
      var clearPreview = function () {
        photoQueue.forEach(function (entry) {
          URL.revokeObjectURL(entry.url);
        });
        photoQueue = [];
        if (previewList) {
          previewList.innerHTML = '';
        }
        if (previewContainer) {
          previewContainer.hidden = true;
        }
      };
      var formatSize = function (bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
      };
      var photoQueue = [];
      var renderPreview = function () {
        if (!previewContainer || !previewList) {
          return;
        }
        previewList.innerHTML = '';
        if (!photoQueue.length) {
          previewContainer.hidden = true;
          return;
        }
        photoQueue.forEach(function (entry, index) {
          var file = entry.file;
          var url = entry.url;
          var card = document.createElement('div');
          card.className = 'sku-photo-item is-preview';

          var link = document.createElement('a');
          link.className = 'sku-photo-link';
          link.href = url;
          link.target = '_blank';
          link.rel = 'noopener';
          link.title = 'Open ' + (file.name || 'photo') + ' in a new tab';

          var img = document.createElement('img');
          img.src = url;
          img.alt = file.name || 'Selected photo';

          var caption = document.createElement('span');
          var label = file.name || 'Photo';
          var size = Number.isFinite(file.size) ? ' • ' + formatSize(file.size) : '';
          caption.textContent = label + size;

          link.appendChild(img);
          card.appendChild(link);
          card.appendChild(caption);

          var deleteBtn = document.createElement('button');
          deleteBtn.type = 'button';
          deleteBtn.className = 'ghost danger';
          deleteBtn.textContent = 'Remove';
          deleteBtn.addEventListener('click', function () {
            URL.revokeObjectURL(url);
            photoQueue.splice(index, 1);
            syncInputFromQueue();
            renderPreview();
          });
          card.appendChild(deleteBtn);

          var progressBar = document.createElement('div');
          progressBar.className = 'sku-photo-progress';
          var progressInner = document.createElement('span');
          progressInner.dataset.photoIdx = String(index);
          progressInner.textContent = '0%';
          progressBar.appendChild(progressInner);
          card.appendChild(progressBar);

          previewList.appendChild(card);
        });
        previewContainer.hidden = previewList.children.length === 0;
      };
      var clearFileInput = function () {
        if (!photoInput) return;
        // Clear the file input so that photos uploaded via AJAX
        // do NOT also get re-submitted with the main form.
        if (window.DataTransfer) {
          photoInput.files = (new DataTransfer()).files;
        }
        // Fallback for environments without full DataTransfer support.
        try { photoInput.value = ''; } catch (_) {}
      };
      var syncInputFromQueue = function () {
        if (!photoInput || !window.DataTransfer) {
          clearFileInput();
          return;
        }
        var dt = new DataTransfer();
        photoQueue.forEach(function (entry) {
          dt.items.add(entry.file);
        });
        photoInput.files = dt.files;
      };
      if (photoInput) {
        photoInput.addEventListener('change', function () {
          var files = photoInput.files || [];
          var remaining = files.length;
          if (photoSelectionHint) {
            photoSelectionHint.hidden = !files.length;
            photoSelectionHint.textContent = files.length
              ? files.length + ' photo' + (files.length === 1 ? '' : 's') + ' selected. Preparing preview…'
              : '';
          }
          if (!remaining) return;
          Array.prototype.forEach.call(files, function (file) {
            resizeIfNeeded(file, function (processed) {
              if (!processed || !processed.type || !processed.type.startsWith('image/')) {
                return;
              }
              var url = URL.createObjectURL(processed);
              photoQueue.push({ file: processed, url: url, progress: 0 });
              remaining -= 1;
              if (remaining === 0) {
                syncInputFromQueue();
                renderPreview();
                if (photoSelectionHint) {
                  photoSelectionHint.hidden = false;
                  photoSelectionHint.textContent = photoQueue.length + ' photo' + (photoQueue.length === 1 ? '' : 's') + ' ready. Uploading…';
                }
                processQueue();
              }
            });
          });
        });
      }
      var updateProgress = function (idx, percent) {
        var bar = previewList.querySelector('.sku-photo-progress span[data-photo-idx="' + idx + '"]');
        if (bar) {
          var pct = Math.min(100, Math.max(0, percent));
          bar.style.width = pct + '%';
          bar.textContent = Math.round(pct) + '%';
        }
      };
      var CHUNK_SIZE = 512 * 1024; // 512KB to stay under server limits
      var uploadFileChunked = function (entry, fileIndex, onDone, onError) {
        var file = entry.file;
        var uploadId = entry.uploadId || (entry.uploadId = (Date.now() + '-' + Math.random().toString(16).slice(2)));
        var sku = (skuField && skuField.value || '').trim();
        if (!sku) {
          onError('Enter a SKU before uploading photos so they can attach.');
          return;
        }
        var chunkTotal = Math.ceil(file.size / CHUNK_SIZE);
        var sendChunk = function (chunkIndex) {
          var start = chunkIndex * CHUNK_SIZE;
          var end = Math.min(file.size, start + CHUNK_SIZE);
          var blob = file.slice(start, end);
          var fd = new FormData();
          fd.append('sku', sku);
          fd.append('upload_id', uploadId);
          fd.append('chunk_index', String(chunkIndex));
          fd.append('chunk_total', String(chunkTotal));
          fd.append('total_size', String(file.size));
          fd.append('original_name', file.name || 'photo');
          fd.append('mime_type', file.type || 'application/octet-stream');
          fd.append('csrf_token', window.CSRF_TOKEN);
          fd.append('chunk', blob);
          var xhr = new XMLHttpRequest();
          xhr.open('POST', 'upload_photo_chunk.php');
          xhr.upload.onprogress = function (evt) {
            if (evt.lengthComputable) {
              var pct = ((start + evt.loaded) / file.size) * 100;
              updateProgress(fileIndex, pct);
            }
          };
          xhr.onreadystatechange = function () {
            if (xhr.readyState === 4) {
              var ok = xhr.status >= 200 && xhr.status < 300;
              if (!ok) {
                onError('Status ' + xhr.status);
                return;
              }
              var resp = {};
              try {
                resp = JSON.parse(xhr.responseText || '{}');
              } catch (e) {
                onError('Bad JSON response');
                return;
              }
              if (resp.status !== 'ok') {
                onError(resp.message || 'Upload failed');
                return;
              }
              if (chunkIndex + 1 < chunkTotal) {
                sendChunk(chunkIndex + 1);
              } else {
                updateProgress(fileIndex, 100);
                pushUploadMessage((file.name || 'photo') + ' uploaded', 'ok');
                onDone(resp);
              }
            }
          };
          xhr.onerror = function () {
            onError('Network error');
          };
          xhr.send(fd);
        };
        sendChunk(0);
      };

      var processQueue = function () {
        if (isUploading || !photoQueue.length) return;
        var sku = (skuField && skuField.value || '').trim();
        if (!sku) {
          alert('Enter a SKU before uploading photos so they can attach.');
          return;
        }
        isUploading = true;
        if (submitButton) {
          submitButton.disabled = true;
          submitButton.textContent = 'Uploading photos...';
        }
        var escapeHtml = function (s) {
          if (typeof s !== 'string') return '';
          return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        };

        var appendUploadedPhoto = function (photoId, fileName) {
          var grid = document.querySelector('.sku-photos .sku-photo-grid');
          var section = document.getElementById('sku-photos');
          if (!section || !photoId) return;
          if (!grid) {
            grid = document.createElement('div');
            grid.className = 'sku-photo-grid';
            var uploadMsgs = document.getElementById('photo-upload-messages');
            if (uploadMsgs && uploadMsgs.nextElementSibling) {
              section.insertBefore(grid, uploadMsgs.nextElementSibling);
            } else {
              section.appendChild(grid);
            }
          }
          var sku = (skuField && skuField.value || '').trim() || 'SKU';
          var item = document.createElement('div');
          item.className = 'sku-photo-item';
          item.draggable = true;
          item.setAttribute('data-photo-id', photoId);
          item.innerHTML = '<a class="sku-photo-link" href="photo.php?id=' + photoId + '" target="_blank" rel="noopener" title="Open photo in new tab"><span class="sku-photo-badge">SKU ' + escapeHtml(sku) + '</span><img src="photo.php?id=' + photoId + '&amp;thumb=1" data-full-src="photo.php?id=' + photoId + '" alt="Photo for SKU ' + escapeHtml(sku) + ' — ' + escapeHtml(fileName) + '" loading="lazy"></a><div class="sku-photo-meta"><span class="sku-photo-name">' + escapeHtml(fileName) + '</span></div><div class="sku-photo-actions"><button type="button" class="ghost danger js-delete-photo" data-photo-id="' + photoId + '">Delete</button><button type="button" class="ghost js-set-thumb" data-photo-id="' + photoId + '" data-photo-sku="' + escapeHtml(sku) + '">Set thumbnail</button></div>';
          grid.appendChild(item);
        };

        var idx = 0;
        var total = photoQueue.length;
        var next = function () {
          if (idx >= total) {
            isUploading = false;
            if (submitButton) {
              submitButton.disabled = false;
              submitButton.textContent = 'Save Intake Item';
            }
            photoQueue.length = 0;
            syncInputFromQueue();
            clearFileInput();
            renderPreview();
            if (photoSelectionHint) {
              photoSelectionHint.hidden = true;
              photoSelectionHint.textContent = '';
            }
            return;
          }
          var entry = photoQueue[idx];
          uploadFileChunked(entry, idx, function (resp) {
            if (resp && resp.id) {
              appendUploadedPhoto(resp.id, entry.file.name || 'photo');
            }
            idx += 1;
            next();
          }, function (msg) {
            isUploading = false;
            if (submitButton) {
              submitButton.disabled = false;
              submitButton.textContent = 'Save Intake Item';
            }
            if (photoSelectionHint) {
              photoSelectionHint.hidden = false;
              photoSelectionHint.textContent = 'Upload stopped. Fix the problem and try again.';
            }
            pushUploadMessage('Failed: ' + (entry.file.name || 'photo') + ' — ' + msg, 'error');
          });
        };
        next();
      };
      var addFilesToQueue = function (fileList) {
        var files = Array.prototype.slice.call(fileList || []);
        if (!files.length) return;
        var remaining = files.length;
        files.forEach(function (file) {
          resizeIfNeeded(file, function (processed) {
            if (!processed || !processed.type || !processed.type.startsWith('image/')) {
              remaining -= 1;
              return;
            }
            var url = URL.createObjectURL(processed);
            photoQueue.push({ file: processed, url: url, progress: 0 });
            remaining -= 1;
            if (remaining === 0) {
              syncInputFromQueue();
              renderPreview();
              processQueue();
            }
          });
        });
      };

      /* ── Photo auto-attach: when a SKU is typed and photos are still
         queued (added before the SKU existed), upload them right away. ── */
      if (skuField) {
        skuField.addEventListener('input', function () {
          if (!isUploading && photoQueue.length > 0 && (skuField.value || '').trim() !== '') {
            processQueue();
          }
        });
      }
      if (photoDropzone) {
        var dz = photoDropzone;
        photoDropzone.addEventListener('keydown', function (evt) {
          if ((evt.key === 'Enter' || evt.key === ' ') && photoInput) {
            evt.preventDefault();
            photoInput.click();
          }
        });
        ['dragenter', 'dragover'].forEach(function (evtName) {
          dz.addEventListener(evtName, function (evt) {
            evt.preventDefault();
            dz.classList.add('is-hover');
            if (evt.dataTransfer) {
              evt.dataTransfer.dropEffect = evt.dataTransfer.files && evt.dataTransfer.files.length > 0 ? 'copy' : 'move';
            }
          });
        });
        ['dragleave', 'drop'].forEach(function (evtName) {
          dz.addEventListener(evtName, function (evt) {
            evt.preventDefault();
            dz.classList.remove('is-hover');
          });
        });
        dz.addEventListener('drop', function (evt) {
          if (evt.dataTransfer && evt.dataTransfer.files && evt.dataTransfer.files.length > 0) {
            addFilesToQueue(evt.dataTransfer.files);
            return;
          }
          var photoId = evt.dataTransfer && evt.dataTransfer.getData('text/plain');
          if (photoId && confirm('Delete this photo?')) {
            if (deleteInput) deleteInput.value = photoId;
            if (skuField && deleteSku) deleteSku.value = skuField.value;
            if (deleteForm) deleteForm.submit();
          }
        });
        dz.addEventListener('paste', function (evt) {
          if (!evt.clipboardData) return;
          var items = evt.clipboardData.files;
          addFilesToQueue(items);
        });
      }
      // Drag-and-drop reordering + drag-out-to-delete for saved photos
      (function () {
        var dragSrc = null;
        var dragPhotoId = null;
        var droppedInGrid = false;
        var photosSection = document.getElementById('sku-photos');

        document.addEventListener('dragstart', function (e) {
          var item = e.target.closest('.sku-photo-item');
          if (!item) return;
          if (!item.closest('.sku-photos')) return;
          if (item.classList.contains('is-preview')) return;
          if (e.target.tagName === 'A' || e.target.tagName === 'IMG' || e.target.closest('button')) return;
          dragSrc = item;
          dragPhotoId = item.getAttribute('data-photo-id');
          droppedInGrid = false;
          e.dataTransfer.effectAllowed = 'move';
          e.dataTransfer.setData('text/plain', dragPhotoId || '');
          item.classList.add('is-dragging');
        });

        document.addEventListener('dragend', function (e) {
          var item = e.target.closest('.sku-photo-item');
          if (item) {
            item.classList.remove('is-dragging');
          }
          if (dragSrc && !droppedInGrid && dragPhotoId) {
            if (confirm('Delete this photo?')) {
              if (deleteInput) deleteInput.value = dragPhotoId;
              if (skuField && deleteSku) deleteSku.value = skuField.value;
              if (deleteForm) deleteForm.submit();
            }
          }
          dragSrc = null;
          dragPhotoId = null;
        });

        function getGrid() {
          return photosSection ? photosSection.querySelector('.sku-photo-grid') : null;
        }

        if (photosSection) {
          photosSection.addEventListener('dragover', function (e) {
            var grid = getGrid();
            if (!grid) return;
            var item = e.target.closest('.sku-photo-item');
            if (item && grid.contains(item)) {
              e.preventDefault();
              e.dataTransfer.dropEffect = 'move';
              droppedInGrid = true;
              var rect = item.getBoundingClientRect();
              var midY = rect.top + rect.height / 2;
              var items = grid.querySelectorAll('.sku-photo-item');
              Array.prototype.forEach.call(items, function (el) {
                el.classList.remove('drop-before', 'drop-after');
              });
              if (e.clientY < midY) {
                item.classList.add('drop-before');
              } else {
                item.classList.add('drop-after');
              }
            } else if (e.dataTransfer.types && Array.prototype.indexOf.call(e.dataTransfer.types, 'Files') !== -1) {
              e.preventDefault();
              e.dataTransfer.dropEffect = 'copy';
              if (grid) grid.classList.add('is-drag-over');
            }
          });

          photosSection.addEventListener('dragleave', function (e) {
            var grid = getGrid();
            if (!grid) return;
            if (grid.contains(e.relatedTarget)) return;
            var items = grid.querySelectorAll('.sku-photo-item.drop-before, .sku-photo-item.drop-after');
            Array.prototype.forEach.call(items, function (el) {
              el.classList.remove('drop-before', 'drop-after');
            });
            grid.classList.remove('is-drag-over');
          });

          photosSection.addEventListener('drop', function (e) {
            var grid = getGrid();
            if (!grid) return;
            e.preventDefault();
            grid.classList.remove('is-drag-over');
            var items = grid.querySelectorAll('.sku-photo-item.drop-before, .sku-photo-item.drop-after');
            Array.prototype.forEach.call(items, function (el) {
              el.classList.remove('drop-before', 'drop-after');
            });

            var files = e.dataTransfer.files;
            if (files && files.length > 0) {
              addFilesToQueue(files);
              return;
            }

            droppedInGrid = true;
            var target = e.target.closest('.sku-photo-item');
            if (target && grid.contains(target) && dragSrc && target !== dragSrc) {
              var rect = target.getBoundingClientRect();
              var midY = rect.top + rect.height / 2;
              if (e.clientY < midY) {
                target.parentNode.insertBefore(dragSrc, target);
              } else {
                target.parentNode.insertBefore(dragSrc, target.nextSibling);
              }
              savePhotoOrder();
            }
          });
        }

        function savePhotoOrder() {
          var grid = getGrid();
          if (!grid) return;
          var ids = [];
          var items = grid.querySelectorAll('.sku-photo-item');
          Array.prototype.forEach.call(items, function (item) {
            var pid = item.getAttribute('data-photo-id');
            if (pid) ids.push(pid);
          });
          if (ids.length < 2) return;
          fetch('reorder_photos.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'photo_ids=' + encodeURIComponent(ids.join(',')) + '&csrf_token=' + encodeURIComponent(window.CSRF_TOKEN)
          });
        }
      })();

      // Compatible OS quick-select buttons
      var compatOsInput = document.getElementById('compatible-os-input');
      var osBtns = document.querySelectorAll('.compat-os-btn');
      if (compatOsInput && osBtns.length) {
        osBtns.forEach(function (btn) {
          btn.addEventListener('click', function () {
            var val = btn.getAttribute('data-os') || '';
            // Toggle: clicking the active one clears it
            if (compatOsInput.value === val) {
              compatOsInput.value = '';
              btn.classList.remove('is-active');
            } else {
              compatOsInput.value = val;
              osBtns.forEach(function (b) { b.classList.remove('is-active'); });
              btn.classList.add('is-active');
            }
            compatOsInput.dispatchEvent(new Event('input', { bubbles: true }));
          });
        });
        // Keep buttons in sync if compatible OS field is typed into manually
        compatOsInput.addEventListener('input', function () {
          var val = compatOsInput.value.trim();
          osBtns.forEach(function (b) {
            b.classList.toggle('is-active', b.getAttribute('data-os') === val);
          });
        });
      }
      /* Upgrade visible saved-photo thumbnails to full resolution only after
         the fast cached thumbnails have rendered. This keeps the intake form
         responsive without changing the full-resolution link behavior. */
      document.querySelectorAll('.sku-photos img[data-full-src]').forEach(function (img) {
        var fullSrc = img.getAttribute('data-full-src');
        if (!fullSrc) return;
        var fullImage = new Image();
        fullImage.onload = function () {
          if (img.isConnected) img.src = fullSrc;
        };
        fullImage.src = fullSrc;
      });

      document.addEventListener('click', function (e) {
        var delBtn = e.target.closest('.js-delete-photo');
        if (delBtn) {
          var pid = delBtn.getAttribute('data-photo-id');
          if (!pid) return;
          if (!confirm('Delete this photo?')) return;
          if (deleteInput) deleteInput.value = pid;
          if (skuField) deleteSku.value = skuField.value;
          if (deleteForm) deleteForm.submit();
          return;
        }
        var thumbBtn = e.target.closest('.js-set-thumb');
        if (!thumbBtn) return;
        var id = thumbBtn.getAttribute('data-photo-id');
        var skuVal = thumbBtn.getAttribute('data-photo-sku');
        if (!id || !skuVal) return;
        fetch('set_thumbnail.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'photo_id=' + encodeURIComponent(id) + '&sku=' + encodeURIComponent(skuVal) + '&csrf_token=' + encodeURIComponent(window.CSRF_TOKEN)
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.ok) {
              var thumbImg = document.querySelector('td.thumb-cell a.thumb img');
              if (thumbImg) {
                var newSrc = 'photo.php?id=' + id;
                thumbImg.src = newSrc;
                thumbImg.parentNode.href = newSrc;
              }
            } else {
              alert('Set thumbnail failed: ' + (data.error || 'error'));
            }
          })
          .catch(function () { alert('Set thumbnail failed.'); });
      });

      /* ── Sequential individual photo downloads (replaces ZIP) ── */
      var downloadAllBtn = document.getElementById('download-all-btn');
      if (downloadAllBtn) {
        downloadAllBtn.addEventListener('click', function () {
          var items = document.querySelectorAll('.sku-photo-item[data-photo-id]');
          var ids = [];
          items.forEach(function (item) {
            var id = item.getAttribute('data-photo-id');
            if (id) ids.push(id);
          });
          if (!ids.length) return;
          var total = ids.length;
          var origText = downloadAllBtn.textContent;
          downloadAllBtn.textContent = 'Downloading 0 / ' + total + '...';
          downloadAllBtn.disabled = true;
          var completed = 0;
          var downloadOne = function (idx) {
            if (idx >= ids.length) {
              setTimeout(function () {
                downloadAllBtn.textContent = origText;
                downloadAllBtn.disabled = false;
              }, 800);
              return;
            }
            fetch('photo.php?id=' + encodeURIComponent(ids[idx]) + '&download=1')
              .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                var disposition = r.headers.get('Content-Disposition') || '';
                var match = disposition.match(/filename\*?=(?:UTF-8'')?["']?([^;"'\s]+)/i);
                var filename = match ? match[1] : ('photo_' + ids[idx] + '.png');
                return r.blob().then(function (blob) { return { blob: blob, filename: filename }; });
              })
              .then(function (result) {
                var url = URL.createObjectURL(result.blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = result.filename;
                a.style.display = 'none';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                setTimeout(function () { URL.revokeObjectURL(url); }, 5000);
                completed++;
                downloadAllBtn.textContent = 'Downloading ' + completed + ' / ' + total + '...';
                setTimeout(function () { downloadOne(idx + 1); }, 300);
              })
              .catch(function () {
                completed++;
                downloadAllBtn.textContent = 'Downloading ' + completed + ' / ' + total + '...';
                setTimeout(function () { downloadOne(idx + 1); }, 300);
              });
          };
          downloadOne(0);
        });
      }

      window.addEventListener('beforeunload', clearPreview);

      var checkbox = document.getElementById('print-pink');
      if (checkbox) {
        var storageKey = 'printPink';
        var apply = function (enabled) {
          document.body.classList.toggle('print-pink', enabled);
        };
        if (localStorage.getItem(storageKey) === '1') {
          checkbox.checked = true;
          apply(true);
        }
        checkbox.addEventListener('change', function () {
          apply(checkbox.checked);
          localStorage.setItem(storageKey, checkbox.checked ? '1' : '0');
        });
      }

      // Mobile mode toggle
      var mobileToggle = document.getElementById('mobile-mode-toggle');
      var intakeSheet = document.querySelector('.sheet.intake');
      if (mobileToggle && intakeSheet) {
        var mobileStorageKey = 'intakeMobileMode';
        var applyMobile = function (enabled) {
          intakeSheet.classList.toggle('mobile-mode', enabled);
          mobileToggle.textContent = enabled ? 'Desktop' : 'Mobile';
        };
        if (localStorage.getItem(mobileStorageKey) === '1') {
          applyMobile(true);
        }
        mobileToggle.addEventListener('click', function () {
          var enabled = !intakeSheet.classList.contains('mobile-mode');
          applyMobile(enabled);
          localStorage.setItem(mobileStorageKey, enabled ? '1' : '0');
        });
      }

      // Removed auto-refresh to avoid losing in-progress intake entries.

      var toastElement = document.getElementById('save-toast');
      var toastTimer = null;
      var showToast = function (msg) {
        if (!toastElement || !msg) return;
        toastElement.textContent = msg;
        toastElement.classList.add('toast-visible');
        if (toastTimer) {
          clearTimeout(toastTimer);
        }
        toastTimer = setTimeout(function () {
          toastElement.classList.remove('toast-visible');
        }, 4200);
      };

      if (toastElement && toastElement.dataset.active === '1') {
        var toastMessage = (toastElement.dataset.message || '').trim();
        if (toastMessage !== '') {
          toastElement.dataset.active = '0';
          showToast(toastMessage);
        }
      }

      // Keep screen view at full readable size; print layout is handled by CSS.
    })();
  </script>

  <script>
  (function () {
    var qrInstance = null;
    var qrWrap = document.getElementById('intake-qr-wrap');
    var qrRender = document.getElementById('intake-qr-render');
    // Scope to the intake form: photo-delete-form's hidden input[name=sku]
    // precedes it in the DOM, so a global query would read the wrong field.
    var skuInput = document.querySelector('#intake-form input[name="sku"]');
    var qrLibPromise = null;

    var loadQrLibrary = function () {
      if (window.QRCode) {
        return Promise.resolve();
      }
      if (qrLibPromise) {
        return qrLibPromise;
      }
      qrLibPromise = new Promise(function (resolve, reject) {
        var existing = document.querySelector('script[data-qrcode-lib="1"]');
        if (existing) {
          existing.addEventListener('load', function () { resolve(); }, { once: true });
          existing.addEventListener('error', function () { reject(new Error('QR library failed to load')); }, { once: true });
          return;
        }
        var script = document.createElement('script');
        script.src = 'assets/qrcode.min.js?v=<?php echo getAssetVersion(); ?>';
        script.dataset.qrcodeLib = '1';
        script.onload = function () { resolve(); };
        script.onerror = function () { reject(new Error('QR library failed to load')); };
        document.body.appendChild(script);
      });
      return qrLibPromise;
    };

    var updateQrCode = function () {
      if (!qrRender || !qrWrap) return;
      var sku = skuInput ? skuInput.value.trim().toUpperCase() : '';
      if (!sku) {
        qrWrap.hidden = true;
        return;
      }
      if (typeof QRCode === 'undefined') {
        qrWrap.hidden = true;
        loadQrLibrary().then(updateQrCode).catch(function () {
          qrWrap.hidden = true;
        });
        return;
      }
      var isHttps = window.location.protocol === 'https:';
      var host = window.location.host;
      var url = (isHttps ? 'https' : 'http') + '://' + host + '/intake.php?sku=' + encodeURIComponent(sku);
      qrRender.innerHTML = '';
      qrInstance = null;
      try {
        qrInstance = new QRCode(qrRender, {
          text: url,
          width: 90,
          height: 90,
          colorDark: '#0f172a',
          colorLight: '#ffffff',
          correctLevel: QRCode.CorrectLevel.H
        });
        qrWrap.hidden = false;
      } catch (e) {}
    };

    if (skuInput) {
      skuInput.addEventListener('input', updateQrCode);
    }
    updateQrCode();
  })();
  </script>

  <div class="modal" id="find-sku-modal" hidden>
    <div class="modal-backdrop" id="find-sku-backdrop"></div>
    <div class="modal-content">
      <header class="modal-header">
        <h3>Find a SKU to copy</h3>
        <button type="button" class="ghost" id="find-sku-close">×</button>
      </header>
      <div class="modal-body">
        <input type="text" id="find-sku-query" placeholder="Type SKU or keywords">
        <ul id="find-sku-results" class="find-sku-list"></ul>
      </div>
    </div>
  </div>
<?php if (!$isPartial): ?>
  </div> <!-- /content-area -->
  </div> <!-- /layout-wrapper -->
</body>
</html>
<?php endif; ?>
