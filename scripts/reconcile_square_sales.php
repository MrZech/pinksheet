<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php'; require_once __DIR__ . '/../square_sync.php'; require_once __DIR__ . '/../square_webhook_lib.php';
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$hours=24; $dryRun=in_array('--dry-run',$argv,true); foreach($argv as $a){if(str_starts_with($a,'--hours=')){$hours=max(1,(int)substr($a,8));}}
$config=squareSyncConfig(); if(!$config['enabled']){fwrite(STDERR,"Square API is not configured\n");exit(1);} $response=squareSyncApiJson($config,'GET','/v2/payments?begin_time='.rawurlencode(gmdate('c',time()-$hours*3600)).'&sort_order=DESC'); $pdo=pdoConnect(__DIR__.'/../data/intake.sqlite'); squareWebhookEnsureSchema($pdo);
foreach(($response['payments']??[]) as $payment){if(($payment['status']??'')!=='COMPLETED'||empty($payment['id'])||empty($payment['order_id']))continue; echo ($dryRun?'Would reconcile ':'Reconciling ').$payment['id'].PHP_EOL; if(!$dryRun){$event=['event_id'=>'reconcile-'.$payment['id'],'type'=>'payment.updated','data'=>['object'=>['payment'=>$payment]]]; squareWebhookProcessPayment($pdo,$event,null);}}
