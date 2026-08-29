<?php
declare(strict_types=1);

/** Square inbound webhook and reconciliation helpers. */
function squareWebhookConfig(): array
{
    $enabled = strtolower(trim((string)(getenv('SQUARE_WEBHOOK_ENABLED') ?: '0')));
    $maxBody = (int)(getenv('SQUARE_WEBHOOK_MAX_BODY_BYTES') ?: 1048576);
    return [
        'enabled' => !in_array($enabled, ['0', 'false', 'no', 'off', ''], true),
        'signature_key' => trim((string)(getenv('SQUARE_WEBHOOK_SIGNATURE_KEY') ?: '')),
        'notification_url' => trim((string)(getenv('SQUARE_WEBHOOK_NOTIFICATION_URL') ?: '')),
        'max_body_bytes' => max(1, $maxBody),
        'refund_status' => trim((string)(getenv('SQUARE_WEBHOOK_REFUND_STATUS') ?: 'RETURNED - NEEDS INSPECTION')),
    ];
}

function squareWebhookVerifySignature(string $rawBody, string $provided, string $key, string $notificationUrl): bool
{
    if ($provided === '' || $key === '' || $notificationUrl === '') return false;
    $expected = base64_encode(hash_hmac('sha256', $notificationUrl . $rawBody, $key, true));
    return hash_equals($expected, $provided);
}

function squareWebhookEnsureLegacySchema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS square_webhook_events (
 event_id TEXT PRIMARY KEY, event_type TEXT NOT NULL, square_created_at TEXT,
 received_at TEXT NOT NULL DEFAULT (datetime('now')), processed_at TEXT,
 processing_status TEXT NOT NULL DEFAULT 'received', retry_count INTEGER NOT NULL DEFAULT 0,
 error_message TEXT, payload_json TEXT NOT NULL
)
SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_square_webhook_events_status ON square_webhook_events(processing_status, received_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_square_webhook_events_type ON square_webhook_events(event_type, received_at)');
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS square_sales (
 square_payment_id TEXT PRIMARY KEY, square_order_id TEXT, square_location_id TEXT,
 payment_status TEXT, amount_cents INTEGER, currency TEXT, receipt_url TEXT,
 square_created_at TEXT, square_updated_at TEXT, recorded_at TEXT NOT NULL DEFAULT (datetime('now')),
 updated_at TEXT NOT NULL DEFAULT (datetime('now'))
)
SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_square_sales_order_id ON square_sales(square_order_id)');
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS square_sale_items (
 id INTEGER PRIMARY KEY AUTOINCREMENT, square_payment_id TEXT NOT NULL, square_order_id TEXT,
 square_line_item_uid TEXT NOT NULL, square_variation_id TEXT, sku_normalized TEXT,
 quantity TEXT, amount_cents INTEGER, currency TEXT, processed_at TEXT NOT NULL DEFAULT (datetime('now')),
 UNIQUE(square_payment_id, square_line_item_uid)
)
SQL);
    foreach (['square_payment_id','square_order_id','square_variation_id','sku_normalized'] as $column) {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_square_sale_items_' . $column . ' ON square_sale_items(' . $column . ')');
    }
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS square_inventory_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT, event_id TEXT NOT NULL, square_variation_id TEXT,
 sku_normalized TEXT, location_id TEXT, inventory_state TEXT, quantity TEXT, occurred_at TEXT,
 received_at TEXT NOT NULL DEFAULT (datetime('now')), reconciliation_status TEXT NOT NULL DEFAULT 'observed', notes TEXT,
 UNIQUE(event_id, square_variation_id, location_id, inventory_state)
)
SQL);
}

