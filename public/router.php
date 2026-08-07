<?php
declare(strict_types=1);

/**
 * Pinksheet — front controller / router.
 *
 * This is the single public entry point.  Point your web server's document
 * root at this public/ directory and route everything through router.php:
 *
 *   PHP built-in server:
 *     php -S 127.0.0.1:8765 -t public public/router.php
 *
 *   Apache:  DocumentRoot = public/, .htaccess rewrites to router.php
 *   nginx:   root public/; try_files $uri $uri/ /router.php?$query_string;
 *
 * Only whitelisted entry points are ever reachable.  Everything sensitive
 * (data/, logs/, .env, qz-signing/, scripts/, php-8.5.4/, _quarantine/,
 * tmp/, vendor/, composer.*, .git) is unreachable over HTTP.
 *
 * No sign-in is required — the app works for anyone who can reach it.  The
 * only externally hit endpoint is the Square webhook receiver (which
 * Square's servers hit directly and which is protected by its own HMAC
 * signature verification).
 */

$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rawurldecode($uri);

/* ── Never serve the router itself, dotfiles, or path traversal ── */
if ($path === '/router.php' || $path === '/.htaccess' || str_contains($path, '..')) {
    http_response_code(404);
    exit;
}

$repoRoot = dirname(__DIR__);

/* ── Static assets (only from assets/, never from anywhere else) ── */
if (str_starts_with($path, '/assets/')) {
    $rel = substr($path, strlen('/assets/'));
    if ($rel !== '' && !str_contains($rel, '/') && !str_contains($rel, '\\')) {
        $file = $repoRoot . '/assets/' . $rel;
        if (is_file($file)) {
            serveFile($file);
        }
    }
    http_response_code(404);
    exit;
}

/* ── Map URL path -> real file ─────────────────────────────────── */
$target     = null;
$publicPath = $path;

if ($path === '/') {
    $target     = $repoRoot . '/index.php';
    $publicPath = '/index.php';
} elseif (preg_match('#^/webhooks/([A-Za-z0-9_\-]+\.php)$#', $path, $m)) {
    if ($m[1] !== 'square.php') {
        http_response_code(404);
        exit;
    }
    $target = $repoRoot . '/webhooks/square.php';
} elseif (preg_match('#^/api/qz/([A-Za-z0-9_\-]+\.php)$#', $path, $m)) {
    if (!in_array($m[1], ['certificate.php', 'sign.php'], true)) {
        http_response_code(404);
        exit;
    }
    $target = $repoRoot . '/api/qz/' . $m[1];
} elseif ($path === '/assets/zpl.php') {
    $target = $repoRoot . '/assets/zpl.php';
} elseif (preg_match('#^/([A-Za-z0-9_\-]+\.php)$#', $path, $m)) {
    $name = $m[1];
    /* Library / CLI-only files must never be reachable over HTTP. */
    $blocked = [
        'square_sync.php',
        'square_sync_queue.php',
        'square_webhook_service.php',
        'square_webhook.php', // legacy receiver, superseded by webhooks/square.php
        'square_audit.php',
        'migrate_ebay_status.php',
    ];
    if (in_array($name, $blocked, true)) {
        http_response_code(404);
        exit;
    }
    $target = $repoRoot . '/' . $name;
} else {
    http_response_code(404);
    exit;
}

if ($target === null || !is_file($target)) {
    http_response_code(404);
    exit;
}

/* ── Shared config: hardened sessions, security headers, .env ─── */
require_once $repoRoot . '/config.php';

/* ── Present the mapped script to application code ─────────────── */
$_SERVER['SCRIPT_NAME']     = $publicPath;
$_SERVER['PHP_SELF']        = $publicPath;
$_SERVER['SCRIPT_FILENAME'] = $target;

/* Run the endpoint.  The target calls require_once config.php, which
   is a no-op because we already loaded it above. */
require $target;

/**
 * Stream a static asset with ETag caching.
 */
function serveFile(string $file): void
{
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $map = [
        'css'   => 'text/css; charset=utf-8',
        'js'    => 'application/javascript; charset=utf-8',
        'svg'   => 'image/svg+xml',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'map'   => 'application/json',
        'txt'   => 'text/plain; charset=utf-8',
    ];
    header('Content-Type: ' . ($map[$ext] ?? 'application/octet-stream'));
    header('Cache-Control: public, max-age=3600');
    $etag = '"' . dechex(filesize($file)) . '-' . dechex(filemtime($file)) . '"';
    header('ETag: ' . $etag);
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }
    readfile($file);
    exit;
}
