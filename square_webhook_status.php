<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/square_sync.php';
require_once __DIR__ . '/square_sync_queue.php';
require_once __DIR__ . '/square_webhook_service.php';

if (!squareWebhookIsPrivateRequest()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

checkMaintenance(true);
ensureStorageWritable();
$pdo = pdoConnect(__DIR__ . '/data/intake.sqlite');
squareSyncEnsureSchema($pdo);
squareWebhookEnsureSchema($pdo);
squareWebhookEnsureLegacySchema($pdo);
$config = squareWebhookConfig();

function squareWebhookStatusEscape(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$events = $pdo->query('SELECT event_id,event_type,received_at,processed_at,processing_status,retry_count,error_message FROM square_webhook_events ORDER BY received_at DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
$inventory = $pdo->query('SELECT square_variation_id,sku_normalized,quantity,reconciliation_status,notes,received_at FROM square_inventory_events ORDER BY received_at DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
$sales = $pdo->query('SELECT sku_normalized,square_order_id,square_payment_id,sale_price,line_item_quantity,sold_at FROM sales_history ORDER BY sold_at DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
$last24 = (int)$pdo->query("SELECT COUNT(*) FROM square_webhook_events WHERE received_at >= datetime('now','-24 hours')")->fetchColumn();
$failed = (int)$pdo->query("SELECT COUNT(*) FROM square_webhook_events WHERE processing_status = 'failed'")->fetchColumn();
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Square webhook status</title><style>body{font:14px system-ui;margin:2rem;color:#222}table{border-collapse:collapse;width:100%;margin:1rem 0}td,th{border:1px solid #ccc;padding:.4rem;text-align:left}th{background:#eee}.warn{color:#a00}pre{white-space:pre-wrap;background:#eee;padding:1rem}</style></head>
<body>
<h1>Square webhook status</h1>
<p>Enabled: <strong><?= $config['enabled'] ? 'yes' : 'no' ?></strong> · Signature key: <strong><?= $config['signature_key'] !== '' ? 'configured' : 'missing' ?></strong> · Notification URL: <strong><?= squareWebhookStatusEscape($config['notification_url'] ?: 'missing') ?></strong></p>
<p>Square API configured: <strong><?= squareSyncConfig()['enabled'] ? 'yes' : 'no' ?></strong> · Events last 24h: <strong><?= $last24 ?></strong> · Failed events: <strong class="<?= $failed ? 'warn' : '' ?>"><?= $failed ?></strong></p>
<h2>Recent webhook events</h2><table><tr><th>ID</th><th>Type</th><th>Status</th><th>Received</th><th>Retries</th><th>Error</th></tr><?php foreach ($events as $row): ?><tr><td><?= squareWebhookStatusEscape($row['event_id']) ?></td><td><?= squareWebhookStatusEscape($row['event_type']) ?></td><td><?= squareWebhookStatusEscape($row['processing_status']) ?></td><td><?= squareWebhookStatusEscape($row['received_at']) ?></td><td><?= squareWebhookStatusEscape($row['retry_count']) ?></td><td><?= squareWebhookStatusEscape($row['error_message']) ?></td></tr><?php endforeach ?></table>
<h2>Recent sales</h2><table><tr><th>SKU</th><th>Order</th><th>Payment</th><th>Price</th><th>Qty</th><th>Sold at</th></tr><?php foreach ($sales as $row): ?><tr><td><?= squareWebhookStatusEscape($row['sku_normalized']) ?></td><td><?= squareWebhookStatusEscape($row['square_order_id']) ?></td><td><?= squareWebhookStatusEscape($row['square_payment_id']) ?></td><td><?= squareWebhookStatusEscape($row['sale_price']) ?></td><td><?= squareWebhookStatusEscape($row['line_item_quantity']) ?></td><td><?= squareWebhookStatusEscape($row['sold_at']) ?></td></tr><?php endforeach ?></table>
<h2>Inventory observations</h2><table><tr><th>Variation</th><th>SKU</th><th>Quantity</th><th>Status</th><th>Note</th><th>Received</th></tr><?php foreach ($inventory as $row): ?><tr><td><?= squareWebhookStatusEscape($row['square_variation_id']) ?></td><td><?= squareWebhookStatusEscape($row['sku_normalized']) ?></td><td><?= squareWebhookStatusEscape($row['quantity']) ?></td><td><?= squareWebhookStatusEscape($row['reconciliation_status']) ?></td><td><?= squareWebhookStatusEscape($row['notes']) ?></td><td><?= squareWebhookStatusEscape($row['received_at']) ?></td></tr><?php endforeach ?></table>
</body></html>
