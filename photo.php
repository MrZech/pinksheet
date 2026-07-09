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
    exit;
}

try {
    $pdo = pdoConnect(DB_PATH);
} catch (Throwable $e) {
    http_response_code(500);
    exit;
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
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT sku_normalized, original_name, stored_name FROM sku_photos WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $photoId]);
    $photo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$photo) {
        http_response_code(404);
        exit;
    }

    $storedName = basename((string)($photo['stored_name'] ?? ''));
    $skuDir = normalizedSkuDirectory((string)($photo['sku_normalized'] ?? ''));
    $path = PHOTO_UPLOAD_DIR . '/' . $skuDir . '/' . $storedName;

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

    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        header('Cache-Control: public, max-age=' . PHOTO_CACHE_MAX_AGE);
        header('ETag: ' . $etag);
        exit;
    }

    header('Cache-Control: public, max-age=' . PHOTO_CACHE_MAX_AGE);
    header('ETag: ' . $etag);
    header('Content-Type: ' . $mimeType, true);
    header('Content-Length: ' . (string)$fileSize);
    readfile($path);
} catch (Throwable $e) {
    http_response_code(500);
    exit;
}
