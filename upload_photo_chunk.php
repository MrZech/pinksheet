<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/square_sync.php';
checkMaintenance(true);
ensureStorageWritable();

const DB_PATH = __DIR__ . '/data/intake.sqlite';
const PHOTO_UPLOAD_DIR = __DIR__ . '/data/sku_photos';
const CHUNK_DIR = __DIR__ . '/data/chunks';
const MAX_SKU_PHOTO_BYTES = 50 * 1024 * 1024; // 50 MB per photo
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

function infoLog(string $message): void
{
    @file_put_contents(__DIR__ . '/logs/upload_chunk.log', '[' . date('c') . '] ' . $message . PHP_EOL, FILE_APPEND);
}

$sku = normalizeSku((string)($_POST['sku'] ?? ''));
if ($sku === '') {
    errorResponse('SKU is required to attach photos.');
}

$uploadId = preg_replace('/[^A-Za-z0-9_-]+/', '', (string)($_POST['upload_id'] ?? ''));
$chunkIndex = (int)($_POST['chunk_index'] ?? -1);
$chunkTotal = (int)($_POST['chunk_total'] ?? 0);
$totalSize = (int)($_POST['total_size'] ?? 0);
$originalName = (string)($_POST['original_name'] ?? 'photo');
$mimeType = (string)($_POST['mime_type'] ?? '');

infoLog("recv chunk upload_id=$uploadId idx=$chunkIndex/$chunkTotal size=" . ($chunk['size'] ?? 0) . " total=$totalSize sku=$sku");

if ($uploadId === '' || $chunkIndex < 0 || $chunkTotal <= 0 || $chunkIndex >= $chunkTotal) {
    errorResponse('Invalid chunk metadata.');
}

if ($totalSize <= 0 || $totalSize > MAX_SKU_PHOTO_BYTES) {
    errorResponse('File is outside the size limit (' . MAX_SKU_PHOTO_BYTES . ' bytes).');
}

if (!isset($_FILES['chunk'])) {
    errorResponse('Chunk missing.');
}

$chunk = $_FILES['chunk'];
if (($chunk['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    errorResponse('Chunk upload failed (code ' . ($chunk['error'] ?? -1) . ').');
}

$tmp = (string)($chunk['tmp_name'] ?? '');
if (!is_uploaded_file($tmp)) {
    errorResponse('Chunk failed validation.');
}

// Validate MIME from first chunk only
$extension = null;
if ($chunkIndex === 0) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = (string)finfo_file($finfo, $tmp);
    finfo_close($finfo);
    $extension = ALLOWED_PHOTO_MIME_TYPES[$mimeType] ?? null;
    if ($extension === null) {
        errorResponse($originalName . ' is not JPG/PNG/WEBP/GIF.');
    }
} else {
    // use provided mime
    $extension = ALLOWED_PHOTO_MIME_TYPES[$mimeType] ?? null;
    if ($extension === null) {
        errorResponse('Unsupported type on chunk.');
    }
}

if (!is_dir(CHUNK_DIR)) {
    mkdir(CHUNK_DIR, 0777, true);
}

$chunkFolder = CHUNK_DIR . '/' . $uploadId;
if (!is_dir($chunkFolder) && !mkdir($chunkFolder, 0777, true) && !is_dir($chunkFolder)) {
    errorResponse('Could not create chunk folder.');
}

$chunkPath = $chunkFolder . '/' . str_pad((string)$chunkIndex, 6, '0', STR_PAD_LEFT) . '.part';
if (!move_uploaded_file($tmp, $chunkPath)) {
    errorResponse('Failed to store chunk on disk.');
}

// If last chunk, assemble
$assembled = false;
if ($chunkIndex === $chunkTotal - 1) {
$pdo = new PDO('sqlite:' . DB_PATH, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
squareSyncEnsureSchema($pdo);
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS sku_photos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sku_normalized TEXT NOT NULL,
    original_name TEXT NOT NULL,
    stored_name TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    file_size INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    is_thumb INTEGER NOT NULL DEFAULT 0
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
    $out = fopen($destination, 'wb');
    if ($out === false) {
        errorResponse('Failed to open destination file.');
    }
    for ($i = 0; $i < $chunkTotal; $i++) {
        $partPath = $chunkFolder . '/' . str_pad((string)$i, 6, '0', STR_PAD_LEFT) . '.part';
        if (!is_file($partPath)) {
            fclose($out);
            errorResponse('Missing chunk ' . $i);
        }
        $in = fopen($partPath, 'rb');
        stream_copy_to_stream($in, $out);
        fclose($in);
    }
    fclose($out);

    $finalSize = filesize($destination) ?: 0;
    if ($finalSize !== $totalSize) {
        @unlink($destination);
        errorResponse('Assembled size mismatch.');
    }

    try {
        $stmt = $pdo->prepare(<<<'SQL'
INSERT INTO sku_photos (sku_normalized, original_name, stored_name, mime_type, file_size, created_at)
VALUES (:sku_normalized, :original_name, :stored_name, :mime_type, :file_size, datetime('now'));
SQL);
        $stmt->execute([
            'sku_normalized' => $sku,
            'original_name' => sanitizeFilename($originalName),
            'stored_name' => $storedName,
            'mime_type' => $mimeType,
            'file_size' => $finalSize,
        ]);
        $photoId = $pdo->lastInsertId();
    } catch (Throwable $e) {
        @unlink($destination);
        errorResponse('Database error: ' . $e->getMessage(), 500);
    }

    infoLog("assembled upload_id=$uploadId stored=$storedName size=$finalSize sku=$sku");

    // cleanup chunks
    $files = glob($chunkFolder . '/*.part') ?: [];
    foreach ($files as $file) {
        @unlink($file);
    }
    @rmdir($chunkFolder);
    $assembled = true;
}

$squareSync = $assembled ? squareSyncItemBySku($pdo, $sku) : ['status' => 'skipped'];
echo json_encode([
    'status' => 'ok',
    'message' => $assembled ? 'Uploaded' : 'Chunk stored',
    'done' => $assembled,
    'id' => $photoId ?? null,
    'square_sync' => $squareSync['status'] ?? 'skipped',
]);
