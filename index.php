<?php
require_once __DIR__ . '/config.php';
checkMaintenance();
ensureStorageWritable();

// Simple intake sheet app backed by SQLite



const DB_DIR = __DIR__ . '/data';
const DB_PATH = __DIR__ . '/data/intake.sqlite';
const LOOKUP_LOG_DIR = __DIR__ . '/logs';
const LOOKUP_LOG_PATH = LOOKUP_LOG_DIR . '/lookup.csv';
const CLEAR_DRAFT_PARAM = 'clear_draft';
const PHOTO_UPLOAD_DIR = DB_DIR . '/sku_photos';
const MAX_SKU_PHOTOS_PER_UPLOAD = 100;
const MAX_SKU_PHOTO_BYTES = 50 * 1024 * 1024;
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

$pdo = new PDO('sqlite:' . DB_PATH, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

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
    notes TEXT
);
SQL);
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
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_sku_photos_sku_normalized ON sku_photos (sku_normalized)");

$columns = $pdo->query("PRAGMA table_info(intake_items)")->fetchAll(PDO::FETCH_ASSOC);
$columnNames = array_map(static fn(array $column): string => (string)$column['name'], $columns);
if (!in_array('sku_normalized', $columnNames, true)) {
    $pdo->exec('ALTER TABLE intake_items ADD COLUMN sku_normalized TEXT');
}
if (!in_array('os', $columnNames, true)) {
    $pdo->exec('ALTER TABLE intake_items ADD COLUMN os TEXT');
}
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_intake_items_sku_normalized ON intake_items (sku_normalized)");
$pdo->exec("UPDATE intake_items SET sku_normalized = UPPER(TRIM(COALESCE(sku, ''))) WHERE sku_normalized IS NULL OR sku_normalized = ''");

function normalizeSku(string $sku): string
{
    return strtoupper(trim($sku));
}

function iniBytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $last = strtolower(substr($value, -1));
    $num = (float)$value;
    switch ($last) {
        case 'g':
            $num *= 1024;
            // no break
        case 'm':
            $num *= 1024;
            // no break
        case 'k':
            $num *= 1024;
    }
    return (int)$num;
}

