<?php
/**
 * upload.php — AJAX image upload for eBay Listing Image Composer
 *
 * Accepts a single image file via POST, validates type/size, stores it
 * in data/ebay_images/<sku>/, returns JSON with the image URL.
 *
 * POST params:
 *   photo       — uploaded file
 *   sku         — normalized SKU string
 *   csrf_token  — CSRF token
 *
 * Success: { "ok": true,  "id": 42, "url": "ebay_serve.php?sku=ABC&file=abc.jpg", "name": "photo.jpg" }
 * Failure: { "ok": false, "message": "..." }
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance(true);
ensureStorageWritable();

header('Content-Type: application/json; charset=utf-8');
require_csrf();

const UPLOAD_DIR = __DIR__ . '/data/ebay_images';
const MAX_BYTES  = 16 * 1024 * 1024;
const ALLOWED_MIME = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

function fail(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg]);
    exit;
}

$sku = normalizeSku((string)($_POST['sku'] ?? ''));
if ($sku === '') fail('SKU is required.');

if (!isset($_FILES['photo'])) fail('No file uploaded.');

$f = $_FILES['photo'];
$name = (string)($f['name'] ?? 'photo');
$err  = $f['error'] ?? UPLOAD_ERR_NO_FILE;

if ($err === UPLOAD_ERR_NO_FILE)    fail('No file selected.');
if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) fail($name . ' exceeds size limit.');
if ($err !== UPLOAD_ERR_OK)         fail($name . ' upload error (code ' . $err . ').');

$size = (int)($f['size'] ?? 0);
if ($size <= 0 || $size > MAX_BYTES) fail($name . ' is too large (' . $size . ' bytes).');

$tmp = (string)($f['tmp_name'] ?? '');
if (!is_uploaded_file($tmp)) fail($name . ' validation failed.');

$mime = detectUploadMimeType($tmp, $name);
$ext = ALLOWED_MIME[$mime] ?? null;
if ($ext === null) fail($name . ' must be JPG, PNG, WebP, or GIF.');

// Ensure storage directory
if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0777, true) && !is_dir(UPLOAD_DIR)) {
    error_log('upload.php: failed to create ' . UPLOAD_DIR);
    fail('Server error.', 500);
}

$skuDir = UPLOAD_DIR . '/' . preg_replace('/[^A-Z0-9_-]+/', '_', $sku);
$skuDir = rtrim($skuDir, '_') ?: UPLOAD_DIR . '/UNASSIGNED';
if (!is_dir($skuDir) && !mkdir($skuDir, 0777, true) && !is_dir($skuDir)) {
    error_log('upload.php: failed to create ' . $skuDir);
    fail('Server error.', 500);
}

$stored = bin2hex(random_bytes(16)) . '.' . $ext;
$dest   = $skuDir . '/' . $stored;

if (!move_uploaded_file($tmp, $dest)) {
    error_log('upload.php: move_uploaded_file failed for ' . $dest);
    fail('Failed to save file.', 500);
}

// Clean up: if there were any chunk leftovers from a previous failed upload in this session,
// they are removed immediately. This upload is single-file, not chunked, so no chunk cleanup needed.

echo json_encode([
    'ok'   => true,
    'id'   => $stored,
    'url'  => 'ebay_serve.php?sku=' . urlencode($sku) . '&file=' . urlencode($stored),
    'name' => $name,
]);
