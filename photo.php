<?php
declare(strict_types=1);

$photoId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($photoId <= 0) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';
checkMaintenance();
ensureStorageWritable();

try {
    $pdo = pdoConnect(__DIR__ . '/data/intake.sqlite');
} catch (Throwable $e) {
    http_response_code(500);
    exit;
}

$stmt = $pdo->prepare('SELECT sku_normalized, stored_name FROM sku_photos WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $photoId]);
$photo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$photo) {
    http_response_code(404);
    exit;
}

$storedName = basename((string)($photo['stored_name'] ?? ''));
$skuDir = normalizedSkuDirectory((string)($photo['sku_normalized'] ?? ''));
$path = __DIR__ . '/data/sku_photos/' . $skuDir . '/' . $storedName;

if (!is_file($path)) {
    http_response_code(404);
    exit;
}

/* ── Optional on-the-fly thumbnail (?thumb=1) ───────────────────
   Downsizes the photo to a 320px JPEG, cached on disk under
   data/thumbs/ so repeat requests stream the cache.  If GD is
   unavailable or the image can't be decoded, we fall through and
   serve the original — never worse than today. */
if (isset($_GET['thumb']) && $_GET['thumb'] === '1') {
    $thumbsDir = __DIR__ . '/data/thumbs';
    if (!is_dir($thumbsDir)) {
        @mkdir($thumbsDir, 0777, true);
    }
    $thumbPath = $thumbsDir . '/' . $photoId . '-' . (int)@filemtime($path) . '-t320.jpg';
    if (is_dir($thumbsDir) && is_writable($thumbsDir)) {
        if (!is_file($thumbPath) && function_exists('imagecreatefromstring')) {
            $src = @imagecreatefromstring((string)@file_get_contents($path));
            if ($src !== false) {
                $w = imagesx($src);
                $h = imagesy($src);
                $scale = min(1.0, 320 / max($w, $h));
                $tw = max(1, (int)round($w * $scale));
                $th = max(1, (int)round($h * $scale));
                $dst = imagecreatetruecolor($tw, $th);
                $white = imagecolorallocate($dst, 255, 255, 255);
                imagefilledrectangle($dst, 0, 0, $tw, $th, $white);
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
                imagejpeg($dst, $thumbPath, 82);
                imagedestroy($dst);
                imagedestroy($src);
            }
        }
        if (is_file($thumbPath)) {
            $path = $thumbPath;
        }
    }
    // Thumbnails are immutable for a given photo version and can be reused
    // for a full day without another PHP/SQLite request.
    if ($path === $thumbPath && is_file($path)) {
        header('Cache-Control: public, max-age=86400, immutable');
    }
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
$mimeType = $mimeMap[$ext] ?? 'image/png';

$fileSize = filesize($path);
$fileMtime = filemtime($path);
/* Content-addressed ETag: changes whenever the file bytes change, so
   browsers that cached a broken response from an earlier buggy version
   re-fetch instead of being served a stale 304 forever. */
$etag = sprintf('W/"v2-%x-%x-%s"', $photoId, $fileMtime, substr(hash_file('sha256', $path), 0, 16));

ini_set('default_mimetype', $mimeType);
header_remove('Content-Type');
header('Content-Type: ' . $mimeType, true);
header('Content-Disposition: inline; filename="' . addcslashes($storedName, '\"') . '"');

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    header('Cache-Control: public, max-age=86400');
    header('ETag: ' . $etag);
    exit;
}

if (!headers_sent() && !isset($_GET['thumb'])) {
    header('Cache-Control: public, max-age=86400');
}
header('ETag: ' . $etag);
header('Content-Length: ' . $fileSize);

$fh = fopen($path, 'rb');
if ($fh === false) {
    http_response_code(500);
    exit;
}
fpassthru($fh);
fclose($fh);
