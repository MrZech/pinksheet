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
