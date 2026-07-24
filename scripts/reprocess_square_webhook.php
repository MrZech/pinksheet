<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php'; require_once __DIR__ . '/../square_sync.php'; require_once __DIR__ . '/../square_webhook_lib.php';
if (PHP_SAPI !== 'cli' || empty($argv[1])) { fwrite(STDERR,"Usage: php scripts/reprocess_square_webhook.php EVENT_ID\n"); exit(2); }
$pdo=pdoConnect(__DIR__.'/../data/intake.sqlite'); squareWebhookEnsureSchema($pdo); $s=$pdo->prepare('SELECT payload_json FROM square_webhook_events WHERE event_id=:id'); $s->execute(['id'=>$argv[1]]); $raw=$s->fetchColumn(); if($raw===false){fwrite(STDERR,"Event not found\n");exit(1);} $event=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR); squareWebhookSetEventStatus($pdo,$argv[1],'received'); squareWebhookDispatch($pdo,$event); echo "Reprocessed {$argv[1]}\n";
