<?php
declare(strict_types=1);

/**
 * RSA-SHA512 signing endpoint for QZ Tray.
 *
 * QZ Tray requires every print/sign action to be cryptographically signed
 * by the server.  This endpoint accepts the signing request string sent by
 * the QZ Tray client library, signs it with the private key in
 * qz-signing/private-key.pem using RSA-SHA512, and returns the Base64-
 * encoded signature.
 *
 * POST /api/qz/sign
 *
 * Request body (JSON):
 *   { "request": "<signing request string>" }
 *
 * Response: text/plain — Base64 signature on success.
 *
 * Errors (text/plain):
 *   400 — Invalid or missing request body / payload / origin
 *   503 — Private key not configured
 *   500 — Signing failure
 */

require_once __DIR__ . '/../../config.php';
checkMaintenance(true);

/* ── CORS headers ──────────────────────────────────────────── */
$allowedOrigins = explode(',', QZ_ALLOWED_ORIGINS);
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

/* Build the list of acceptable origins */
$acceptableOrigins = [];
if (QZ_ALLOWED_ORIGINS !== '*') {
    foreach ($allowedOrigins as $o) {
        $o = trim($o);
        if ($o !== '') {
            $acceptableOrigins[] = $o;
        }
    }
}
/* Fallback: same-origin derived from the request */
if (empty($acceptableOrigins)) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $acceptableOrigins[] = $scheme . '://' . $host;
}

if ($origin === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Origin header is required for CORS requests.';
    exit;
}
$matched = false;
foreach ($acceptableOrigins as $allowed) {
    if ($origin === $allowed) {
        $matched = true;
        break;
    }
}
if (!$matched) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Origin not allowed.';
    exit;
}
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Method not allowed. Use POST.';
    exit;
}

/* ── Constants ──────────────────────────────────────────────── */
const QZ_SIGN_MAX_BYTES = 64 * 1024;        // 64 KB max signing request
const QZ_PRIVATE_KEY_FILE = __DIR__ . '/../../qz-signing/private-key.pem';

/* ── Validate Content-Type ──────────────────────────────────── */
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') === false) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Content-Type must be application/json.';
    exit;
}

/* ── Parse input ─────────────────────────────────────────────── */
$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Request body is required.';
    exit;
}

$input = json_decode($rawBody, true);
if (!is_array($input)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid JSON request body.';
    exit;
}

$request = isset($input['request']) && is_string($input['request']) ? $input['request'] : '';
if ($request === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'The "request" field is required and must be a non-empty string.';
    exit;
}

if (strlen($request) > QZ_SIGN_MAX_BYTES) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo sprintf('Signing request is too large (max %d KB).', QZ_SIGN_MAX_BYTES / 1024);
    exit;
}

/* ── Load private key ────────────────────────────────────────── */
if (!is_file(QZ_PRIVATE_KEY_FILE) || !is_readable(QZ_PRIVATE_KEY_FILE)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'QZ signing is not configured. Generate a certificate and private key through QZ Tray > Advanced > Site Manager.';
    exit;
}

$privateKey = file_get_contents(QZ_PRIVATE_KEY_FILE);
if ($privateKey === false || $privateKey === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Could not read the QZ private key file.';
    exit;
}

/* ── Sign ────────────────────────────────────────────────────── */
$signature = null;
$ok = openssl_sign($request, $signature, $privateKey, OPENSSL_ALGO_SHA512);

if (!$ok || $signature === null) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Could not sign the QZ request using the configured private key.';
    exit;
}

/* ── Response ────────────────────────────────────────────────── */
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo base64_encode($signature);
