<?php
declare(strict_types=1);

/**
 * Square Webhook Receiver
 *
 * SETUP INSTRUCTIONS — Square Developer Dashboard:
 *   1. Go to https://developer.squareup.com/apps → your app → Webhooks
 *   2. Add a new subscription with URL:   https://your-domain.com/webhooks/square.php
 *   3. Enable these event types:
 *        - order.created, order.updated, order.completed, order.fulfillment_updated
 *        - payment.created, payment.updated
 *        - inventory.count.updated
 *        - catalog.version.updated
 *   4. Copy the "Signature Key" and set it as SQUARE_WEBHOOK_SIGNATURE_KEY in .env
 *   5. Verify delivery: Square will POST a `test.webhook` event — check logs/square_sync.log
 *
 * REQUIREMENTS:
 *   - HTTPS (mandatory — Square refuses HTTP delivery)
 *   - PHP cURL extension
 *   - SQUARE_WEBHOOK_SIGNATURE_KEY set in .env
 *   - Webhook must be reachable from Square's servers (not firewalled)
 *
 * PRODUCTION HEADER NOTES:
 *   Square sends the signature in the "x-square-hmac-sha256-signature" header.
 *   PHP converts this to $_SERVER['HTTP_X_SQUARE_HMAC_SHA256_SIGNATURE'].
 *   Legacy header "x-square-hmacsha256-signature" is also supported.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../square_sync.php';
require_once __DIR__ . '/../square_webhook_service.php';

checkMaintenance(true);

// Warn if not HTTPS — Square only delivers to HTTPS URLs
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    squareSyncLog('Webhook received over HTTP (not HTTPS) — Square will not deliver to non-TLS endpoints');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$signatureHeaderRaw = $_SERVER['HTTP_X_SQUARE_HMAC_SHA256_SIGNATURE']
    ?? $_SERVER['HTTP_X_SQUARE_HMACSHA256_SIGNATURE']
    ?? $_SERVER['HTTP_X_SQUARE_HMAC_SHA256']
    ?? '';
if ($signatureHeaderRaw === '') {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Missing signature header']);
    exit;
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Empty request body']);
    exit;
}

$body = json_decode($rawBody, true);
if (!is_array($body)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$eventType = (string)($body['type'] ?? '');

$notificationUrl = squareWebhookNotificationUrl();
if (
    $notificationUrl !== ''
    && (
        str_starts_with($notificationUrl, 'http://')
        || str_contains($notificationUrl, '127.0.0.1')
        || str_contains($notificationUrl, 'localhost')
    )
) {
    squareSyncLog('Webhook notification URL is not a public HTTPS URL (' . $notificationUrl . ') — Square cannot deliver there and signatures will never match. Set SQUARE_WEBHOOK_NOTIFICATION_URL to the exact public HTTPS URL registered in Square.');
}
if (!squareWebhookVerify($rawBody, $signatureHeaderRaw, $notificationUrl)) {
    squareSyncLog('Webhook rejected: invalid signature from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ' (verified against URL ' . $notificationUrl . ')');
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Invalid signature']);
    exit;
}

if (!squareWebhookIsFresh($body)) {
    squareSyncLog('Webhook rejected: event is outside replay window for type ' . $eventType);
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Webhook event is outside replay window']);
    exit;
}

if ($eventType === 'test.webhook' || $eventType === 'webhook.test') {
    squareSyncLogEvent([
        'operation' => $eventType,
        'direction' => 'pull',
        'webhook_id' => (string)($body['event_id'] ?? ''),
        'status' => 'test_ok',
        'message' => 'Square webhook test verified',
    ]);
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'status' => 'test_ok']);
    exit;
}

try {
    $pdo = pdoConnect(__DIR__ . '/../data/intake.sqlite');
    squareSyncEnsureSchema($pdo);

    // Ensure webhook + sales history tables exist before any processing
    squareWebhookEnsureSchema($pdo);

    $result = squareWebhookProcess($pdo, $eventType, $body);

    squareSyncLog('Webhook ' . $eventType . ': ' . ($result['status'] ?? 'unknown') . ' — ' . ($result['message'] ?? ''));

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'status' => $result['status'] ?? 'ok',
        'message' => $result['message'] ?? '',
    ]);
} catch (Throwable $e) {
    squareSyncLog('Webhook error for ' . $eventType . ': ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Internal server error']);
}