function humanBytes(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

function normalizeUploadedFiles(array $uploaded): array
{
    if (!isset($uploaded['name']) || !is_array($uploaded['name'])) {
        return [];
    }
    $files = [];
    foreach ($uploaded['name'] as $index => $name) {
        $files[] = [
            'name' => (string)$name,
            'type' => (string)($uploaded['type'][$index] ?? ''),
            'tmp_name' => (string)($uploaded['tmp_name'][$index] ?? ''),
            'error' => (int)($uploaded['error'][$index] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($uploaded['size'][$index] ?? 0),
        ];
    }
    return $files;
}

function sanitizeFilename(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'photo';
    }
    $clean = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
    return trim((string)$clean, '._-') ?: 'photo';
}

function normalizedSkuDirectory(string $skuNormalized): string
{
    $dir = preg_replace('/[^A-Z0-9_-]+/', '_', $skuNormalized);
    return trim((string)$dir, '_') ?: 'UNASSIGNED';
}

function loadSkuPhotos(PDO $pdo, string $skuNormalized): array
{
    if ($skuNormalized === '') {
        return [];
    }
    $stmt = $pdo->prepare('SELECT id, original_name, mime_type, file_size, created_at FROM sku_photos WHERE sku_normalized = :sku_normalized ORDER BY id DESC');
    $stmt->execute(['sku_normalized' => $skuNormalized]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    return ['Intake', 'Description', 'Tested', 'Listed', 'SOLD'];
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
$saveMode = trim($_GET['save_mode'] ?? '');
$errors = [];
$photoWarnings = [];
$statusOptions = statusOptions();
$lookupSku = trim($_GET['sku'] ?? '');
$lookupSkuNormalized = normalizeSku($lookupSku);
$lookupStatus = trim($_GET['status'] ?? '');
if ($lookupStatus !== '' && !in_array($lookupStatus, $statusOptions, true)) {
    $lookupStatus = '';
}
$bulkErrors = [];
$bulkMessage = '';
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

    if (isset($_POST['bulk_update'])) {
        $bulkStatus = trim($_POST['bulk_status'] ?? '');
        $bulkIds = array_values(array_unique(array_map('intval', (array)($_POST['bulk_ids'] ?? []))));
        $bulkIds = array_filter($bulkIds, static fn ($id): bool => $id > 0);
        if ($bulkStatus === '' || !in_array($bulkStatus, $statusOptions, true)) {
            $bulkErrors[] = 'Please pick a valid status for the bulk update.';
        }
        if (!$bulkIds) {
            $bulkErrors[] = 'Select at least one SKU from the table.';
        }
        if (!$bulkErrors) {
            $placeholders = implode(',', array_fill(0, count($bulkIds), '?'));
            $stmt = $pdo->prepare("UPDATE intake_items SET status = ?, updated_at = datetime('now') WHERE id IN ($placeholders)");
            $params = array_merge([$bulkStatus], $bulkIds);
            $stmt->execute($params);
            $bulkMessage = 'Updated ' . count($bulkIds) . ' SKU' . (count($bulkIds) === 1 ? '' : 's') . ' to ' . $bulkStatus . '.';
        }
        $saved = false;
        $errors = [];
    } else {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
        $sku = trim($_POST['sku'] ?? '');
        $data = [
            'id' => $id,
            'sku' => $sku,
            'sku_normalized' => normalizeSku($sku),
            'status' => trim($_POST['status'] ?? ''),
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
            'battery_health' => trim($_POST['battery_health'] ?? ''),
            'graphics_card' => trim($_POST['graphics_card'] ?? ''),
            'screen_resolution' => trim($_POST['screen_resolution'] ?? ''),
            'where_it_goes' => trim($_POST['where_it_goes'] ?? ''),
            'ebay_status' => trim($_POST['ebay_status'] ?? ''),
            'ebay_price' => $_POST['ebay_price'] !== '' ? (float)$_POST['ebay_price'] : null,
            'dispotech_price' => $_POST['dispotech_price'] !== '' ? (float)$_POST['dispotech_price'] : null,
            'in_ebay_room' => trim($_POST['in_ebay_room'] ?? ''),
            'what_box' => trim($_POST['what_box'] ?? ''),
            'notes' => trim($_POST['notes'] ?? ''),
        ];
        $pendingPhotoUploads = [];

        $uploadedPhotos = normalizeUploadedFiles((array)($_FILES['sku_photos'] ?? []));
        if ($uploadedPhotos) {
            if (count($uploadedPhotos) > MAX_SKU_PHOTOS_PER_UPLOAD) {
                $photoWarnings[] = 'You can upload up to ' . MAX_SKU_PHOTOS_PER_UPLOAD . ' photos at once; extra files were ignored.';
                $uploadedPhotos = array_slice($uploadedPhotos, 0, MAX_SKU_PHOTOS_PER_UPLOAD);
            }
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
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
                $mimeType = (string)finfo_file($finfo, (string)$upload['tmp_name']);
                $extension = ALLOWED_PHOTO_MIME_TYPES[$mimeType] ?? null;
                if ($extension === null) {
                    $photoWarnings[] = $originalDisplayName . ' is not JPG/PNG/WEBP/GIF and was skipped.';
                    continue;
                }
                $pendingPhotoUploads[] = [
                    'tmp_name' => (string)$upload['tmp_name'],
                    'mime_type' => $mimeType,
                    'extension' => $extension,
                    'original_name' => sanitizeFilename($originalDisplayName),
                    'file_size' => (int)$upload['size'],
                ];
            }
            finfo_close($finfo);
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
    where_it_goes = :where_it_goes,
    ebay_status = :ebay_status,
    ebay_price = :ebay_price,
    dispotech_price = :dispotech_price,
    in_ebay_room = :in_ebay_room,
    what_box = :what_box,
    notes = :notes,
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
    graphics_card, screen_resolution, where_it_goes,
    ebay_status, ebay_price, dispotech_price, in_ebay_room,
    what_box, notes, updated_at
) VALUES (
    :sku, :sku_normalized, :status, :what_is_it, :date_received, :source,
    :functional, :condition, :is_square, :care_if_square,
    :cords_adapters, :keep_items_together, :picture_taken,
    :power_on, :brand_model, :ram, :ssd_gb, :cpu, :os, :battery_health,
    :graphics_card, :screen_resolution, :where_it_goes,
    :ebay_status, :ebay_price, :dispotech_price, :in_ebay_room,
    :what_box, :notes, datetime('now')
);
SQL);
                    $insertData = $data;
                    unset($insertData['id']);
                    $stmt->execute($insertData);
                    $saveMode = 'created';
                }
            }

            if ($pendingPhotoUploads) {
                $skuPhotoDir = PHOTO_UPLOAD_DIR . '/' . normalizedSkuDirectory($data['sku_normalized']);
                if (!is_dir($skuPhotoDir) && !mkdir($skuPhotoDir, 0777, true) && !is_dir($skuPhotoDir)) {
                    $photoWarnings[] = 'Could not create the photo folder for this SKU; item saved without photos.';
                } else {
                    $insertPhotoStmt = $pdo->prepare(<<<'SQL'
INSERT INTO sku_photos (sku_normalized, original_name, stored_name, mime_type, file_size, created_at)
VALUES (:sku_normalized, :original_name, :stored_name, :mime_type, :file_size, datetime('now'));
SQL);
                    foreach ($pendingPhotoUploads as $upload) {
                        $storedName = bin2hex(random_bytes(16)) . '.' . $upload['extension'];
                        $destination = $skuPhotoDir . '/' . $storedName;
                        if (!move_uploaded_file($upload['tmp_name'], $destination)) {
                            $photoWarnings[] = 'A photo could not be saved and was skipped; the item was saved.';
                            continue;
                        }
                        $insertPhotoStmt->execute([
                            'sku_normalized' => $data['sku_normalized'],
                            'original_name' => $upload['original_name'],
                            'stored_name' => $storedName,
                            'mime_type' => $upload['mime_type'],
                            'file_size' => $upload['file_size'],
                        ]);
                    }
                }
            }

            if (!$errors) {
                $redirect = $_SERVER['PHP_SELF'] . '?saved=1&save_mode=' . urlencode($saveMode);
                if ($data['sku'] !== '') {
                    $redirect .= '&sku=' . urlencode($data['sku']);
                }
                header('Location: ' . $redirect);
                exit;
            }
        }
    }
}

