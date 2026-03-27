<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance();

const DB_PATH = __DIR__ . '/data/intake.sqlite';
const PHOTO_UPLOAD_DIR = __DIR__ . '/data/sku_photos';

function normalizeSku(string $sku): string
{
    return strtoupper(trim($sku));
}

function normalizedSkuDirectory(string $skuNormalized): string
{
    $dir = preg_replace('/[^A-Z0-9_-]+/', '_', $skuNormalized);
    return trim((string)$dir, '_') ?: 'UNASSIGNED';
}

$sku = normalizeSku((string)($_GET['sku'] ?? ''));
if ($sku === '') {
    http_response_code(400);
    exit('SKU is required.');
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('Zip support is not available on this server.');
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
$stmt = $pdo->prepare('SELECT original_name, stored_name FROM sku_photos WHERE sku_normalized = :sku ORDER BY id ASC');
$stmt->execute(['sku' => $sku]);
$photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$photos) {
    http_response_code(404);
    exit('No photos found for this SKU.');
}

$skuDir = PHOTO_UPLOAD_DIR . '/' . normalizedSkuDirectory($sku);
$zipPath = tempnam(sys_get_temp_dir(), 'sku_zip_');
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Could not create zip file.');
}
$added = 0;
foreach ($photos as $idx => $photo) {
    $stored = basename((string)($photo['stored_name'] ?? ''));
    $orig = (string)($photo['original_name'] ?? 'photo');
    $safeOrig = preg_replace('/[^A-Za-z0-9._-]+/', '_', $orig);
    if ($safeOrig === '') {
        $safeOrig = 'photo';
    }
    // ensure unique inside zip
    $pathInZip = $safeOrig;
    if (strpos($pathInZip, '.') === false) {
        $pathInZip .= '.jpg';
    }
    if ($added > 0) {
        $pathInZip = pathinfo($pathInZip, PATHINFO_FILENAME) . '_' . ($idx + 1) . '.' . pathinfo($pathInZip, PATHINFO_EXTENSION);
    }
    $filePath = $skuDir . '/' . $stored;
    if (!is_file($filePath)) {
        continue;
    }
    $zip->addFile($filePath, $pathInZip);
    $added++;
}
$zip->close();

if ($added === 0) {
    @unlink($zipPath);
    http_response_code(404);
    exit('No photo files found to download.');
}

$downloadName = 'sku_' . $sku . '_photos.zip';
$size = (int)filesize($zipPath);
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . (string)$size);
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
readfile($zipPath);
@unlink($zipPath);
exit;
