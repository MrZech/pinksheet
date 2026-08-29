<?php
declare(strict_types=1);

/**
 * Backward-compatible Square webhook endpoint.
 *
 * Existing Square subscriptions may still use /square_webhook.php. The
 * canonical implementation is /webhooks/square.php; this adapter keeps the
 * old URL working without duplicating webhook logic.
 */
$receiver = __DIR__ . '/webhooks/square.php';
if (!is_file($receiver)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Webhook receiver is not installed']);
    exit;
}

require $receiver;
