<?php
/**
 * save_layout.php — Persist image positions for eBay Listing Image Composer
 *
 * Accepts a SKU and JSON-encoded image positions, saves to
 * data/layouts/<sku>.json (untracked by Git).
 *
 * POST params:
 *   sku        — normalized SKU string
 *   positions  — JSON string: [{"id":N,"x":100,"y":200,"src":"...","name":"..."}, ...]
 *   csrf_token — CSRF token
 *
 * Success: { "ok": true }
 * Failure: { "ok": false, "message": "..." }
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance(true);
ensureStorageWritable();

require_csrf();

const LAYOUT_DIR = __DIR__ . '/data/layouts';

$sku = normalizeSku((string)($_POST['sku'] ?? ''));
if ($sku === '') {
    errorResponse('SKU is required.');
}

$raw = (string)($_POST['positions'] ?? '');
if ($raw === '') {
    errorResponse('positions JSON is required.');
}

$positions = json_decode($raw, true);
if (!is_array($positions)) {
    errorResponse('Invalid positions JSON.');
}

if (!is_dir(LAYOUT_DIR) && !mkdir(LAYOUT_DIR, 0777, true) && !is_dir(LAYOUT_DIR)) {
    error_log('save_layout.php: failed to create ' . LAYOUT_DIR);
    errorResponse('Server error.', 500);
}

$file = LAYOUT_DIR . '/' . $sku . '.json';
$written = @file_put_contents($file, json_encode($positions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

if ($written === false) {
    error_log('save_layout.php: failed to write ' . $file);
    errorResponse('Write failed.', 500);
}

successResponse(['count' => count($positions)]);
