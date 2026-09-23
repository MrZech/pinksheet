<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php'; require_once __DIR__ . '/square_sync.php'; require_once __DIR__ . '/square_webhook_lib.php';
header('Content-Type: application/json; charset=utf-8');
function squareWebhookResponse(int $code, array $body): never { http_response_code($code); echo json_encode($body); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { header('Allow: POST'); squareWebhookResponse(405, ['error' => 'Method Not Allowed']); }
$config = squareWebhookConfig();
if (!$config['enabled']) { squareWebhookResponse(503, ['error' => 'Webhook support is disabled']); }
$length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0); if ($length > $config['max_body_bytes']) { squareWebhookResponse(413, ['error' => 'Request body too large']); }
$raw = file_get_contents('php://input'); if ($raw === false || $raw === '') { squareWebhookResponse(400, ['error' => 'Request body is required']); }
if (strlen($raw) > $config['max_body_bytes']) { squareWebhookResponse(413, ['error' => 'Request body too large']); }
$signature = (string)($_SERVER['HTTP_X_SQUARE_HMACSHA256_SIGNATURE'] ?? '');
if (!squareWebhookVerifySignature($raw, $signature, $config['signature_key'], $config['notification_url'])) { squareWebhookResponse(403, ['error' => 'Forbidden']); }
try { $event = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); } catch (JsonException) { squareWebhookResponse(400, ['error' => 'Invalid JSON']); }
if (!is_array($event) || empty($event['event_id']) || empty($event['type'])) { squareWebhookResponse(400, ['error' => 'event_id and type are required']); }
try { ensureStorageWritable(); $pdo = pdoConnect(__DIR__ . '/data/intake.sqlite'); $claim = squareWebhookClaimEvent($pdo, $event); if ($claim === 'duplicate') { squareWebhookResponse(200, ['received' => true, 'duplicate' => true]); } squareWebhookDispatch($pdo, $event); squareWebhookResponse(200, ['received' => true, 'event_id' => $event['event_id'], 'type' => $event['type']]); } catch (Throwable $e) { squareWebhookLog('endpoint error event_id=' . ($event['event_id'] ?? '') . ' error=' . substr($e->getMessage(), 0, 1000)); squareWebhookResponse(500, ['error' => 'Webhook processing failed']); }
