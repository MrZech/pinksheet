<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php'; require_once __DIR__ . '/../square_webhook_lib.php';
$type = $argv[1] ?? 'payment.updated'; if (!in_array($type, ['payment.updated','inventory.count.updated','refund.updated'], true)) { fwrite(STDERR, "Usage: php scripts/test_square_webhook.php [payment.updated|inventory.count.updated|refund.updated]\n"); exit(2); }
$key = getenv('SQUARE_WEBHOOK_SIGNATURE_KEY') ?: 'local-test-key'; $url = getenv('SQUARE_WEBHOOK_NOTIFICATION_URL') ?: 'https://example.invalid/square_webhook.php';
$payload = ['event_id'=>'local-test-'.bin2hex(random_bytes(4)), 'type'=>$type, 'data'=>['object'=>[]]]; $raw=json_encode($payload, JSON_THROW_ON_ERROR); $signature=base64_encode(hash_hmac('sha256',$url.$raw,$key,true));
echo json_encode(['payload'=>$payload,'signature'=>$signature,'signature_valid'=>squareWebhookVerifySignature($raw,$signature,$key,$url)], JSON_PRETTY_PRINT).PHP_EOL;
