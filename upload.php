<?php
/**
 * upload.php — AJAX image upload with automatic PNG conversion
 *
 * Accepts JPG, PNG, WebP, or GIF input; validates type/size; converts
 * to PNG using the shared imageConvertToPng() helper (GD); stores in
 * data/ebay_images/<sku>/; returns JSON with the PNG file path.
 * All temporary files are cleaned up immediately.
 *
 * POST params:
 *   photo       — uploaded file
 *   sku         — normalized SKU string
 *   csrf_token  — CSRF token
 *
 * Success: { "ok": true,  "id": "<hash>.png", "url": "ebay_serve.php?sku=ABC&file=<hash>.png", "name": "photo.png", "size": N }
 * Failure: { "ok": false, "message": "..." }
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance(true);
ensureStorageWritable();

require_csrf();

const UPLOAD_DIR  = __DIR__ . '/data/ebay_images';
const MAX_BYTES   = 32 * 1024 * 1024;

/* ── Validate input ──────────────────────────────────────────── */
$sku = normalizeSku((string)($_POST['sku'] ?? ''));
if ($sku === '') {
    errorResponse('SKU is required.');
}

if (!isset($_FILES['photo'])) {
    errorResponse('No file uploaded.');
}

$f    = $_FILES['photo'];
$name = (string)($f['name'] ?? 'photo');
$err  = $f['error'] ?? UPLOAD_ERR_NO_FILE;

if ($err === UPLOAD_ERR_NO_FILE) {
    errorResponse('No file selected.');
}
if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
    errorResponse($name . ' exceeds size limit.');
}
if ($err !== UPLOAD_ERR_OK) {
    errorResponse($name . ' upload error (code ' . $err . ').');
}

$size = (int)($f['size'] ?? 0);
if ($size <= 0 || $size > MAX_BYTES) {
    errorResponse($name . ' is too large (' . $size . ' bytes).');
}

$tmp = (string)($f['tmp_name'] ?? '');
if (!is_uploaded_file($tmp)) {
    errorResponse($name . ' validation failed.');
}

/* ── Delegate to centralised pipeline (MIME check, GD polyglot defence, conversion) ──── */
$result = processUploadedPhoto($f, $sku, UPLOAD_DIR);
if (!$result['ok']) {
    errorResponse($result['message'] ?? 'Upload failed.');
}

$stored    = $result['stored_name'];
$finalSize = $result['file_size'];

$urlSku    = urlencode($sku);
$urlFile   = urlencode($stored);

successResponse([
    'id'   => $stored,
    'url'  => "ebay_serve.php?sku={$urlSku}&file={$urlFile}",
    'name' => pathinfo($name, PATHINFO_FILENAME) . '.png',
    'size' => $finalSize,
]);
