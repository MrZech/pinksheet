<?php
declare(strict_types=1);

/**
 * ──────────────────────────────────────────────────────────────
 *  Pinksheet — Consolidated Shared Utilities
 *  ──────────────────────────────────────────────────────────────
 *  Every function in this file is the single, authoritative
 *  definition.  All endpoint files MUST include config.php
 *  (which loads this file) and MUST NOT redefine any of these
 *  functions locally.
 * ──────────────────────────────────────────────────────────────
 */

/* ── HTML escaping ───────────────────────────────────────── */
function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/* ── Normalise a SKU to uppercase + trimmed ──────────────── */

/**
 * Normalise SKU directory keys.
 *
 * This function is the single canonical implementation.
 * It always applies strtoupper() internally so that photo
 * serving and file saving resolve to the same directory,
 * even if the caller forgot to normalise first.
 */
function normalizedSkuDirectory(string $skuNormalized): string
{
    $dir = preg_replace('/[^A-Z0-9_-]+/', '_', strtoupper(trim($skuNormalized)));
    return trim($dir, '_') ?: 'UNASSIGNED';
}

/* ── File-name sanitisation ──────────────────────────────── */
function sanitizeFilename(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'photo';
    }
    $clean = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
    return trim($clean, '._-') ?: 'photo';
}

/* ── Unified JSON response envelope ─────────────────────────
 *
 * All AJAX endpoints MUST use these helpers so that every
 * response follows the same contract:
 *
 *   Success: { "success": true,  "data": { ... }, "error": null }
 *   Failure: { "success": false, "data": null,   "error": "..." }
 *
 * Failure automatically sets the HTTP status code (default 400).
 */
function successResponse(mixed $data = null): never
{
    header('Content-Type: application/json; charset=utf-8');
    $response = ['ok' => true];
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $response[$key] = $value;
        }
    } elseif ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response);
    exit;
}

function errorResponse(string $message, int $code = 400): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $prefix = str_replace(__DIR__, '', debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file'] ?? '');
    error_log('errorResponse [' . $prefix . ']: ' . $message);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

/* ── PHP.ini byte-string parser ──────────────────────────── */
function iniBytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $last = strtolower(substr($value, -1));
    $num  = (float)$value;
    switch ($last) {
        case 'g': $num *= 1024; // no break
        case 'm': $num *= 1024; // no break
        case 'k': $num *= 1024;
    }
    return (int)$num;
}

/* ── Human-readable byte formatter ───────────────────────── */
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

/* ── Normalise the messy $_FILES array into a flat list ──── */
function normalizeUploadedFiles(array $uploaded): array
{
    if (!isset($uploaded['name']) || !is_array($uploaded['name'])) {
        return [];
    }
    $files = [];
    foreach ($uploaded['name'] as $index => $name) {
        $files[] = [
            'name'     => (string)$name,
            'type'     => (string)($uploaded['type'][$index] ?? ''),
            'tmp_name' => (string)($uploaded['tmp_name'][$index] ?? ''),
            'error'    => (int)($uploaded['error'][$index] ?? UPLOAD_ERR_NO_FILE),
            'size'     => (int)($uploaded['size'][$index] ?? 0),
        ];
    }
    return $files;
}

/* ──────────────────────────────────────────────────────────────
 *  GD-BASED IMAGE PROCESSING PIPELINE
 *  ──────────────────────────────────────────────────────────────
 */

/**
 * Convert an image file to PNG using GD.
 *
 * Reads the source image (JPG, PNG, WebP, or GIF), converts to
 * PNG, and writes to $destDir with a random filename.  The
 * original file is NOT deleted — the caller handles cleanup.
 *
 * MEMORY SAFETY:
 *   - Sets memory_limit to 512 M temporarily.
 *   - Probes dimensions via getimagesize() before allocating GD.
 *   - Downscales to MAX_DIM pixels on the longest edge.
 *
 * @param  string $srcPath  Absolute path to source image
 * @param  string $destDir  Absolute path to destination directory (must exist)
 * @return string|null      The stored filename or null on failure
 */