$recent = [];
if ($lookupStatus !== '') {
    $recentStmt = $pdo->prepare('SELECT * FROM intake_items WHERE status = :status ORDER BY updated_at DESC, id DESC LIMIT 100');
    $recentStmt->execute(['status' => $lookupStatus]);
    $recent = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $recent = $pdo->query('SELECT * FROM intake_items ORDER BY id DESC LIMIT 25')->fetchAll(PDO::FETCH_ASSOC);
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
$toastMessage = '';
if ($saved) {
    $toastMessage = $saveMode === 'created' ? 'Saved as new SKU record.' : 'Saved and synced to this SKU.';
}
if (isset($_GET['photo_notice']) && trim((string)$_GET['photo_notice']) !== '') {
    $photoWarnings[] = trim((string)$_GET['photo_notice']);
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function checked(string $name, string $value, array $formData): string
{
    return (($formData[$name] ?? '') === $value) ? 'checked' : '';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dispo.Tech Intake Sheet</title>
  <link rel="stylesheet" href="assets/style.css">
  <link rel="stylesheet" media="print" href="assets/print.css">
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
</head>
<body>
  <main class="page">
    <div id="save-toast" class="toast" role="status" aria-live="polite"
      data-active="<?php echo $saved ? '1' : '0'; ?>"
      data-message="<?php echo h($toastMessage); ?>">
    </div>
    <div class="app-menu">
      <button type="button" class="menu-toggle" aria-expanded="false" aria-controls="global-menu" id="menu-toggle">
        <span class="hamburger" aria-hidden="true"></span>
        <span>Menu</span>
      </button>
      <nav class="menu-panel" id="global-menu" aria-hidden="true">
        <ul class="menu-links">
          <li><a class="menu-link <?php echo $currentPage === 'home' ? 'is-active' : ''; ?>" href="home.php">Home</a></li>
          <li><a class="menu-link <?php echo $currentPage === 'lookup' ? 'is-active' : ''; ?>" href="home.php#sku-lookup">SKU Lookup</a></li>
          <li><a class="menu-link <?php echo $currentPage === 'intake' ? 'is-active' : ''; ?>" href="index.php?clear_draft=1" data-new-intake>New Intake</a></li>
        </ul>
      </nav>
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
          <a class="button-link new-intake-cta" href="index.php?clear_draft=1" data-new-intake>New Intake</a>
        </div>
        <div class="status">
          <label>
            <span>Status:</span>
            <select name="status" form="intake-form">
              <option value="">Select</option>
              <?php foreach ($statusOptions as $opt): ?>
                <option value="<?php echo $opt; ?>" <?php echo (($formData['status'] ?? '') === $opt) ? 'selected' : ''; ?>><?php echo $opt; ?></option>
              <?php endforeach; ?>
            </select>
          </label>
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

      <form id="photo-delete-form" method="post" class="visually-hidden">
        <input type="hidden" name="delete_photo_id" id="delete-photo-id">
        <input type="hidden" name="sku" id="delete-photo-sku" value="<?php echo h($activeSkuNormalized); ?>">
      </form>

          <form id="intake-form" method="post" enctype="multipart/form-data" class="form-grid">
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
        <div class="row">
          <label>SKU
              <input type="text" name="sku" value="<?php echo h($formData['sku'] ?? ''); ?>" required autofocus>
          </label>
          <?php
            $currentWhat = trim((string)($formData['what_is_it'] ?? ''));
            $whatOptionsList = $whatIsItOptions;
            if ($currentWhat !== '' && !in_array($currentWhat, $whatOptionsList, true)) {
                $whatOptionsList[] = $currentWhat;
            }
          ?>
          <label>What is it?
            <div class="what-field dropdown-mode">
              <input type="text"
                     id="what-is-it-input"
                     name="what_is_it"
                     maxlength="120"
                     value="<?php echo h($currentWhat); ?>"
                     placeholder="Describe the item">
              <div class="what-counter" id="what-counter">0 / 120</div>
              <div class="what-menu">
                <button type="button" class="what-menu-toggle" id="what-menu-toggle" aria-expanded="false" aria-haspopup="listbox" aria-label="Open item type list">▼</button>
                <div class="what-menu-list" id="what-menu-list" role="listbox" hidden>
                  <?php foreach ($whatOptionsList as $opt): ?>
                    <button type="button"
                            class="what-menu-item"
                            role="option"
                            data-value="<?php echo h($opt); ?>">
                      <span class="what-menu-label"><?php echo h($opt); ?></span>
                      <?php if (!in_array($opt, baseWhatIsItOptions(), true)): ?>
                        <span class="what-menu-delete" data-value="<?php echo h($opt); ?>" aria-label="Delete <?php echo h($opt); ?>">×</span>
                      <?php else: ?>
                        <span class="what-menu-spacer"></span>
                      <?php endif; ?>
                    </button>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
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
                  <span>Is it square?</span>
                </label>
                <label class="segment">
                  <input type="checkbox" name="care_if_square" <?php echo !empty($formData['care_if_square']) ? 'checked' : ''; ?>>
                  <span>Do we care?</span>
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
                <span>Square item (flag it so we care)</span>
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
          </div>

          <div class="section sku-photos">
            <h2>SKU Photos</h2>
            <div class="sku-photo-dropzone" id="sku-photo-dropzone">
              <label>Add photos for this SKU
                <input type="file" name="sku_photos[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple id="sku-photo-input">
              </label>
              <p class="hint">Drop, paste, or click to add images.</p>
            </div>
            <div class="sku-photo-preview" id="sku-photo-preview" hidden>
              <p class="hint">Preview (not saved until you click Save Intake Item):</p>
              <div class="sku-photo-grid" id="sku-photo-preview-list" aria-live="polite"></div>
            </div>
            <div id="photo-upload-messages" class="upload-messages" aria-live="polite"></div>
            <p class="hint">Photos are attached when you click Save Intake Item.</p>
            <p class="hint">Per-photo limit: <?php echo h(humanBytes($effectivePhotoLimitBytes)); ?>.</p>
            <?php if ($activeSkuNormalized === ''): ?>
              <p class="hint">Enter a SKU first to keep photos grouped with that specific item.</p>
            <?php elseif (!$skuPhotos): ?>
              <p class="hint">No photos saved for SKU <?php echo h($activeSkuNormalized); ?> yet.</p>
            <?php else: ?>
              <div class="inline-actions">
                <a class="ghost button" href="download_photos.php?sku=<?php echo urlencode($activeSkuNormalized); ?>">Download all as ZIP</a>
              </div>
              <div class="sku-photo-grid">
                <?php foreach ($skuPhotos as $photo): ?>
                  <div class="sku-photo-item">
                    <a class="sku-photo-link" href="photo.php?id=<?php echo isset($photo['id']) ? (int)$photo['id'] : 0; ?>" target="_blank" rel="noopener" title="Open photo in new tab">
                      <span class="sku-photo-badge">SKU <?php echo h($activeSkuNormalized); ?></span>
                      <img src="photo.php?id=<?php echo isset($photo['id']) ? (int)$photo['id'] : 0; ?>"
                           alt="Photo for SKU <?php echo h($activeSkuNormalized); ?> — <?php echo h($photo['original_name'] ?? 'Photo'); ?>">
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
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="section">
            <h2>Where did it go?</h2>
            <div class="row">
              <label>
                <select name="where_it_goes">
                  <option value="">Select</option>
                  <?php foreach (['D2 - Description','Scrap Room'] as $opt): ?>
                    <option value="<?php echo $opt; ?>" <?php echo (($formData['where_it_goes'] ?? '') === $opt) ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>
          </div>

          <div class="section">
            <h2>E-Bay Status</h2>
            <div class="row">
              <label>Ebay Status
                <input type="text" name="ebay_status" value="<?php echo h($formData['ebay_status'] ?? ''); ?>">
              </label>
              <label>Ebay Price
                <input type="number" step="0.01" name="ebay_price" value="<?php echo h(isset($formData['ebay_price']) ? (string)$formData['ebay_price'] : ''); ?>">
              </label>
              <label>DispoTech Price
                <input type="number" step="0.01" name="dispotech_price" value="<?php echo h(isset($formData['dispotech_price']) ? (string)$formData['dispotech_price'] : ''); ?>">
              </label>
            </div>
            <div class="row">
              <fieldset>
                <legend>Is it in the EBay Room?</legend>
                <label><input type="radio" name="in_ebay_room" value="Yes" <?php echo checked('in_ebay_room','Yes', $formData); ?>> Yes</label>
                <label><input type="radio" name="in_ebay_room" value="No" <?php echo checked('in_ebay_room','No', $formData); ?>> No</label>
              </fieldset>
              <label>What Box?
                <input type="text" name="what_box" value="<?php echo h($formData['what_box'] ?? ''); ?>">
              </label>
            </div>
          </div>
        </div>

        <div class="section notes">
          <h2>Notes</h2>
          <textarea name="notes" rows="3"><?php echo h($formData['notes'] ?? ''); ?></textarea>
        </div>

        <div class="actions">
          <button type="submit">Save Intake Item</button>
        </div>
      </form>

      <section class="section recent-items">
        <h2><?php echo $lookupStatus !== '' ? 'Status Results' : 'Recent SKUs'; ?></h2>
        <form class="form-grid" method="get" action="index.php">
          <div class="row">
            <label>SKU
              <input type="text" name="sku" value="<?php echo h($lookupSku); ?>">
            </label>
            <label>Status
              <select name="status">
                <option value="">Any status</option>
                <?php foreach ($statusOptions as $opt): ?>
                  <option value="<?php echo $opt; ?>" <?php echo $lookupStatus === $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
            <div class="actions">
            <button type="submit">Search</button>
            <a class="button-link" href="index.php?clear_draft=1" data-new-intake>Clear</a>
          </div>
        </form>
        <?php if ($bulkErrors): ?>
          <div class="error-box">
            <?php foreach ($bulkErrors as $error): ?>
              <p class="error"><?php echo h($error); ?></p>
            <?php endforeach; ?>
          </div>
        <?php elseif ($bulkMessage): ?>
          <p class="success"><?php echo h($bulkMessage); ?></p>
        <?php endif; ?>
        <form id="bulk-form" method="post">
          <input type="hidden" name="bulk_update" value="1">
          <div class="bulk-actions">
            <label>
              Set selected to
              <select name="bulk_status">
                <option value="">Choose status</option>
                <?php foreach ($statusOptions as $opt): ?>
                  <option value="<?php echo $opt; ?>"><?php echo $opt; ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <button type="submit">Apply to selected</button>
            <span class="hint">Check boxes in the table, then update that status in bulk.</span>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Select</th>
                  <th>SKU</th>
                  <th>Status</th>
                  <th>What is it?</th>
                  <th>Updated</th>
                  <th>Open</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$recent): ?>
                  <tr>
                    <td colspan="6">No items found for this lookup.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($recent as $item): ?>
                    <tr>
                      <td class="bulk-checkbox-cell">
                        <label class="bulk-checkbox">
                          <input type="checkbox" name="bulk_ids[]" value="<?php echo isset($item['id']) ? (int)$item['id'] : 0; ?>">
                          <span></span>
                        </label>
                      </td>
                      <td><?php echo h($item['sku'] ?? ''); ?></td>
                      <td><?php echo h($item['status'] ?? ''); ?></td>
                      <td><?php echo h($item['what_is_it'] ?? ''); ?></td>
                      <td><?php echo h($item['updated_at'] ?? ''); ?></td>
                      <td><a class="open-link" href="index.php?sku=<?php echo urlencode((string)($item['sku'] ?? '')); ?>">Open</a></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </form>
      </section>
        </div>
      </div>
    </section>
  </main>
  <script>
    (function () {
      var baseWhatOptions = <?php echo json_encode(baseWhatIsItOptions(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
      var themeToggle = document.getElementById('theme-toggle');
      var applyThemeMode = function (mode) {
        var isDark = mode === 'dark';
        document.body.classList.toggle('dark-mode', isDark);
        if (themeToggle) {
          themeToggle.textContent = isDark ? 'Light mode' : 'Dark mode';
        }
      };
      var storedTheme = null;
      try {
        storedTheme = localStorage.getItem('themePreference');
      } catch (e) {}
      var initialTheme = storedTheme || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
      applyThemeMode(initialTheme);
      if (themeToggle) {
        themeToggle.addEventListener('click', function () {
          var nextMode = document.body.classList.contains('dark-mode') ? 'light' : 'dark';
          applyThemeMode(nextMode);
          try {
            localStorage.setItem('themePreference', nextMode);
          } catch (e) {}
        });
      }

      var PRINT_MARGIN_IN = 0.35;
      var PRINT_PAGE_WIDTH_IN = 8.5;
      var PRINT_PAGE_HEIGHT_IN = 11;
      var PRINT_DPI = 96;
      var resizeTextareasForPrint = function () {
        var textareas = document.querySelectorAll('textarea');
        textareas.forEach(function (ta) {
          ta.style.height = 'auto';
          ta.style.minHeight = '0';
          ta.style.height = (ta.scrollHeight + 6) + 'px';
        });
      };
      var resetTextareaHeights = function () {
        document.querySelectorAll('textarea').forEach(function (ta) {
          ta.style.height = '';
          ta.style.minHeight = '';
        });
      };
      var applyPrintScale = function () {
        var sheet = document.querySelector('.sheet');
        if (!sheet) return;
        sheet.style.transform = '';
        sheet.style.width = '';
        var printableWidth = (PRINT_PAGE_WIDTH_IN - PRINT_MARGIN_IN * 2) * PRINT_DPI;
        var printableHeight = (PRINT_PAGE_HEIGHT_IN - PRINT_MARGIN_IN * 2) * PRINT_DPI;
        var scale = Math.min(
          1,
          printableWidth / sheet.offsetWidth,
          printableHeight / sheet.offsetHeight
        );
        sheet.dataset.printScale = scale.toFixed(3);
        sheet.style.transformOrigin = 'top left';
        sheet.style.transform = 'scale(' + scale + ')';
        sheet.style.width = (100 / scale) + '%';
      };
      var resetPrintScale = function () {
        var sheet = document.querySelector('.sheet');
        if (!sheet) return;
        sheet.style.transform = '';
        sheet.style.width = '';
        sheet.removeAttribute('data-print-scale');
      };
      var prepareForPrint = function () {
        resizeTextareasForPrint();
        applyPrintScale();
      };
      var cleanupAfterPrint = function () {
        resetTextareaHeights();
        resetPrintScale();
      };
      window.addEventListener('beforeprint', prepareForPrint);
      window.addEventListener('afterprint', cleanupAfterPrint);
      if (window.matchMedia) {
        var printWatcher = window.matchMedia('print');
        if (printWatcher && printWatcher.addListener) {
          printWatcher.addListener(function (mql) {
            if (mql.matches) {
              prepareForPrint();
            } else {
              cleanupAfterPrint();
            }
          });
        }
      }

      var printButton = document.getElementById('print-button');
      if (printButton) {
        printButton.addEventListener('click', function () {
          prepareForPrint();
          window.print();
        });
      }

      // "What is it?" select with custom entry support
      var whatInput = document.getElementById('what-is-it-input');
      var whatError = document.getElementById('what-error');
      var whatMenuToggle = document.getElementById('what-menu-toggle');
      var whatMenuList = document.getElementById('what-menu-list');
      var whatCounter = document.getElementById('what-counter');
      var isProtectedWhat = function (value) {
        return baseWhatOptions.indexOf(value) !== -1;
      };
      var closeWhatMenu = function () {
        if (!whatMenuList || !whatMenuToggle) return;
        whatMenuList.classList.remove('is-open');
        whatMenuToggle.setAttribute('aria-expanded', 'false');
      };
      var openWhatMenu = function () {
        if (!whatMenuList || !whatMenuToggle) return;
        whatMenuList.classList.add('is-open');
        whatMenuToggle.setAttribute('aria-expanded', 'true');
      };
      if (whatMenuToggle && whatMenuList) {
        whatMenuToggle.addEventListener('click', function () {
          if (!whatMenuList.classList.contains('is-open')) {
            openWhatMenu();
          } else {
            closeWhatMenu();
          }
        });
        document.addEventListener('click', function (evt) {
          if (whatMenuList.classList.contains('is-open') && !whatMenuList.contains(evt.target) && evt.target !== whatMenuToggle) {
            closeWhatMenu();
          }
        });
        whatMenuList.addEventListener('click', function (evt) {
          var itemBtn = evt.target.closest('.what-menu-item');
          if (!itemBtn) {
            return;
          }
          var value = itemBtn.getAttribute('data-value') || '';
          var deleteBtn = evt.target.closest('.what-menu-delete');
          if (deleteBtn) {
            if (isProtectedWhat(value)) {
              alert('Default options cannot be removed.');
              return;
            }
            itemBtn.remove();
            if (whatInput && whatInput.value === value) {
              whatInput.value = '';
            }
            closeWhatMenu();
            return;
          }
          if (whatInput) {
            whatInput.value = value;
            closeWhatMenu();
          }
        });
        // Counter + open menu on focus
        if (whatInput) {
          var updateCounter = function () {
            if (!whatCounter) return;
            var len = (whatInput.value || '').length;
            whatCounter.textContent = len + ' / 120';
          };
          whatInput.addEventListener('focus', openWhatMenu);
          whatInput.addEventListener('blur', function () { setTimeout(closeWhatMenu, 120); });
          whatInput.addEventListener('input', updateCounter);
          updateCounter();
        }
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
      var menuToggle = document.getElementById('menu-toggle');
      var menuPanel = document.getElementById('global-menu');
      if (menuToggle && menuPanel) {
        var bodyElement = document.body;
        var setMenuState = function (open) {
          menuPanel.classList.toggle('is-open', open);
          menuPanel.setAttribute('aria-hidden', open ? 'false' : 'true');
          menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
          bodyElement.classList.toggle('has-open-menu', open);
        };

        var closeMenu = function () {
          setMenuState(false);
        };

        menuToggle.addEventListener('click', function () {
          var opening = !menuPanel.classList.contains('is-open');
          setMenuState(opening);
        });
        document.addEventListener('click', function (event) {
          if (!menuPanel.classList.contains('is-open')) {
            return;
          }
          if (!menuPanel.contains(event.target) && !menuToggle.contains(event.target)) {
            closeMenu();
          }
        });
        document.addEventListener('keydown', function (event) {
          if (event.key === 'Escape') {
            closeMenu();
          }
        });
      }

      var form = document.getElementById('intake-form');
      if (form) {
        var draftKey = 'intakeDraftV1';
        var backupKey = 'intakeDraftBackupV1';
        var errorEl = document.getElementById('client-error');
        var dismissDraft = document.getElementById('draft-dismiss');
        var hasRecord = document.getElementById('has-server-record');
        var hasLookup = document.getElementById('has-lookup-sku');
        var clearDraft = document.getElementById('clear-draft');
        var restoreBtn = document.getElementById('restore-draft-button');
        var restoreHint = document.getElementById('restore-hint');
        // Track last serialized draft to avoid writing identical data over and over.
        var lastSavedDraft = null;
        var applyDraft = function (raw) {
          if (!raw) return;
          var draft = JSON.parse(raw);
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
        if (clearDraft && clearDraft.value === '1') {
          try {
            var existingDraft = localStorage.getItem(draftKey);
            if (existingDraft) {
              localStorage.setItem(backupKey, existingDraft);
            }
            localStorage.removeItem(draftKey);
          } catch (e) {}
        }
        var shouldRestore = dismissDraft && dismissDraft.value !== '1'
          && hasRecord && hasRecord.value !== '1'
          && hasLookup && hasLookup.value !== '1';
        if (clearDraft && clearDraft.value === '1') {
          shouldRestore = false;
        }

        var applyRequiredState = function (name, missing) {
          var el = form.querySelector('[name="' + name + '"]');
          if (el) {
            el.classList.toggle('required-missing', missing);
          }
        };

        if (shouldRestore) {
          try {
            var raw = localStorage.getItem(draftKey);
            if (raw) {
              applyDraft(raw);
              lastSavedDraft = raw;
            }
          } catch (e) {}
        }

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
                lastSavedDraft = backupDraft;
                restoreBtn.hidden = true;
                if (restoreHint) { restoreHint.hidden = true; }
                showToast('Draft restored');
            });
            }
          } catch (e) {}
        }

        var saveTimer = null;
        var saveDraft = function () {
          var payload = {};
          var fields = form.querySelectorAll('input[name], select[name], textarea[name]');
          fields.forEach(function (field) {
            if (field.type === 'radio') {
              if (field.checked) {
                payload[field.name] = field.value;
              }
              return;
            }
            if (field.type === 'checkbox') {
              payload[field.name] = field.checked;
              return;
            }
            if (field.type === 'file') {
              return;
            }
            payload[field.name] = field.value;
          });
          var serialized = JSON.stringify(payload);
          if (serialized === lastSavedDraft) {
            return;
          }
          lastSavedDraft = serialized;
          localStorage.setItem(draftKey, serialized);
        };
        var queueDraftSave = function () {
          clearTimeout(saveTimer);
          // Debounce a bit longer to avoid rapid-fire saves while typing.
          saveTimer = setTimeout(saveDraft, 800);
        };

        form.addEventListener('input', function (event) {
          queueDraftSave();
          if (!event.target || !event.target.name) {
            return;
          }
          if (event.target.name === 'sku') {
            applyRequiredState(event.target.name, false);
            if (errorEl) {
              errorEl.hidden = true;
            }
            var upper = (event.target.value || '').toUpperCase();
            if (event.target.value !== upper) {
              var pos = event.target.selectionStart;
              event.target.value = upper;
              if (typeof pos === 'number') {
                event.target.selectionStart = event.target.selectionEnd = pos;
              }
            }
          }
          if (event.target === whatInput && whatError) {
            whatError.hidden = true;
          }
        });
        form.addEventListener('change', queueDraftSave);
        form.addEventListener('submit', function (event) {
          var skuField = form.querySelector('[name="sku"]');
          var sku = ((skuField || {}).value || '').trim().toUpperCase();
          if (skuField) {
            skuField.value = sku;
          }
          var missingSku = sku === '';
          applyRequiredState('sku', missingSku);
          var whatVal = (whatInput && whatInput.value.trim()) || '';
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
          localStorage.removeItem(draftKey);
        });
      }

      var photoInput = document.getElementById('sku-photo-input');
      var photoDropzone = document.getElementById('sku-photo-dropzone');
      var previewContainer = document.getElementById('sku-photo-preview');
      var previewList = document.getElementById('sku-photo-preview-list');
      var deleteForm = document.getElementById('photo-delete-form');
      var deleteInput = document.getElementById('delete-photo-id');
      var deleteSku = document.getElementById('delete-photo-sku');
      var skuField = document.querySelector('input[name="sku"]');
      var isUploading = false;
      var submitButton = document.querySelector('button[type="submit"]');
      var uploadMessages = document.getElementById('photo-upload-messages');
      var pushUploadMessage = function (text, type) {
        if (!uploadMessages) return;
        var div = document.createElement('div');
        div.className = 'msg ' + (type === 'error' ? 'err' : 'ok');
        div.textContent = text;
        uploadMessages.appendChild(div);
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
          progressBar.appendChild(progressInner);
          card.appendChild(progressBar);

          previewList.appendChild(card);
        });
        previewContainer.hidden = previewList.children.length === 0;
      };
      var syncInputFromQueue = function () {
        if (!photoInput || !window.DataTransfer) {
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
          Array.prototype.forEach.call(files, function (file) {
            if (!file || !file.type || !file.type.startsWith('image/')) {
              return;
            }
            var url = URL.createObjectURL(file);
            photoQueue.push({ file: file, url: url });
          });
          syncInputFromQueue();
          renderPreview();
        });
      }
      var updateProgress = function (idx, percent) {
        var bar = previewList.querySelector('.sku-photo-progress span[data-photo-idx="' + idx + '"]');
        if (bar) {
          bar.style.width = Math.min(100, Math.max(0, percent)) + '%';
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
                onDone();
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
        var idx = 0;
        var total = photoQueue.length;
        var next = function () {
          if (idx >= total) {
            isUploading = false;
            if (submitButton) {
              submitButton.disabled = false;
              submitButton.textContent = 'Save Intake Item';
            }
            // reload to show freshly saved photos
            location.reload();
            return;
          }
          var entry = photoQueue[idx];
          uploadFileChunked(entry, idx, function () {
            idx += 1;
            next();
          }, function (msg) {
            isUploading = false;
            if (submitButton) {
              submitButton.disabled = false;
              submitButton.textContent = 'Save Intake Item';
            }
            pushUploadMessage('Failed: ' + (entry.file.name || 'photo') + ' — ' + msg, 'error');
          });
        };
        next();
      };
      var addFilesToQueue = function (fileList) {
        Array.prototype.forEach.call(fileList || [], function (file) {
          if (!file || !file.type || !file.type.startsWith('image/')) {
            return;
          }
          var url = URL.createObjectURL(file);
          photoQueue.push({ file: file, url: url, progress: 0 });
        });
        syncInputFromQueue();
        renderPreview();
        processQueue();
      };
      if (photoDropzone) {
        var dz = photoDropzone;
        ['dragenter', 'dragover'].forEach(function (evtName) {
          dz.addEventListener(evtName, function (evt) {
            evt.preventDefault();
            dz.classList.add('is-hover');
          });
        });
        ['dragleave', 'drop'].forEach(function (evtName) {
          dz.addEventListener(evtName, function (evt) {
            evt.preventDefault();
            dz.classList.remove('is-hover');
          });
        });
        dz.addEventListener('drop', function (evt) {
          addFilesToQueue(evt.dataTransfer ? evt.dataTransfer.files : []);
        });
        dz.addEventListener('paste', function (evt) {
          if (!evt.clipboardData) return;
          var items = evt.clipboardData.files;
          addFilesToQueue(items);
        });
      }
      var deleteButtons = document.querySelectorAll('.js-delete-photo');
      if (deleteButtons.length && deleteForm && deleteInput) {
        deleteButtons.forEach(function (btn) {
          btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-photo-id');
            if (!id) return;
            var ok = confirm('Delete this photo?');
            if (!ok) return;
            deleteInput.value = id;
            if (skuField) {
              deleteSku.value = skuField.value;
            }
            deleteForm.submit();
          });
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

      // Light auto-refresh to keep listings/photos current without manual Save press
      setInterval(function () {
        if (!document.body.classList.contains('has-open-menu') && !isUploading) {
          location.reload();
        }
      }, 60000);

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
</body>
</html>
