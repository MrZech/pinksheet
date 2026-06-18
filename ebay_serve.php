<?php
/**
 * ebay_serve.php — Serve eBay listing images with caching
 *
 * GET ?sku=SKU&file=filename
 * Streams an image from data/ebay_images/<sku>/<file> with ETag caching.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance();

$sku  = preg_replace('/[^A-Z0-9_-]+/', '_', normalizeSku((string)($_GET['sku'] ?? '')));
$file = basename((string)($_GET['file'] ?? ''));

if ($sku === '' || $file === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Missing parameters.';
    exit;
}

$path = __DIR__ . '/data/ebay_images/' . $sku . '/' . $file;

if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'File not found.';
    exit;
}

$mime = 'application/octet-stream';
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$map = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
if (isset($map[$ext])) $mime = $map[$ext];

$size = filesize($path);
$etag = '"' . md5_file($path) . '"';
$lm   = gmdate('D, d M Y H:i:s', filemtime($path)) . ' GMT';

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('ETag: ' . $etag);
header('Last-Modified: ' . $lm);
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}
if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
    $since = strtotime((string)$_SERVER['HTTP_IF_MODIFIED_SINCE']);
    if ($since !== false && $since >= filemtime($path)) {
        http_response_code(304);
        exit;
    }
}

readfile($path);
