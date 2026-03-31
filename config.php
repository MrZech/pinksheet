<?php
declare(strict_types=1);

// Expose any runtime errors immediately so the server can report the failing endpoint.
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
// Increase upload limits for photo handling (may be overridden by server config).
@ini_set('upload_max_filesize', '16M');
@ini_set('post_max_size', '64M');
@ini_set('max_file_uploads', '50');

const MAINTENANCE_MODE = false;
const MAINTENANCE_MESSAGE = 'The intake system is temporarily offline for maintenance.';
const MAX_QUERY_LENGTH = 50;
const MAX_STATUS_LENGTH = 30;
const SUGGESTION_LIMIT = 40;
const PREVIEW_LIMIT = 7;

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

function checkMaintenance(bool $json = false): void
{
    if (!MAINTENANCE_MODE) {
        return;
    }
    http_response_code(503);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'maintenance',
            'message' => MAINTENANCE_MESSAGE,
        ]);
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo MAINTENANCE_MESSAGE;
    exit;
}
