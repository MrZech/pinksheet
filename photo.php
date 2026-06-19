<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance();
ensureStorageWritable();

const DB_PATH = __DIR__ . '/data/intake.sqlite';
const PHOTO_UPLOAD_DIR = __DIR__ . '/data/sku_photos';
const PHOTO_CACHE_MAX_AGE = 31536000; // 1 year

$photoId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($photoId <= 0) {
    http_response_code(404);
    exit('Photo not found.');
}

try {
    $pdo = pdoConnect(DB_PATH);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Database connection failed.');
}

try {
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
} catch (Throwable $e) {
    http_response_code(500);
    exit('Schema initialization failed.');
}

try {
    $stmt = $pdo->prepare('SELECT sku_normalized, original_name, stored_name, mime_type FROM sku_photos WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $photoId]);
    $photo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$photo) {
        http_response_code(404);
        exit('Photo not found.');
    }

    $skuDir = normalizedSkuDirectory((string)($photo['sku_normalized'] ?? ''));
    $storedName = basename((string)($photo['stored_name'] ?? ''));
    $path = PHOTO_UPLOAD_DIR . '/' . $skuDir . '/' . $storedName;
    if (!is_file($path)) {
        http_response_code(404);
        exit('Photo file is missing.');
    }

    $mimeType = (string)($photo['mime_type'] ?? 'application/octet-stream');
    $fileMtime = filemtime($path);
    $forceDownload = (int)($_GET['download'] ?? 0) === 1;

    /* ── In-memory PNG conversion for legacy non-PNG files ────── */
    // If PNG_ONLY_MODE is active and the file on disk isn't PNG, convert in-memory.
    // This handles legacy files that weren't migrated or were uploaded before conversion.
    $ext = strtolower(pathinfo($storedName, PATHINFO_EXTENSION));
    $isLegacyFormat = in_array($ext, ['jpg', 'jpeg', 'webp', 'gif'], true);

    if (PNG_ONLY_MODE && $isLegacyFormat) {
        $gd = null;
        switch ($ext) {
            case 'jpg':
            case 'jpeg': $gd = @imagecreatefromjpeg($path); break;
            case 'webp':  $gd = @imagecreatefromwebp($path); break;
            case 'gif':   $gd = @imagecreatefromgif($path);  break;
        }
        if ($gd) {
            imagealphablending($gd, false);
            imagesavealpha($gd, true);
            $pngData = null;
            $captured = false;
            ob_start();
            if (imagepng($gd)) {
                $pngData = ob_get_contents();
                $captured = true;
            }
            ob_end_clean();
            imagedestroy($gd);

            if ($captured && $pngData !== false && $pngData !== '') {
                $fileSize = strlen($pngData);
                // Use deterministic ETag based on photo ID + mtime (same as before, but size may differ)
                $etag = sprintf('W/"%x-%x-%x"', $photoId, $fileMtime, $fileSize);

                if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
                    http_response_code(304);
                    header('Cache-Control: public, max-age=' . PHOTO_CACHE_MAX_AGE);
                    header('ETag: ' . $etag);
                    exit;
                }

                $downloadName = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)($photo['original_name'] ?? 'photo'));
                $downloadName = trim($downloadName, '._-') ?: 'photo';
                $downloadName = pathinfo($downloadName, PATHINFO_FILENAME) . '.png';

                header('Cache-Control: public, max-age=' . PHOTO_CACHE_MAX_AGE);
                header('ETag: ' . $etag);
                header('Content-Type: image/png');
                header('Content-Length: ' . (string)$fileSize);
                header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="' . $downloadName . '"');
                echo $pngData;
                exit;
            }
            // Fall through to normal serving if conversion fails
            error_log('photo.php: in-memory PNG conversion failed for id=' . $photoId . ', falling back to original');
        }
    }

    // Normal serving path (PNG already or conversion skipped/not needed)
    $fileSize = filesize($path);
    $etag = sprintf('W/"%x-%x-%x"', $photoId, $fileMtime, $fileSize);

    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        header('Cache-Control: public, max-age=' . PHOTO_CACHE_MAX_AGE);
        header('ETag: ' . $etag);
        exit;
    }

    $originalName = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)($photo['original_name'] ?? 'photo'));
    $downloadName = trim((string)$originalName, '._-');
    if ($downloadName === '') {
        $downloadName = 'photo';
    }

    header('Cache-Control: public, max-age=' . PHOTO_CACHE_MAX_AGE);
    header('ETag: ' . $etag);
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . (string)$fileSize);
    header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="' . $downloadName . '"');
    readfile($path);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Internal server error.');
}
