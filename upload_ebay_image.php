<?php
/**
 * upload_ebay_image.php — AJAX image upload for eBay Listing Image Composer
 *
 * Accepts a single image file via POST, validates type/size, stores it
 * in data/ebay_listing_photos/<sku>/, inserts a DB record, and returns a
 * JSON response with the new image's ID and URL.
 *
 * POST params:
 *   sku          — normalized SKU string
 *   photo        — uploaded file (see $_FILES['photo'])
 *   csrf_token   — CSRF token
 *
 * JSON response (success):
 *   { "status": "ok", "id": 42, "url": "ebay_photo_serve.php?id=42", "name": "photo.jpg" }
 *
 * JSON response (failure):
 *   { "status": "error", "message": "..." }
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance(true);
ensureStorageWritable();

const EBAY_UPLOAD_DB   = __DIR__ . '/data/intake.sqlite';
const EBAY_PHOTO_DIR   = __DIR__ . '/data/ebay_listing_photos';
const MAX_EBAI_IMG_BYTES = 16 * 1024 * 1024;
const ALLOWED_EBAY_MIME = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

header('Content-Type: application/json; charset=utf-8');
require_csrf();

/* ── Helpers ──────────────────────────────────────────────────── */
function errorJson(string $msg, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

function sanitizeFilename(string $name): string
{
    $name = trim($name);
    if ($name === '') return 'photo';
    $clean = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
    return trim((string)$clean, '._-') ?: 'photo';
}

/* ── Validate SKU ─────────────────────────────────────────────── */
$sku = normalizeSku((string)($_POST['sku'] ?? ''));
if ($sku === '') {
    errorJson('SKU is required.');
}

/* ── Validate uploaded file ────────────────────────────────────── */
if (!isset($_FILES['photo'])) {
    errorJson('No file uploaded.');
}

$upload = $_FILES['photo'];
$originalName = (string)($upload['name'] ?? 'photo');

$errCode = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
if ($errCode === UPLOAD_ERR_NO_FILE) {
    errorJson('No file was selected.');
}
if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
    errorJson($originalName . ' exceeds the size limit.');
}
if ($errCode !== UPLOAD_ERR_OK) {
    errorJson($originalName . ' upload error (code ' . $errCode . ').');
}

$size = (int)($upload['size'] ?? 0);
if ($size <= 0 || $size > MAX_EBAI_IMG_BYTES) {
    errorJson($originalName . ' is too large (' . $size . ' bytes). Maximum is ' . MAX_EBAI_IMG_BYTES . ' bytes.');
}

$tmp = (string)($upload['tmp_name'] ?? '');
if (!is_uploaded_file($tmp)) {
    errorJson($originalName . ' failed server-side validation.');
}

/* ── MIME type validation ──────────────────────────────────────── */
$mimeType = detectUploadMimeType($tmp, $originalName);
$extension = ALLOWED_EBAY_MIME[$mimeType] ?? null;
if ($extension === null) {
    errorJson($originalName . ' must be a JPG, PNG, WebP, or GIF image.');
}

/* ── Database setup ───────────────────────────────────────────── */
try {
    $pdo = new PDO('sqlite:' . EBAY_UPLOAD_DB, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS ebay_listing_images (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sku_normalized TEXT NOT NULL,
        original_name TEXT NOT NULL,
        stored_name TEXT NOT NULL,
        mime_type TEXT NOT NULL,
        file_size INTEGER NOT NULL DEFAULT 0,
        pos_x REAL NOT NULL DEFAULT 0,
        pos_y REAL NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
} catch (Throwable $e) {
    errorJson('Database error.', 500);
}

/* ── Store file ───────────────────────────────────────────────── */
if (!is_dir(EBAY_PHOTO_DIR)) {
    @mkdir(EBAY_PHOTO_DIR, 0777, true);
}
$skuDir = EBAY_PHOTO_DIR . '/' . preg_replace('/[^A-Z0-9_-]+/', '_', $sku);
$skuDir = rtrim($skuDir, '_') ?: EBAY_PHOTO_DIR . '/UNASSIGNED';
if (!is_dir($skuDir) && !mkdir($skuDir, 0777, true) && !is_dir($skuDir)) {
    errorJson('Could not create storage folder.', 500);
}

$storedName = bin2hex(random_bytes(16)) . '.' . $extension;
$destination = $skuDir . '/' . $storedName;

if (!move_uploaded_file($tmp, $destination)) {
    errorJson('Failed to save file to disk.', 500);
}

/* ── Insert DB record ─────────────────────────────────────────── */
try {
    $stmt = $pdo->prepare("INSERT INTO ebay_listing_images
        (sku_normalized, original_name, stored_name, mime_type, file_size)
        VALUES (:sku, :name, :stored, :mime, :size)");
    $stmt->execute([
        'sku'   => $sku,
        'name'  => sanitizeFilename($originalName),
        'stored' => $storedName,
        'mime'  => $mimeType,
        'size'  => $size,
    ]);
    $photoId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    @unlink($destination);
    errorJson('Database insert failed.', 500);
}

/* ── Success response ─────────────────────────────────────────── */
echo json_encode([
    'status' => 'ok',
    'id'     => $photoId,
    'url'    => 'ebay_photo_serve.php?id=' . $photoId,
    'name'   => $originalName,
]);
