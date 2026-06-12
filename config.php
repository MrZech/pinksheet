<?php
declare(strict_types=1);

// Expose any runtime errors immediately so the server can report the failing endpoint.
$env = getenv('APP_ENV') ?: 'production';
if ($env === 'development') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
}
// Increase upload limits for photo handling (may be overridden by server config).
@ini_set('upload_max_filesize', '16M');
@ini_set('post_max_size', '64M');
@ini_set('max_file_uploads', '50');

const MAINTENANCE_MODE = false;
const MAINTENANCE_MESSAGE = 'The intake system is temporarily offline for maintenance.';
const MAX_QUERY_LENGTH = 50;
const MAX_STATUS_LENGTH = 30;
const SUGGESTION_LIMIT = 40;
const PREVIEW_LIMIT = 500;

/**
 * Allowed CORS origins for QZ Tray signing endpoints.
 * Comma-separated list or '*' to allow any origin.
 * Read from .env variable QZ_ALLOWED_ORIGINS; falls back to '*' for
 * development convenience.  In production, set this to the exact
 * deployed origin (e.g. https://app.example.com).
 */
$qzAllowedOriginsEnv = getenv('QZ_ALLOWED_ORIGINS');
define('QZ_ALLOWED_ORIGINS', $qzAllowedOriginsEnv !== false && $qzAllowedOriginsEnv !== '' ? $qzAllowedOriginsEnv : '*');

function loadDotEnv(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $name = trim($parts[0]);
        if ($name === '' || !preg_match('/^[A-Z0-9_]+$/i', $name)) {
            continue;
        }

        $value = trim($parts[1]);
        if ($value !== '' && (
            ($value[0] === '"' && substr($value, -1) === '"')
            || ($value[0] === "'" && substr($value, -1) === "'")
        )) {
            $value = substr($value, 1, -1);
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

loadDotEnv(__DIR__ . '/.env');

/**
 * Ensure on-disk storage (SQLite + uploads + logs) is writable. Exit with 500 if not.
 */
function ensureStorageWritable(): void
{
    $paths = [
        __DIR__ . '/data',
        __DIR__ . '/data/sku_photos',
        __DIR__ . '/data/chunks',
        __DIR__ . '/logs',
    ];

    foreach ($paths as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            storageFatal('Could not create required directory: ' . $dir);
        }
        // Try to relax perms in case the host unpacked as read-only.
        @chmod($dir, 0777);
        if (!is_writable($dir)) {
            storageFatal('Directory is not writable: ' . $dir);
        }
    }

    $dbFile = __DIR__ . '/data/intake.sqlite';
    if (!file_exists($dbFile)) {
        // Touch to ensure the file exists with liberal perms; SQLite will initialize it.
        if (@touch($dbFile) === false) {
            storageFatal('Could not create database file: ' . $dbFile);
        }
    }
    @chmod($dbFile, 0666);
    if (!is_writable($dbFile)) {
        storageFatal('Database file is read-only: ' . $dbFile);
    }
}

function storageFatal(string $message): void
{
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message . "\n" . 'Fix: grant write access to data/, data/sku_photos/, data/chunks/, logs/, and data/intake.sqlite for the web/PHP user.';
    exit;
}

/**
 * Detect the MIME type of an uploaded file with graceful fallback.
 * Tries finfo (native), then mime_content_type, then extension-based guess.
 * Returns a string MIME type (e.g. "image/jpeg") or "application/octet-stream".
 */
function detectUploadMimeType(string $filePath, string $originalName): string
{
    if ($filePath === '' || !is_file($filePath)) {
        return 'application/octet-stream';
    }
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = @finfo_file($finfo, $filePath);
            @finfo_close($finfo);
            if ($mime !== false && $mime !== '') {
                return (string)$mime;
            }
        }
    }
    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($filePath);
        if ($mime !== false && $mime !== '') {
            return (string)$mime;
        }
    }
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $extensionMap = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];
    return $extensionMap[$extension] ?? 'application/octet-stream';
}

function safeJsonEncode(mixed $data, int $flags = 0, int $depth = 512): string
{
    if (!function_exists('json_encode')) {
        return '{"error":"json extension not available"}';
    }
    return json_encode($data, $flags, $depth);
}

function checkMaintenance(bool $json = false): void
{
    if (!MAINTENANCE_MODE) {
        return;
    }
    http_response_code(503);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo safeJsonEncode([
            'status' => 'maintenance',
            'message' => MAINTENANCE_MESSAGE,
        ]);
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo MAINTENANCE_MESSAGE;
    exit;
}
