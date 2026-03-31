<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance(true);
ensureStorageWritable();

const DB_PATH = __DIR__ . '/data/intake.sqlite';
const PHOTO_UPLOAD_DIR = __DIR__ . '/data/sku_photos';
const MAX_SKU_PHOTOS_PER_UPLOAD = 8;
const MAX_SKU_PHOTO_BYTES = 16 * 1024 * 1024;
const ALLOWED_PHOTO_MIME_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
];

header('Content-Type: application/json; charset=utf-8');

function normalizeSku(string $sku): string
{
    return strtoupper(trim($sku));
}

function normalizedSkuDirectory(string $skuNormalized): string
{
    $dir = preg_replace('/[^A-Z0-9_-]+/', '_', $skuNormalized);
    return trim((string)$dir, '_') ?: 'UNASSIGNED';
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

function errorResponse(string $message, int $code = 400): void
{
    http_response_code($code);
    @file_put_contents(__DIR__ . '/logs/upload_errors.log', '[' . date('c') . '] ' . $message . PHP_EOL, FILE_APPEND);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

$sku = normalizeSku((string)($_POST['sku'] ?? ''));
if ($sku === '') {
    errorResponse('SKU is required to upload photos.');
}

if (!isset($_FILES['photo'])) {
    errorResponse('No photo uploaded.');
}

$upload = $_FILES['photo'];
$originalDisplayName = (string)($upload['name'] ?? 'photo');

if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    errorResponse('No file was selected.');
}
if (($upload['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_INI_SIZE) {
    errorResponse($originalDisplayName . ' exceeded the server upload limit.');
}
if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    errorResponse($originalDisplayName . ' failed to upload (error code ' . $upload['error'] . ').');
}

$size = (int)($upload['size'] ?? 0);
if ($size <= 0 || $size > MAX_SKU_PHOTO_BYTES) {
    errorResponse($originalDisplayName . ' is outside the size limit (' . $size . ' bytes).');
}

$tmp = (string)($upload['tmp_name'] ?? '');
if (!is_uploaded_file($tmp)) {
    errorResponse($originalDisplayName . ' failed validation.');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = (string)finfo_file($finfo, $tmp);
finfo_close($finfo);
$extension = ALLOWED_PHOTO_MIME_TYPES[$mimeType] ?? null;
if ($extension === null) {
    errorResponse($originalDisplayName . ' is not JPG/PNG/WEBP/GIF.');
}

$pdo = new PDO('sqlite:' . DB_PATH, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
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

if (!is_dir(PHOTO_UPLOAD_DIR)) {
    mkdir(PHOTO_UPLOAD_DIR, 0777, true);
}

$skuDir = PHOTO_UPLOAD_DIR . '/' . normalizedSkuDirectory($sku);
if (!is_dir($skuDir) && !mkdir($skuDir, 0777, true) && !is_dir($skuDir)) {
    errorResponse('Could not create photo folder.');
}

$storedName = bin2hex(random_bytes(16)) . '.' . $extension;
$destination = $skuDir . '/' . $storedName;

if (!move_uploaded_file($tmp, $destination)) {
    errorResponse('Failed to save file to disk.');
}

try {
    $stmt = $pdo->prepare(<<<'SQL'
INSERT INTO sku_photos (sku_normalized, original_name, stored_name, mime_type, file_size, created_at)
VALUES (:sku_normalized, :original_name, :stored_name, :mime_type, :file_size, datetime('now'));
SQL);
    $stmt->execute([
        'sku_normalized' => $sku,
        'original_name' => sanitizeFilename($originalDisplayName),
        'stored_name' => $storedName,
        'mime_type' => $mimeType,
        'file_size' => $size,
    ]);
} catch (Throwable $e) {
    errorResponse('Database error: ' . $e->getMessage(), 500);
}

echo json_encode(['status' => 'ok', 'message' => 'Uploaded', 'id' => $pdo->lastInsertId()]);