function imageConvertToPng(string $srcPath, string $destDir): ?string
{
    /* ── Boost memory headroom ────────────────────────────── */
    $oldLimit = @ini_get('memory_limit');
    @ini_set('memory_limit', '512M');

    if (!is_file($srcPath) || !is_dir($destDir)) {
        error_log('imageConvertToPng: invalid src or dest');
        @ini_set('memory_limit', (string)$oldLimit);
        return null;
    }

    /* ── Probe dimensions before decoding ─────────────────── */
    $maxDim = 1200;
    $dims   = @getimagesize($srcPath);
    $srcW   = $dims[0] ?? 0;
    $srcH   = $dims[1] ?? 0;

    $mime = detectUploadMimeType($srcPath, basename($srcPath));
    $gd   = null;

    switch ($mime) {
        case 'image/jpeg': $gd = @imagecreatefromjpeg($srcPath); break;
        case 'image/png':  $gd = @imagecreatefrompng($srcPath);  break;
        case 'image/webp': $gd = @imagecreatefromwebp($srcPath); break;
        case 'image/gif':  $gd = @imagecreatefromgif($srcPath);  break;
        default:
            error_log('imageConvertToPng: unsupported MIME ' . $mime);
            @ini_set('memory_limit', (string)$oldLimit);
            return null;
    }

    if (!$gd) {
        error_log('imageConvertToPng: GD failed to decode ' . $srcPath);
        @ini_set('memory_limit', (string)$oldLimit);
        return null;
    }

    /* ── Downscale if needed ──────────────────────────────── */
    if ($srcW > 0 && $srcH > 0 && ($srcW > $maxDim || $srcH > $maxDim)) {
        $scale = min($maxDim / $srcW, $maxDim / $srcH);
        $dstW  = (int)round($srcW * $scale);
        $dstH  = (int)round($srcH * $scale);
        $resampled = imagecreatetruecolor($dstW, $dstH);
        if ($resampled) {
            imagealphablending($resampled, false);
            imagesavealpha($resampled, true);
            imagecopyresampled($resampled, $gd, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
            imagedestroy($gd);
            $gd = $resampled;
        }
    }

    imagealphablending($gd, false);
    imagesavealpha($gd, true);

    $storedName = bin2hex(random_bytes(16)) . '.png';
    $destPath   = $destDir . '/' . $storedName;

    $ok = imagepng($gd, $destPath);
    imagedestroy($gd);

    @ini_set('memory_limit', (string)$oldLimit);

    if (!$ok) {
        error_log('imageConvertToPng: imagepng() failed for ' . $destPath);
        @unlink($destPath);
        return null;
    }

    return $storedName;
}

/**
 * Resize, compress, and strip EXIF from an image file using GD.
 *
 * Skips GIFs (would break animation). The processed file is written
 * to a temporary path and the caller is responsible for cleanup.
 *
 * @param  string $sourcePath  Absolute path to the source image.
 * @param  string $mimeType    MIME type (image/jpeg, image/png, image/webp).
 * @return array               ['ok' => bool, 'path' => ?string, 'message' => ?string]
 */
function compressUploadedImage(string $sourcePath, string $mimeType): array
{
    $maxWidth = 1200;
    $quality  = 75;

    if ($mimeType === 'image/gif') {
        return ['ok' => true, 'path' => $sourcePath];
    }

    $gd = null;
    switch ($mimeType) {
        case 'image/jpeg': $gd = @imagecreatefromjpeg($sourcePath); break;
        case 'image/png':  $gd = @imagecreatefrompng($sourcePath);  break;
        case 'image/webp': $gd = @imagecreatefromwebp($sourcePath); break;
        default:
            return ['ok' => false, 'message' => 'Unsupported image format.'];
    }

    if (!$gd) {
        return ['ok' => false, 'message' => 'Failed to decode image.'];
    }

    $origW = imagesx($gd);
    $origH = imagesy($gd);

    if ($origW > $maxWidth) {
        $newW = $maxWidth;
        $newH = (int)round($origH * ($maxWidth / $origW));
        $resized = imagecreatetruecolor($newW, $newH);
        if (!$resized) {
            imagedestroy($gd);
            return ['ok' => false, 'message' => 'Failed to allocate resized canvas.'];
        }
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $gd, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($gd);
        $gd = $resized;
    }

    $tempPath = dirname($sourcePath) . '/' . bin2hex(random_bytes(8)) . '_processed.tmp';
    $saved = false;

    switch ($mimeType) {
        case 'image/jpeg':
            imageinterlace($gd, true);
            $saved = imagejpeg($gd, $tempPath, $quality);
            break;
        case 'image/png':
            $saved = imagepng($gd, $tempPath, 6);
            break;
        case 'image/webp':
            $saved = imagewebp($gd, $tempPath, $quality);
            break;
    }

    imagedestroy($gd);

    if (!$saved) {
        @unlink($tempPath);
        return ['ok' => false, 'message' => 'Failed to write processed image.'];
    }

    return ['ok' => true, 'path' => $tempPath];
}

/**
 * Centralised upload processor.
 *
 * Handles the ENTIRE pipeline for a single uploaded file:
 *
 *   1. MIME validation (finfo magic-bytes)
 *   2. GD decode check (polyglot / execution-boundary defence)
 *   3. Image resize + compression (strips EXIF, caps at 1200px)
 *   4. Format conversion / pass-through according to
 *      PNG_ONLY_MODE and PNG_REJECT_NON_PNG constants
 *   5. Content-hash computation (SHA-256 for dedup)
 *   6. Temp-file cleanup
 *   7. Final storage write
 *
 * @param  array       $file     Single-entry file descriptor with keys:
 *                                name, tmp_name, size, error
 * @param  string      $sku      Normalised SKU for directory routing
 * @param  string|null $baseDir  Optional base directory override.
 *                               Defaults to PHOTO_UPLOAD_DIR constant.
 * @return array                 ['ok' => bool, 'stored_name' => ?string,
 *                                'mime_type' => ?string, 'file_size' => ?int,
 *                                'content_hash' => ?string, 'message' => ?string]
 */
function processUploadedPhoto(array $file, string $sku, ?string $baseDir = null): array
{
    /* ── Boost memory headroom ────────────────────────────── */
    $oldLimit = @ini_get('memory_limit');
    @ini_set('memory_limit', '512M');

    $originalName = (string)($file['name'] ?? 'photo');
    $tmp          = (string)($file['tmp_name'] ?? '');

    /* ── Validate upload integrity ────────────────────────── */
    if ($tmp === '' || !is_file($tmp)) {
        return ['ok' => false, 'message' => 'No file uploaded.'];
    }

    /* ── MIME validation via finfo ────────────────────────── */
    $mime = detectUploadMimeType($tmp, $originalName);
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    if (!in_array($mime, $allowedMimes, true)) {
        @unlink($tmp);
        return ['ok' => false, 'message' => $originalName . ' must be JPG, PNG, WebP, or GIF.'];
    }

    /* ── POLYGLOT PROTECTION: force GD decode ─────────────── */
    $raw = @file_get_contents($tmp);
    if ($raw === false || $raw === '') {
        @unlink($tmp);
        return ['ok' => false, 'message' => 'Failed to read uploaded file.'];
    }

    $gdCheck = @imagecreatefromstring($raw);
    if (!$gdCheck) {
        @unlink($tmp);
        return ['ok' => false, 'message' => $originalName . ' is not a valid image file and was rejected.'];
    }
    imagedestroy($gdCheck);
    unset($raw);

    /* ── Resize, compress, strip EXIF ──────────────────────── */
    $processed = compressUploadedImage($tmp, $mime);
    if (!$processed['ok']) {
        @unlink($tmp);
        return ['ok' => false, 'message' => $processed['message']];
    }
    if ($processed['path'] !== $tmp) {
        @unlink($tmp);
        $tmp = $processed['path'];
    }

    /* ── Content-hash (SHA-256) for deduplication ──────────── */
    $contentHash = @hash_file('sha256', $tmp);
    if ($contentHash === false) {
        $contentHash = null;
    }

    /* ── Ensure storage directory ─────────────────────────── */
    $photoDir  = $baseDir
        ?? (defined('PHOTO_UPLOAD_DIR') ? PHOTO_UPLOAD_DIR : (__DIR__ . '/../data/sku_photos'));
    $skuDirName = normalizedSkuDirectory($sku);
    $skuDir     = $photoDir . '/' . $skuDirName;

    if (!is_dir($skuDir) && !mkdir($skuDir, 0777, true) && !is_dir($skuDir)) {
        @unlink($tmp);
        return ['ok' => false, 'message' => 'Could not create storage directory.'];
    }

    /* ── Decide whether to convert or store as-is ─────────── */
    $isPng = $mime === 'image/png';

    if (PNG_REJECT_NON_PNG && !$isPng) {
        @unlink($tmp);
        return ['ok' => false, 'message' => 'Only PNG images are accepted. Convert ' . $originalName . ' to PNG and try again.'];
    }

    if (PNG_ONLY_MODE && !$isPng) {
        /* Convert non-PNG to PNG */
        $storedName = imageConvertToPng($tmp, $skuDir);
        if ($storedName === null) {
            @unlink($tmp);
            return ['ok' => false, 'message' => 'Failed to convert ' . $originalName . ' to PNG.'];
        }
        $finalSize = (int)@filesize($skuDir . '/' . $storedName);
        $dbMime    = 'image/png';
    } else {
        /* Store as-is (already PNG or PNG-only mode is off) */
        $extMap  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $ext     = $extMap[$mime] ?? 'bin';
        $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest    = $skuDir . '/' . $storedName;

        if (!rename($tmp, $dest)) {
            @unlink($tmp);
            return ['ok' => false, 'message' => 'Failed to write file to disk.'];
        }
        $finalSize = (int)@filesize($dest);
        $dbMime    = $mime;
    }

    /* ── Temp file is always cleaned up ───────────────────── */
    @unlink($tmp);

    @ini_set('memory_limit', (string)$oldLimit);

    return [
        'ok'           => true,
        'stored_name'  => $storedName,
        'mime_type'    => $dbMime,
        'file_size'    => $finalSize,
        'content_hash' => $contentHash,
        'message'      => 'Uploaded',
    ];
}
