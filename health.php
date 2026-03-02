<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

http_response_code(MAINTENANCE_MODE ? 503 : 200);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'status' => MAINTENANCE_MODE ? 'maintenance' : 'ok',
    'maintenance' => MAINTENANCE_MODE,
    'max_query_length' => MAX_QUERY_LENGTH,
    'max_status_length' => MAX_STATUS_LENGTH,
    'suggestion_limit' => SUGGESTION_LIMIT,
    'preview_limit' => PREVIEW_LIMIT,
], JSON_THROW_ON_ERROR);
