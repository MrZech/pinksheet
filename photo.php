<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance();
ensureStorageWritable();

const DB_PATH = __DIR__ . '/data/intake.sqlite';
const PHOTO_UPLOAD_DIR = __DIR__ . '/data/sku_photos';

function normalizedSkuDirectory(string $skuNormalized): string
{
    $dir = preg_replace('/[^A-Z0-9_-]+/', '_', strtoupper(trim($skuNormalized)));
    return trim((string)$dir, '_') ?: 'UNASSIGNED';
}

$photoId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($photoId <= 0) {
    http_response_code(404);
    exit('Photo not found.');
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
$originalName = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)($photo['original_name'] ?? 'photo'));
$downloadName = trim((string)$originalName, '._-');
if ($downloadName === '') {
    $downloadName = 'photo';
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: inline; filename="' . $downloadName . '"');
readfile($path);
