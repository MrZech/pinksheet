<?php
declare(strict_types=1);

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
