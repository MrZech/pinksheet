<?php
/**
 * upload.php — AJAX image upload with automatic PNG conversion
 *
 * Accepts JPG, PNG, WebP, or GIF input; validates type/size; converts
 * to PNG using the shared imageConvertToPng() helper (GD); stores in
 * data/ebay_images/<sku>/; returns JSON with the PNG file path.
 * All temporary files are cleaned up immediately.
 *
 * POST params:
 *   photo       — uploaded file
 *   sku         — normalized SKU string
 *   csrf_token  — CSRF token
 *
 * Success: { "ok": true,  "id": "<hash>.png", "url": "ebay_serve.php?sku=ABC&file=<hash>.png", "name": "photo.png", "size": N }
 * Failure: { "ok": false, "message": "..." }
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance(true);
ensureStorageWritable();

header('Content-Type: application/json; charset=utf-8');
require_csrf();

const UPLOAD_DIR  = __DIR__ . '/data/ebay_images';
const MAX_BYTES   = 16 * 1024 * 1024;
const INPUT_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

function fail(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg]);
    exit;
}

/* ── Validate input ──────────────────────────────────────────── */
$sku = normalizeSku((string)($_POST['sku'] ?? ''));
if ($sku === '') fail('SKU is required.');

if (!isset($_FILES['photo'])) fail('No file uploaded.');

$f    = $_FILES['photo'];
$name = (string)($f['name'] ?? 'photo');
$err  = $f['error'] ?? UPLOAD_ERR_NO_FILE;

if ($err === UPLOAD_ERR_NO_FILE)      fail('No file selected.');
if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) fail($name . ' exceeds size limit.');
if ($err !== UPLOAD_ERR_OK)           fail($name . ' upload error (code ' . $err . ').');

$size = (int)($f['size'] ?? 0);
if ($size <= 0 || $size > MAX_BYTES) fail($name . ' is too large (' . $size . ' bytes).');

$tmp = (string)($f['tmp_name'] ?? '');
if (!is_uploaded_file($tmp)) fail($name . ' validation failed.');

$mime = detectUploadMimeType($tmp, $name);
if (!in_array($mime, INPUT_TYPES, true)) {
    @unlink($tmp);
    fail($name . ' must be JPG, PNG, WebP, or GIF.');
}

// PNG-only enforcement: reject non-PNG if configured
if (PNG_REJECT_NON_PNG && $mime !== 'image/png') {
    @unlink($tmp);
    fail('Only PNG images are accepted. Convert ' . $name . ' to PNG and try again.');
}

/* ── Ensure storage directory ────────────────────────────────── */
if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0777, true) && !is_dir(UPLOAD_DIR)) {
    error_log('upload.php: failed to create ' . UPLOAD_DIR);
    fail('Server error.', 500);
}
$skuDir = UPLOAD_DIR . '/' . preg_replace('/[^A-Z0-9_-]+/', '_', $sku);
$skuDir = rtrim($skuDir, '_') ?: UPLOAD_DIR . '/UNASSIGNED';
if (!is_dir($skuDir) && !mkdir($skuDir, 0777, true) && !is_dir($skuDir)) {
    error_log('upload.php: failed to create ' . $skuDir);
    fail('Server error.', 500);
}

/* ── Convert to PNG using shared helper ───────────────────────── */
if (PNG_ONLY_MODE && $mime !== 'image/png') {
    $stored = imageConvertToPng($tmp, $skuDir);
    if ($stored === null) {
        @unlink($tmp);
        fail('Failed to convert image to PNG.', 500);
    }
} else {
    // PNG-only mode off or already PNG — store as-is
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? 'bin';
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest   = $skuDir . '/' . $stored;
    if (!move_uploaded_file($tmp, $dest)) {
        fail('Failed to save file.', 500);
    }
}

@unlink($tmp); // always clean up temp

$finalSize = (int)@filesize($skuDir . '/' . $stored);
$urlSku    = urlencode($sku);
$urlFile   = urlencode($stored);

echo json_encode([
    'ok'   => true,
    'id'   => $stored,
    'url'  => "ebay_serve.php?sku={$urlSku}&file={$urlFile}",
    'name' => pathinfo($name, PATHINFO_FILENAME) . '.png',
    'size' => $finalSize,
]);
