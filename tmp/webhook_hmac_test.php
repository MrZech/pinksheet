<?php
declare(strict_types=1);

/**
 * Local HMAC verification test for webhooks/square.php.
 *
 * Signs a `test.webhook` payload exactly the way Square does:
 *   signature = base64( hmac_sha256( notificationUrl . rawBody, signatureKey ) )
 * then POSTs it to the local dev server and asserts the response.
 *
 * Usage:
 *   php -S 127.0.0.1:8765 -t public public/router.php   (in another shell)
 *   php tmp/webhook_hmac_test.php
 */

require_once __DIR__ . '/../config.php';

const BASE_URL = 'http://127.0.0.1:8765';
const ENDPOINT = BASE_URL . '/webhooks/square.php';

$secret = (string)getenv('SQUARE_WEBHOOK_SIGNATURE_KEY');
$notificationUrl = (string)getenv('SQUARE_WEBHOOK_NOTIFICATION_URL');

$failures = 0;
$checks = 0;

function check(bool $ok, string $label): void
{
    global $failures, $checks;
    $checks++;
    echo ($ok ? "[PASS] " : "[FAIL] ") . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

function signPayload(string $rawBody, string $notificationUrl, string $secret): string
{
    return base64_encode(hash_hmac('sha256', $notificationUrl . $rawBody, $secret, true));
}

function postWebhook(string $rawBody, string $signature, bool $includeHeader = true): array
{
    $headers = ["Content-Type: application/json"];
    if ($includeHeader) {
        $headers[] = "x-square-hmac-sha256-signature: " . $signature;
    }
    $ch = curl_init(ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $rawBody,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['status' => 0, 'body' => '', 'error' => $error];
    }
    $headerSize = strpos($response, "\r\n\r\n");
    $body = $headerSize === false ? $response : substr($response, $headerSize + 4);
    return ['status' => $status, 'body' => $body];
}

echo "Endpoint:  " . ENDPOINT . PHP_EOL;
echo "Secret:    " . (trim($secret) === '' ? "NOT SET" : "set (" . strlen($secret) . " chars)") . PHP_EOL;
echo "Notif URL: " . ($notificationUrl === '' ? "NOT SET" : $notificationUrl) . PHP_EOL;
echo str_repeat('-', 60) . PHP_EOL;

/* ── Case 1: valid signature + fresh event → expect 200 test_ok ── */
$body = [
    'type' => 'test.webhook',
    'event_id' => 'evt-local-test-' . bin2hex(random_bytes(4)),
    'merchant_id' => 'M_LOCAL_TEST',
    'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
];
$raw = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$sig = signPayload($raw, $notificationUrl, $secret);
$res = postWebhook($raw, $sig);
$decoded = json_decode($res['body'], true);
check(
    $res['status'] === 200 && ($decoded['status'] ?? '') === 'test_ok',
    "valid signature + fresh event → HTTP {$res['status']}, body: " . trim($res['body'])
);

/* ── Case 2: tampered signature → expect 401 ── */
$badSig = signPayload($raw, $notificationUrl, 'wrong-secret-key');
$res = postWebhook($raw, $badSig);
check(
    $res['status'] === 401,
    "tampered signature → HTTP {$res['status']} (expected 401), body: " . trim($res['body'])
);

/* ── Case 3: valid signature, stale event (outside replay window) → expect 401 ── */
$oldBody = $body;
$oldBody['created_at'] = gmdate('Y-m-d\TH:i:s\Z', time() - 400000); // ~4.6 days old
$oldRaw = json_encode($oldBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$oldSig = signPayload($oldRaw, $notificationUrl, $secret);
$res = postWebhook($oldRaw, $oldSig);
check(
    $res['status'] === 401,
    "stale event (replay window) → HTTP {$res['status']} (expected 401), body: " . trim($res['body'])
);

/* ── Case 4: missing signature header → expect 401 ── */
$res = postWebhook($raw, '', false);
check(
    $res['status'] === 401,
    "missing signature header → HTTP {$res['status']} (expected 401), body: " . trim($res['body'])
);

echo str_repeat('-', 60) . PHP_EOL;
echo "{$checks} checks, {$failures} failures" . PHP_EOL;
exit($failures > 0 ? 1 : 0);