function squareWebhookLog(string $message): void
{
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    @file_put_contents($dir . '/square_webhook.log', '[' . date('c') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function squareWebhookClaimEvent(PDO $pdo, array $event): string
{
    squareWebhookEnsureLegacySchema($pdo);
    $id = (string)$event['event_id'];
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare('INSERT OR IGNORE INTO square_webhook_events(event_id,event_type,square_created_at,payload_json) VALUES(:id,:type,:created,:payload)');
        $insert->execute(['id'=>$id,'type'=>(string)$event['type'],'created'=>$event['created_at']??null,'payload'=>json_encode($event, JSON_THROW_ON_ERROR)]);
        if ($insert->rowCount() === 1) { $pdo->commit(); return 'new'; }
        $row = $pdo->prepare('SELECT processing_status FROM square_webhook_events WHERE event_id=:id');
        $row->execute(['id'=>$id]);
        if ((string)$row->fetchColumn() === 'failed') {
            $pdo->prepare("UPDATE square_webhook_events SET processing_status='received',retry_count=retry_count+1,error_message=NULL WHERE event_id=:id AND processing_status='failed'")->execute(['id'=>$id]);
            $pdo->commit(); return 'retry';
        }
        $pdo->commit(); return 'duplicate';
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function squareWebhookSetEventStatus(PDO $pdo, string $eventId, string $status, ?string $error=null): void
{
    $stmt = $pdo->prepare("UPDATE square_webhook_events SET processing_status=:status,processed_at=CASE WHEN :status IN ('processed','ignored') THEN datetime('now') ELSE processed_at END,error_message=:error WHERE event_id=:id");
    $stmt->execute(['status'=>$status,'error'=>$error===null?null:substr($error,0,1000),'id'=>$eventId]);
}

function squareWebhookDispatch(PDO $pdo, array $event, ?callable $api=null): string
{
    $id=(string)$event['event_id']; $type=(string)$event['type']; squareWebhookSetEventStatus($pdo,$id,'processing');
    try {
        $result = match ($type) {
            'payment.updated' => squareWebhookProcessPayment($pdo,$event,$api),
            'refund.updated' => squareWebhookProcessRefund($pdo,$event),
            'inventory.count.updated' => squareWebhookProcessInventory($pdo,$event),
            'order.updated' => squareWebhookProcessOrder($pdo,$event),
            default => 'ignored',
        };
        squareWebhookSetEventStatus($pdo,$id,$result==='ignored'?'ignored':'processed');
        squareWebhookLog("event_id=$id type=$type status=$result"); return $result;
    } catch (Throwable $e) { squareWebhookSetEventStatus($pdo,$id,'failed',$e->getMessage()); squareWebhookLog("event_id=$id type=$type status=failed error=".substr($e->getMessage(),0,1000)); throw $e; }
}

function squareWebhookProcessPayment(PDO $pdo, array $event, ?callable $api=null): string
{
    $payment=$event['data']['object']['payment']??null;
    if (!is_array($payment)) throw new RuntimeException('Payment object missing');
    if (($payment['status']??'')!=='COMPLETED') return 'ignored';
    $paymentId=trim((string)($payment['id']??'')); $orderId=trim((string)($payment['order_id']??''));
    if ($paymentId===''||$orderId==='') throw new RuntimeException('Completed payment is missing id or order_id');
    if ($api===null) { $config=squareSyncConfig(); $api=static fn(string $path):array=>squareSyncApiJson($config,'GET',$path); }
    $order=($api('/v2/orders/'.rawurlencode($orderId))['order']??null);
    if (!is_array($order)) throw new RuntimeException('Square order missing');
    $pdo->beginTransaction();
    try {
        squareWebhookUpsertSale($pdo,$payment);
        foreach (($order['line_items']??[]) as $line) if (is_array($line)) squareWebhookProcessSaleLine($pdo,$paymentId,$orderId,$line);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return 'processed';
}

function squareWebhookUpsertSale(PDO $pdo,array $payment):void
{
    $money=is_array($payment['amount_money']??null)?$payment['amount_money']:[];
    $stmt=$pdo->prepare(<<<'SQL'
INSERT INTO square_sales(square_payment_id,square_order_id,square_location_id,payment_status,amount_cents,currency,receipt_url,square_created_at,square_updated_at)
VALUES(:id,:order,:location,:status,:amount,:currency,:receipt,:created,:updated)
ON CONFLICT(square_payment_id) DO UPDATE SET square_order_id=excluded.square_order_id,payment_status=excluded.payment_status,amount_cents=excluded.amount_cents,currency=excluded.currency,receipt_url=excluded.receipt_url,square_updated_at=excluded.square_updated_at,updated_at=datetime('now')
SQL);
    $stmt->execute(['id'=>$payment['id'],'order'=>$payment['order_id']??null,'location'=>$payment['location_id']??null,'status'=>$payment['status']??null,'amount'=>$money['amount']??null,'currency'=>$money['currency']??null,'receipt'=>$payment['receipt_url']??null,'created'=>$payment['created_at']??null,'updated'=>$payment['updated_at']??null]);
}

function squareWebhookProcessSaleLine(PDO $pdo,string $paymentId,string $orderId,array $line):void
{
    $variation=trim((string)($line['catalog_object_id']??'')); if ($variation==='') return;
    $map=$pdo->prepare('SELECT sku_normalized FROM square_catalog_sync WHERE square_variation_id=:id LIMIT 1'); $map->execute(['id'=>$variation]); $sku=$map->fetchColumn(); $sku=$sku===false?null:(string)$sku;
    $money=is_array($line['total_money']??null)?$line['total_money']:[]; $uid=trim((string)($line['uid']??$variation));
    $lineStmt=$pdo->prepare('INSERT OR IGNORE INTO square_sale_items(square_payment_id,square_order_id,square_line_item_uid,square_variation_id,sku_normalized,quantity,amount_cents,currency) VALUES(:payment,:order,:uid,:variation,:sku,:quantity,:amount,:currency)');
    $lineStmt->execute(['payment'=>$paymentId,'order'=>$orderId,'uid'=>$uid,'variation'=>$variation,'sku'=>$sku,'quantity'=>$line['quantity']??null,'amount'=>$money['amount']??null,'currency'=>$money['currency']??null]);
    if ($sku===null||$sku==='') { squareWebhookLog("payment_id=$paymentId variation_id=$variation unmapped"); return; }
    $update=$pdo->prepare("UPDATE intake_items SET status='SOLD',updated_at=datetime('now') WHERE sku_normalized=:sku AND UPPER(TRIM(COALESCE(status,''))) <> 'SOLD'"); $update->execute(['sku'=>$sku]);
    squareWebhookLog("payment_id=$paymentId variation_id=$variation sku=$sku status=".($update->rowCount()?'changed_to_sold':'already_sold'));
}

function squareWebhookProcessRefund(PDO $pdo,array $event):string
{
    $refund=$event['data']['object']['refund']??null; if (!is_array($refund)) throw new RuntimeException('Refund object missing');
    if (($refund['status']??'')!=='COMPLETED') return 'ignored';
    $paymentId=trim((string)($refund['payment_id']??'')); $orderId=trim((string)($refund['order_id']??''));
    if ($paymentId===''&&$orderId==='') throw new RuntimeException('Completed refund missing payment or order');
    $where=$paymentId!==''?'square_payment_id=:id':'square_order_id=:id'; $rows=$pdo->prepare("SELECT DISTINCT sku_normalized FROM square_sale_items WHERE $where AND sku_normalized IS NOT NULL"); $rows->execute(['id'=>$paymentId!==''?$paymentId:$orderId]);
    $status=squareWebhookConfig()['refund_status']; $update=$pdo->prepare("UPDATE intake_items SET status=:status,updated_at=datetime('now') WHERE sku_normalized=:sku AND UPPER(TRIM(COALESCE(status,'')))='SOLD'");
    foreach($rows->fetchAll(PDO::FETCH_COLUMN) as $sku){$update->execute(['status'=>$status,'sku'=>$sku]);squareWebhookLog("refund payment_id=$paymentId sku=$sku status=$status changed=".$update->rowCount());} return 'processed';
}

function squareWebhookProcessInventory(PDO $pdo,array $event):string
{
    $counts=$event['data']['object']['inventory_counts']??$event['data']['object']['inventory_count']??[]; if(isset($counts['catalog_object_id']))$counts=[$counts]; if(!is_array($counts))throw new RuntimeException('Inventory count missing');
    foreach($counts as $count){if(!is_array($count))continue;$variation=(string)($count['catalog_object_id']??'');$map=$pdo->prepare('SELECT s.sku_normalized,i.status FROM square_catalog_sync s LEFT JOIN intake_items i ON i.sku_normalized=s.sku_normalized WHERE s.square_variation_id=:id LIMIT 1');$map->execute(['id'=>$variation]);$match=$map->fetch();$quantity=(string)($count['quantity']??'');$note=null;if(!$match)$note='No Pinksheet mapping exists';elseif((float)$quantity===0.0&&strtoupper(trim((string)$match['status']))!=='SOLD')$note='Square quantity is 0 but Pinksheet is not SOLD';elseif((float)$quantity>0&&strtoupper(trim((string)$match['status']))==='SOLD')$note='Square quantity is greater than 0 but Pinksheet is SOLD';$stmt=$pdo->prepare('INSERT OR IGNORE INTO square_inventory_events(event_id,square_variation_id,sku_normalized,location_id,inventory_state,quantity,occurred_at,reconciliation_status,notes) VALUES(:event,:variation,:sku,:location,:state,:quantity,:occurred,:status,:notes)');$stmt->execute(['event'=>$event['event_id'],'variation'=>$variation,'sku'=>$match['sku_normalized']??null,'location'=>$count['location_id']??null,'state'=>$count['state']??null,'quantity'=>$quantity,'occurred'=>$count['occurred_at']??null,'status'=>$note?'warning':'observed','notes'=>$note]);if($note)squareWebhookLog('event_id=' . $event['event_id'] . ' inventory warning=' . $note . ' variation_id=' . $variation);} return 'processed';
}

function squareWebhookProcessOrder(PDO $pdo,array $event):string
{
    $order=$event['data']['object']['order']??[]; squareWebhookLog('event_id='.$event['event_id'].' order_id='.($order['id']??'').' state='.($order['state']??'')); return 'processed';
}

function squareWebhookIsPrivateRequest():bool
{
    $remote=(string)($_SERVER['REMOTE_ADDR']??''); return $remote==='127.0.0.1'||$remote==='::1'||($remote!==''&&filter_var($remote,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false);
}
