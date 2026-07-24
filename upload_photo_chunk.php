<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/square_sync.php';
checkMaintenance(true);
ensureStorageWritable();

const DB_PATH = __DIR__ . '/data/intake.sqlite';
const PHOTO_UPLOAD_DIR = __DIR__ . '/data/sku_photos';
const CHUNK_DIR = __DIR__ . '/data/chunks';
const MAX_SKU_PHOTO_BYTES = 100 * 1024 * 1024; // 100 MB per photo
const ALLOWED_PHOTO_MIME_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
];

require_csrf();

function logContext(string $message): void
{
    error_log('upload_photo_chunk.php: ' . $message);
}

/* ── Collect & validate metadata ──────────────────────────── */
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

logContext("recv chunk upload_id=$uploadId idx=$chunkIndex/$chunkTotal size=" . ($chunk['size'] ?? 0) . " total=$totalSize sku=$sku");

// Validate MIME from first chunk only
$extension = null;
if ($chunkIndex === 0) {
    $mimeType = detectUploadMimeType($tmp, $originalName);
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

// ── If last chunk, assemble ＋ finalise ─────────────────────
$assembled = false;
if ($chunkIndex === $chunkTotal - 1) {
$pdo = pdoConnect(DB_PATH);
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

    try {
        $pdo->exec("ALTER TABLE sku_photos ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0");
    } catch (Throwable $e) {
        // ignore if exists
    }

    if (!is_dir(PHOTO_UPLOAD_DIR)) {
        mkdir(PHOTO_UPLOAD_DIR, 0777, true);
    }
    $skuDir = PHOTO_UPLOAD_DIR . '/' . normalizedSkuDirectory($sku);
    if (!is_dir($skuDir) && !mkdir($skuDir, 0777, true) && !is_dir($skuDir)) {
        errorResponse('Could not create photo folder.');
    }

    $tempAssembled = $chunkFolder . '/assembled.tmp';
    try {
        $out = fopen($tempAssembled, 'wb');
        if ($out === false) {
            errorResponse('Failed to open temp file.');
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

        $finalSize = (int)@filesize($tempAssembled);
        if ($finalSize !== $totalSize) {
            errorResponse('Assembled size mismatch.');
        }

        /* ── POLYGLOT PROTECTION: validate via GD decode ──── */
        $raw = @file_get_contents($tempAssembled);
        if ($raw === false || $raw === '') {
            errorResponse('Failed to read assembled file.');
        }
        $gdCheck = @imagecreatefromstring($raw);
        if (!$gdCheck) {
            errorResponse('Assembled file is not a valid image and was rejected.');
        }
        imagedestroy($gdCheck);
        unset($raw);

        /* ── Convert or store as-is ───────────────────────── */
        if (PNG_ONLY_MODE && $mimeType !== 'image/png') {
            $storedName = imageConvertToPng($tempAssembled, $skuDir);
            if ($storedName === null) {
                errorResponse('Failed to convert assembled image to PNG.', 500);
            }
            $pngPath = $skuDir . '/' . $storedName;
            $finalSize = (int)@filesize($pngPath);
            $dbMime = 'image/png';
        } else {
            $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
            $destPath = $skuDir . '/' . $storedName;
            if (!rename($tempAssembled, $destPath)) {
                errorResponse('Failed to move assembled file.', 500);
            }
            $dbMime = $mimeType;
        }
    } finally {
        /* ── Always clean up temp files ───────────────────── */
        @unlink($tempAssembled);
        $partFiles = glob($chunkFolder . '/*.part') ?: [];
        foreach ($partFiles as $pf) {
            @unlink($pf);
        }
        @rmdir($chunkFolder);
    }

    try {
        $maxSortStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM sku_photos WHERE sku_normalized = :sku');
        $maxSortStmt->execute(['sku' => $sku]);
        $nextSort = (int)$maxSortStmt->fetchColumn() + 1;
        $stmt = $pdo->prepare(<<<'SQL'
INSERT INTO sku_photos (sku_normalized, original_name, stored_name, mime_type, file_size, created_at, sort_order)
VALUES (:sku_normalized, :original_name, :stored_name, :mime_type, :file_size, datetime('now'), :sort_order);
SQL);
        $stmt->execute([
            'sku_normalized' => $sku,
            'original_name' => sanitizeFilename($originalName),
            'stored_name' => $storedName,
            'mime_type' => $dbMime,
            'file_size' => $finalSize,
            'sort_order' => $nextSort,
        ]);
        $photoId = $pdo->lastInsertId();
    } catch (Throwable $e) {
        errorResponse('Database error: ' . $e->getMessage(), 500);
    }

    logContext("assembled upload_id=$uploadId stored=$storedName size=$finalSize sku=$sku");
    $assembled = true;
}

$squareSync = $assembled ? squareSyncItemBySku($pdo, $sku) : ['status' => 'skipped'];
successResponse([
    'status' => 'ok',
    'done' => $assembled,
    'id' => $photoId ?? null,
    'square_sync' => $squareSync['status'] ?? 'skipped',
]);
