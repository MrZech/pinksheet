<?php
/**
 * ebay_photo_serve.php — Serve eBay listing images with caching
 *
 * GET ?id=N
 * Looks up the image in ebay_listing_images and streams it with
 * ETag + Cache-Control headers for efficient browser caching.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance();

const SERVE_DB = __DIR__ . '/data/intake.sqlite';
const SERVE_DIR = __DIR__ . '/data/ebay_listing_photos';

$photoId = max(0, (int)($_GET['id'] ?? 0));
if ($photoId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid photo ID.';
    exit;
}

try {
    $pdo = new PDO('sqlite:' . SERVE_DB, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $stmt = $pdo->prepare("SELECT sku_normalized, original_name, stored_name, mime_type FROM ebay_listing_images WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $photoId]);
    $photo = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Database error.';
    exit;
}

if (!$photo) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Photo not found.';
    exit;
}

$skuDir = preg_replace('/[^A-Z0-9_-]+/', '_', (string)$photo['sku_normalized']);
$skuDir = trim($skuDir, '_') ?: 'UNASSIGNED';
$filePath = SERVE_DIR . '/' . $skuDir . '/' . basename((string)$photo['stored_name']);

if (!is_file($filePath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'File not found on disk.';
    exit;
}

$mime = $photo['mime_type'] ?: 'application/octet-stream';
$size = filesize($filePath);
$etag = '"' . md5_file($filePath) . '"';
$lastModified = gmdate('D, d M Y H:i:s', filemtime($filePath)) . ' GMT';

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('ETag: ' . $etag);
header('Last-Modified: ' . $lastModified);
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');

// Check conditional GET
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}
if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
    $since = strtotime((string)$_SERVER['HTTP_IF_MODIFIED_SINCE']);
    if ($since !== false && $since >= filemtime($filePath)) {
        http_response_code(304);
        exit;
    }
}

readfile($filePath);
