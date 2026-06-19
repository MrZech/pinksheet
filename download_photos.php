<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance();
ensureStorageWritable();

const DB_PATH = __DIR__ . '/data/intake.sqlite';
const PHOTO_UPLOAD_DIR = __DIR__ . '/data/sku_photos';

$sku = normalizeSku((string)($_GET['sku'] ?? ''));
if ($sku === '') {
    http_response_code(400);
    exit('SKU is required.');
}

try {
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Database connection failed.');
}

// ═══════════════════════════════════════════════════════════════
// DEPRECATED — ZIP archive replaced by sequential individual
// photo downloads via the "Download all as PNG" button on the
// intake page (index.php). Each photo is served individually
// through photo.php?id=N&download=1.
//
// The original ZIP code is preserved in comments below for
// reference and can be restored if needed.
// ═══════════════════════════════════════════════════════════════

// Redirect back to the intake page with a helpful message.
$redirectSku = urlencode($sku);
header("Location: intake.php?sku={$redirectSku}#sku-photos");
exit;

// ── Original ZIP code (retained for reference, no longer active) ──
/*
function dosTime(int $timestamp): int { ... }
function buildStoreOnlyZip(array $files): string { ... }
... (full original implementation) ...
*/
