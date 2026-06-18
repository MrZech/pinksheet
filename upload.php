<?php
/**
 * upload.php — AJAX image upload with automatic PNG conversion
 *
 * Accepts JPG, PNG, WebP, or GIF input; validates type/size; converts
 * to PNG using GD; stores in data/ebay_images/<sku>/; returns JSON
 * with the PNG file path. All temporary files are cleaned up immediately.
 *
 * POST params:
 *   photo       — uploaded file
 *   sku         — normalized SKU string
 *   csrf_token  — CSRF token
 *
 * Success: { "ok": true,  "id": "<hash>", "url": "ebay_serve.php?sku=ABC&file=<hash>.png", "name": "photo.png" }
 * Failure: { "ok": false, "message": "..." }
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance(true);
ensureStorageWritable();

header('Content-Type: application/json; charset=utf-8');
require_csrf();

const UPLOAD_DIR  = __DIR__ . '/data/ebay_images';
const MAX_BYTES   = 16 * 1024 * 1024;
const INPUT_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

function fail(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg]);
    exit;
}

/* ── Validate input ──────────────────────────────────────────── */
$sku = normalizeSku((string)($_POST['sku'] ?? ''));
if ($sku === '') fail('SKU is required.');

if (!isset($_FILES['photo'])) fail('No file uploaded.');

$f    = $_FILES['photo'];
$name = (string)($f['name'] ?? 'photo');
$err  = $f['error'] ?? UPLOAD_ERR_NO_FILE;

if ($err === UPLOAD_ERR_NO_FILE)      fail('No file selected.');
if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) fail($name . ' exceeds size limit.');
if ($err !== UPLOAD_ERR_OK)           fail($name . ' upload error (code ' . $err . ').');

$size = (int)($f['size'] ?? 0);
if ($size <= 0 || $size > MAX_BYTES) fail($name . ' is too large (' . $size . ' bytes).');

$tmp = (string)($f['tmp_name'] ?? '');
if (!is_uploaded_file($tmp)) fail($name . ' validation failed.');

$mime = detectUploadMimeType($tmp, $name);
if (!in_array($mime, INPUT_TYPES, true)) {
    fail($name . ' must be JPG, PNG, WebP, or GIF.');
}

/* ── Ensure storage directory ────────────────────────────────── */
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

/* ── Convert to PNG using GD ─────────────────────────────────── */
$gd = null;
switch ($mime) {
    case 'image/jpeg':
        $gd = @imagecreatefromjpeg($tmp);
        break;
    case 'image/png':
        $gd = @imagecreatefrompng($tmp);
        break;
    case 'image/webp':
        $gd = @imagecreatefromwebp($tmp);
        break;
    case 'image/gif':
        $gd = @imagecreatefromgif($tmp);
        break;
}

if (!$gd) {
    @unlink($tmp); // clean up temp file
    error_log('upload.php: GD failed to decode ' . $name);
    fail('Failed to process image.', 500);
}

// Preserve alpha channel for PNG/GIF transparency
imagealphablending($gd, false);
imagesavealpha($gd, true);

// Output as PNG to memory first
$pngBlob = null;
$captured = false;
$tmpPng = $skuDir . '/' . bin2hex(random_bytes(16)) . '_conv.png';
if (imagepng($gd, $tmpPng)) {
    $captured = true;
}
imagedestroy($gd);

if (!$captured) {
    @unlink($tmp);
    error_log('upload.php: imagepng() failed for ' . $name);
    fail('Failed to convert image to PNG.', 500);
}

/* ── Generate final filename and move ────────────────────────── */
$stored = bin2hex(random_bytes(16)) . '.png';
$dest   = $skuDir . '/' . $stored;

if (!rename($tmpPng, $dest)) {
    @unlink($tmpPng);
    @unlink($tmp);
    error_log('upload.php: rename failed from ' . $tmpPng . ' to ' . $dest);
    fail('Failed to store image.', 500);
}

$finalSize = filesize($dest);

/* ── Clean up ────────────────────────────────────────────────── */
@unlink($tmp); // original upload temp — always clean up

echo json_encode([
    'ok'   => true,
    'id'   => $stored,
    'url'  => 'ebay_serve.php?sku=' . urlencode($sku) . '&file=' . urlencode($stored),
    'name' => pathinfo($name, PATHINFO_FILENAME) . '.png',
    'size' => $finalSize,
]);
