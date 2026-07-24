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

$ext = strtolower(pathinfo($storedName, PATHINFO_EXTENSION));
$mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
$mimeType = $mimeMap[$ext] ?? 'image/png';

$fileSize = filesize($path);
$fileMtime = filemtime($path);
$etag = sprintf('W/"%x-%x-%x"', $photoId, $fileMtime, $fileSize);

ini_set('default_mimetype', $mimeType);
header_remove('Content-Type');
header('Content-Type: ' . $mimeType, true);

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    header('Cache-Control: public, max-age=31536000');
    header('ETag: ' . $etag);
    exit;
}

header('Cache-Control: public, max-age=31536000');
header('ETag: ' . $etag);
header('Content-Length: ' . $fileSize);

$fh = fopen($path, 'rb');
if ($fh === false) {
    http_response_code(500);
    exit;
}
fpassthru($fh);
fclose($fh);
