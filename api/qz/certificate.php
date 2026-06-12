<?php
declare(strict_types=1);

/**
 * QZ Tray signing certificate endpoint.
 *
 * Serves the RSA certificate (digital-certificate.txt) as plain text
 * for the QZ Tray client library's security handshake.
 *
 * GET /api/qz/certificate
 */

require_once __DIR__ . '/../../config.php';
checkMaintenance(true);

/* ── CORS headers ──────────────────────────────────────────── */
$allowedOrigins = explode(',', QZ_ALLOWED_ORIGINS);
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && (QZ_ALLOWED_ORIGINS === '*' || in_array($origin, $allowedOrigins, true))) {
    header('Access-Control-Allow-Origin: ' . ($origin !== '' && QZ_ALLOWED_ORIGINS !== '*' ? $origin : '*'));
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Method not allowed. Use GET.';
    exit;
}

/* ── Load and serve certificate ────────────────────────────── */
$certFile = __DIR__ . '/../../qz-signing/digital-certificate.txt';

if (!is_file($certFile) || !is_readable($certFile)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'QZ signing is not configured. Generate a certificate through QZ Tray > Advanced > Site Manager and save it to qz-signing/digital-certificate.txt.';
    exit;
}

$certContents = file_get_contents($certFile);
if ($certContents === false || trim($certContents) === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'QZ certificate file is empty or unreadable.';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo $certContents;
