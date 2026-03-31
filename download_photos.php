<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance();
ensureStorageWritable();

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

function dosTime(int $timestamp): int
{
    $dt = getdate($timestamp);
    return (($dt['year'] - 1980) << 25) | ($dt['mon'] << 21) | ($dt['mday'] << 16) | ($dt['hours'] << 11) | ($dt['minutes'] << 5) | ($dt['seconds'] >> 1);
}

function buildStoreOnlyZip(array $files): string
{
    // $files: array of ['name' => path inside zip, 'path' => source file]
    $zipData = '';
    $central = '';
    $offset = 0;
    $added = 0;
    foreach ($files as $file) {
        $name = $file['name'];
        $path = $file['path'];
        $data = file_get_contents($path);
        if ($data === false) {
            continue;
        }
        $added++;
        $crc = crc32($data);
        $size = strlen($data);
        $time = dosTime(filemtime($path) ?: time());

        $localHeader = pack(
            'VvvvVVVvv',
            0x04034b50, // signature
            20, // version needed
            0,  // general flags
            0,  // compression 0 = store
            $time, // dos time/date
            $crc,
            $size,
            $size,
            strlen($name),
            0 // extra length
        );
        $zipData .= $localHeader . $name . $data;

        $centralHeader = pack(
            'VvvvvVVVvvvvvVV',
            0x02014b50, // central file header signature
            0, // version made
            20, // version needed
            0, // flags
            0, // compression
            $time,
            $crc,
            $size,
            $size,
            strlen($name),
            0, // extra
            0, // comment len
            0, // disk start
            0, // internal attrs
            0, // external attrs
            $offset
        );
        $central .= $centralHeader . $name;
        $offset += strlen($localHeader) + strlen($name) + $size;
    }
    if ($added === 0) {
        return '';
    }
    $end = pack(
        'VvvvvVVv',
        0x06054b50, // end of central dir
        0, // disk number
        0, // disk with central start
        $added,
        $added,
        strlen($central),
        strlen($zipData),
        0 // comment length
    );
    return $zipData . $central . $end;
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
$zipFiles = [];
$added = 0;
foreach ($photos as $idx => $photo) {
    $stored = basename((string)($photo['stored_name'] ?? ''));
    $orig = (string)($photo['original_name'] ?? 'photo');
    $safeOrig = preg_replace('/[^A-Za-z0-9._-]+/', '_', $orig);
    if ($safeOrig === '') {
        $safeOrig = 'photo';
    }
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
    $zipFiles[] = ['name' => $pathInZip, 'path' => $filePath];
    $added++;
}

if ($added === 0) {
    http_response_code(404);
    exit('No photo files found to download.');
}

$downloadName = 'sku_' . $sku . '_photos.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if (class_exists('ZipArchive')) {
    $zipPath = tempnam(sys_get_temp_dir(), 'sku_zip_');
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        exit('Could not create zip file.');
    }
    foreach ($zipFiles as $file) {
        $zip->addFile($file['path'], $file['name']);
    }
    $zip->close();
    $size = (int)filesize($zipPath);
    header('Content-Length: ' . (string)$size);
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

// Fallback: build a store-only zip manually (no compression)
$zipData = buildStoreOnlyZip($zipFiles);
if ($zipData === '') {
    http_response_code(404);
    exit('No photo files found to download.');
}
header('Content-Length: ' . strlen($zipData));
echo $zipData;
exit;
