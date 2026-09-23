<?php
declare(strict_types=1);

require_once __DIR__ . '/square_audit.php';
require_once __DIR__ . '/square_sync_queue.php';

const SQUARE_WEBHOOK_TABLE_SQL = <<<'SQL'
CREATE TABLE IF NOT EXISTS webhook_processed (
    event_id TEXT PRIMARY KEY,
    event_type TEXT NOT NULL,
    merchant_id TEXT NOT NULL,
    location_id TEXT,
    body_hash TEXT NOT NULL,
    processed_at TEXT NOT NULL DEFAULT (datetime('now'))
);
SQL;

const SQUARE_SALES_HISTORY_TABLE_SQL = <<<'SQL'
CREATE TABLE IF NOT EXISTS sales_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sku TEXT NOT NULL,
    sku_normalized TEXT NOT NULL,
    square_order_id TEXT NOT NULL,
    square_payment_id TEXT,
    sale_price REAL NOT NULL DEFAULT 0,
    tax_amount REAL NOT NULL DEFAULT 0,
    discount_amount REAL NOT NULL DEFAULT 0,
    line_item_quantity INTEGER NOT NULL DEFAULT 1,
    sold_at TEXT NOT NULL,
    location_id TEXT,
    source TEXT NOT NULL DEFAULT 'square_pos',
    receipt_number TEXT,
    employee_name TEXT,
    notes TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(square_order_id, sku_normalized)
);
SQL;

function squareWebhookEnsureSchema(PDO $pdo): void
{
    $pdo->exec(SQUARE_WEBHOOK_TABLE_SQL);
    $pdo->exec(SQUARE_SALES_HISTORY_TABLE_SQL);

    $cols = $pdo->query("PRAGMA table_info(square_catalog_sync)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('last_sale_sync_at', $cols, true)) {
        $pdo->exec("ALTER TABLE square_catalog_sync ADD COLUMN last_sale_sync_at TEXT");
    }
    if (!in_array('last_inventory_sync', $cols, true)) {
        $pdo->exec("ALTER TABLE square_catalog_sync ADD COLUMN last_inventory_sync TEXT");
    }
    if (!in_array('sync_enabled', $cols, true)) {
        $pdo->exec("ALTER TABLE square_catalog_sync ADD COLUMN sync_enabled INTEGER NOT NULL DEFAULT 1");
    }
    if (!in_array('last_origin', $cols, true)) {
        $pdo->exec("ALTER TABLE square_catalog_sync ADD COLUMN last_origin TEXT");
    }
    if (!in_array('last_correlation_id', $cols, true)) {
        $pdo->exec("ALTER TABLE square_catalog_sync ADD COLUMN last_correlation_id TEXT");
    }
}

function squareWebhookConfig(): array
{
    $enabled = strtolower(trim((string)(getenv('SQUARE_WEBHOOK_ENABLED') ?: '1')));
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
    if ($provided === '' || $key === '' || $notificationUrl === '') {
        return false;
    }
    $expected = base64_encode(hash_hmac('sha256', $notificationUrl . $rawBody, $key, true));
    return hash_equals($expected, trim($provided));
}

function squareWebhookVerify(string $rawBody, string $signatureHeader, string $notificationUrl): bool
{
    $secret = getenv('SQUARE_WEBHOOK_SIGNATURE_KEY');
    if ($secret === false || trim($secret) === '') {
        squareSyncLog('Webhook verification failed: SQUARE_WEBHOOK_SIGNATURE_KEY is not set');
        return false;
    }
    if (trim($notificationUrl) === '') {
        squareSyncLog('Webhook verification failed: notification URL is empty');
        return false;
    }
    $expected = base64_encode(hash_hmac('sha256', $notificationUrl . $rawBody, trim($secret), true));
    $provided = trim($signatureHeader);
    return hash_equals($expected, $provided);
}

function squareWebhookNotificationUrl(): string
{
    $configured = trim((string)(getenv('SQUARE_WEBHOOK_NOTIFICATION_URL') ?: ''));
    if ($configured !== '') {
        return $configured;
    }
    $scheme = 'http';
    $forwardedProto = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    if ($forwardedProto !== '' && in_array(strtolower($forwardedProto), ['https', 'http', 'https, http'], true)) {
        $scheme = strtok($forwardedProto, ',') ?: 'http';
    } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    } elseif ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443) {
        $scheme = 'https';
    }
    $host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/webhooks/square.php');
    return $host === '' ? '' : $scheme . '://' . $host . $uri;
}

function squareWebhookEnsureLegacySchema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS square_webhook_events (
    event_id TEXT PRIMARY KEY, event_type TEXT NOT NULL, square_created_at TEXT,
    received_at TEXT NOT NULL DEFAULT (datetime('now')), processed_at TEXT,
    processing_status TEXT NOT NULL DEFAULT 'received', retry_count INTEGER NOT NULL DEFAULT 0,
    error_message TEXT, payload_json TEXT NOT NULL
);
SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_square_webhook_events_status ON square_webhook_events(processing_status, received_at)');
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS square_inventory_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT, event_id TEXT NOT NULL, square_variation_id TEXT,
    sku_normalized TEXT, location_id TEXT, inventory_state TEXT, quantity TEXT, occurred_at TEXT,
    received_at TEXT NOT NULL DEFAULT (datetime('now')), reconciliation_status TEXT NOT NULL DEFAULT 'observed', notes TEXT,
    UNIQUE(event_id, square_variation_id, location_id, inventory_state)
);
SQL);
}

function squareWebhookLog(string $message): void
{
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    @file_put_contents($dir . '/square_webhook.log', '[' . date('c') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function squareWebhookIsPrivateRequest(): bool
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return $remote === '127.0.0.1' || $remote === '::1' || ($remote !== '' && filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false);
}

function squareWebhookIsFresh(array $body): bool
{
    $createdAt = (string)($body['created_at'] ?? '');
    if ($createdAt === '') {
        return true;
    }
    $createdTs = strtotime($createdAt);
    if ($createdTs === false) {
        return false;
    }
    $maxAgeRaw = trim((string)(getenv('SQUARE_WEBHOOK_MAX_AGE_SECONDS') ?: '259200'));
    $maxAge = is_numeric($maxAgeRaw) ? max(300, (int)$maxAgeRaw) : 259200;
    return abs(time() - $createdTs) <= $maxAge;
}

function squareWebhookProcessPayment(PDO $pdo, array $body, ?callable $api = null): array
{
    $payment = $body['data']['object']['payment'] ?? null;
    if (!is_array($payment) || strtoupper((string)($payment['status'] ?? '')) !== 'COMPLETED') {
        return ['status' => 'skipped', 'message' => 'Payment is not completed'];
    }
    $paymentId = trim((string)($payment['id'] ?? ''));
    $orderId = trim((string)($payment['order_id'] ?? ''));
    if ($paymentId === '' || $orderId === '') {
        return ['status' => 'error', 'message' => 'Completed payment is missing id or order_id'];
    }
    if ($api === null) {
        $config = squareSyncConfig();
        $api = static fn(string $path): array => squareSyncApiJson($config, 'GET', $path);
    }
    $orderResponse = $api('/v2/orders/' . rawurlencode($orderId));
    $order = $orderResponse['order'] ?? null;
    if (!is_array($order)) {
        return ['status' => 'error', 'message' => 'Square order missing'];
    }
    $body['data']['object']['order'] = $order;
    $body['data']['object']['payment'] = $payment;
    return squareWebhookProcessSale($pdo, $body, 'wh-' . (string)($body['event_id'] ?? $paymentId));
}

function squareWebhookProcessRefund(PDO $pdo, array $body): array
{
    $refund = $body['data']['object']['refund'] ?? null;
    if (!is_array($refund) || strtoupper((string)($refund['status'] ?? '')) !== 'COMPLETED') {
        return ['status' => 'skipped', 'message' => 'Refund is not completed'];
    }
    $paymentId = trim((string)($refund['payment_id'] ?? ''));
    $orderId = trim((string)($refund['order_id'] ?? ''));
    if ($paymentId === '' && $orderId === '') {
        return ['status' => 'error', 'message' => 'Completed refund is missing payment_id or order_id'];
    }
    $where = $paymentId !== '' ? 'square_payment_id = :id' : 'square_order_id = :id';
    $rows = $pdo->prepare("SELECT DISTINCT sku_normalized FROM sales_history WHERE $where AND sku_normalized <> ''");
    $rows->execute(['id' => $paymentId !== '' ? $paymentId : $orderId]);
    $status = squareWebhookConfig()['refund_status'];
    $update = $pdo->prepare("UPDATE intake_items SET status = :status, updated_at = datetime('now') WHERE sku_normalized = :sku AND UPPER(TRIM(COALESCE(status, ''))) = 'SOLD'");
    $count = 0;
    foreach ($rows->fetchAll(PDO::FETCH_COLUMN) as $sku) {
        $update->execute(['status' => $status, 'sku' => $sku]);
        $count += $update->rowCount();
    }
    return ['status' => 'ok', 'message' => $count . ' refunded item(s) updated'];
}

function squareWebhookProcess(PDO $pdo, string $eventType, array $body): array
{
    $start = microtime(true);
    $eventId = (string)($body['event_id'] ?? ($body['data']['id'] ?? ''));
    $merchantId = (string)($body['merchant_id'] ?? '');
    $locationId = (string)($body['location_id'] ?? '');
    $bodyHash = hash('sha256', json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $correlationId = 'wh-' . $eventId;

    if ($eventId === '') {
        return ['status' => 'skipped', 'message' => 'No event_id in payload'];
    }

    // Schema already ensured by webhook receiver — this is a safety net
    squareWebhookEnsureSchema($pdo);

    $dup = $pdo->prepare('SELECT body_hash FROM webhook_processed WHERE event_id = :eid');
    $dup->execute(['eid' => $eventId]);
    $existingHash = $dup->fetchColumn();
    if ($existingHash !== false) {
        $ms = (int)((microtime(true) - $start) * 1000);
        if ((string)$existingHash !== $bodyHash) {
            squareSyncLogEvent([
                'operation' => $eventType,
                'direction' => 'pull',
                'webhook_id' => $eventId,
                'correlation_id' => $correlationId,
                'status' => 'duplicate_hash_mismatch',
                'duration_ms' => $ms,
                'message' => 'Duplicate event id received with a different payload hash',
            ]);
        }
        squareAuditLog($pdo, [
            'operation' => $eventType,
            'direction' => 'pull', 'status' => 'skipped',
            'duration_ms' => $ms,
            'webhook_id' => $eventId,
            'correlation_id' => $correlationId,
            'request_body_hash' => $bodyHash,
            'response_summary' => 'Duplicate event ' . $eventId,
        ]);
        return ['status' => 'duplicate', 'message' => 'Event already processed'];
    }

    try {
        $pdo->beginTransaction();
        $result = match (true) {
            str_starts_with($eventType, 'payment.') => squareWebhookProcessPayment($pdo, $body),
            str_starts_with($eventType, 'refund.') => squareWebhookProcessRefund($pdo, $body),
            str_starts_with($eventType, 'order.') => squareWebhookProcessSale($pdo, $body, $correlationId),
            str_starts_with($eventType, 'inventory.') => squareWebhookProcessInventory($pdo, $body, $correlationId),
            str_starts_with($eventType, 'catalog.') => squareWebhookProcessCatalog($pdo, $body, $correlationId),
            default => ['status' => 'ignored', 'message' => 'Unhandled event type: ' . $eventType],
        };

        $mark = $pdo->prepare(<<<'SQL'
INSERT OR IGNORE INTO webhook_processed (event_id, event_type, merchant_id, location_id, body_hash)
VALUES (:eid, :etype, :mid, :lid, :hash)
SQL);
        $mark->execute([
            'eid' => $eventId, 'etype' => $eventType,
            'mid' => $merchantId, 'lid' => $locationId,
            'hash' => $bodyHash,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $ms = (int)((microtime(true) - $start) * 1000);
    squareAuditLog($pdo, [
        'operation' => $eventType,
        'direction' => 'pull',
        'status' => $result['status'] === 'error' ? 'failure' : $result['status'],
        'duration_ms' => $ms,
        'webhook_id' => $eventId,
        'correlation_id' => $correlationId,
        'request_body_hash' => $bodyHash,
        'response_summary' => $result['message'] ?? '',
    ]);
    squareSyncLogEvent([
        'operation' => $eventType,
        'direction' => 'pull',
        'webhook_id' => $eventId,
        'correlation_id' => $correlationId,
        'status' => $result['status'] ?? 'unknown',
        'duration_ms' => $ms,
        'message' => $result['message'] ?? '',
    ]);

    return $result;
}

function squareWebhookProcessSale(PDO $pdo, array $body, string $correlationId): array
{
    $order = $body['data']['object']['order'] ?? $body['data']['object']['payment']['order'] ?? null;
    $payment = $body['data']['object']['payment'] ?? null;

    if (!$order) {
        return ['status' => 'skipped', 'message' => 'No order data in payload'];
    }

    $orderId = (string)($order['id'] ?? '');
    $paymentId = (string)($payment['id'] ?? '');
    $orderState = strtoupper((string)($order['state'] ?? ''));
    $orderCreatedAt = (string)($order['created_at'] ?? date('Y-m-d\TH:i:s\Z'));
    $locationId = (string)($order['location_id'] ?? '');
    $lineItems = $order['line_items'] ?? [];

    if (!in_array($orderState, ['COMPLETED', 'DONE'], true)) {
        return ['status' => 'skipped', 'message' => 'Order state is ' . $orderState . ' (not completed)'];
    }

    if (empty($lineItems)) {
        return ['status' => 'skipped', 'message' => 'No line items in order ' . $orderId];
    }

    $results = [];
    foreach ($lineItems as $item) {
        $results[] = squareWebhookProcessLineItem($pdo, $item, $orderId, $paymentId, $orderCreatedAt, $locationId, $correlationId);
    }

    $sold = count(array_filter($results, fn($r) => ($r['status'] ?? '') === 'sold'));
    $skipped = count(array_filter($results, fn($r) => ($r['status'] ?? '') !== 'sold'));

    return [
        'status' => $sold > 0 ? 'ok' : 'skipped',
        'message' => $sold . ' sold, ' . $skipped . ' skipped from order ' . $orderId,
        'results' => $results,
    ];
}

function squareWebhookProcessLineItem(PDO $pdo, array $item, string $orderId, string $paymentId, string $orderCreatedAt, string $locationId, string $correlationId): array
{
    $variationName = (string)($item['variation_name'] ?? '');
    $catalogObjectId = (string)($item['catalog_object_id'] ?? '');
    $quantity = max(1, (int)($item['quantity'] ?? 1));

    $totalMoney = $item['total_money'] ?? [];
    $salePrice = isset($totalMoney['amount']) ? ((int)$totalMoney['amount']) / 100.0 : 0;

    $taxAmount = 0;
    foreach (($item['applied_taxes'] ?? []) as $tax) {
        $taxAmount += isset($tax['applied_money']['amount']) ? ((int)$tax['applied_money']['amount']) / 100.0 : 0;
    }

    $discountAmount = 0;
    foreach (($item['applied_discounts'] ?? []) as $discount) {
        $discountAmount += isset($discount['applied_money']['amount']) ? ((int)$discount['applied_money']['amount']) / 100.0 : 0;
    }

    $receiptNumber = (string)($item['uid'] ?? '');

    $skuNormalized = squareWebhookResolveSku($pdo, $catalogObjectId, $variationName);
    if ($skuNormalized === null) {
        return [
            'sku' => null, 'status' => 'skipped',
            'message' => 'Could not resolve SKU for catalog_id=' . $catalogObjectId . ' name=' . $variationName,
        ];
    }

    $existing = $pdo->prepare('SELECT 1 FROM sales_history WHERE square_order_id = :oid AND sku_normalized = :sku');
    $existing->execute(['oid' => $orderId, 'sku' => $skuNormalized]);
    if ($existing->fetchColumn()) {
        return ['sku' => $skuNormalized, 'status' => 'duplicate', 'message' => 'Sale already recorded'];
    }

    $insert = $pdo->prepare(<<<'SQL'
INSERT INTO sales_history (sku, sku_normalized, square_order_id, square_payment_id,
    sale_price, tax_amount, discount_amount, line_item_quantity, sold_at,
    location_id, source, receipt_number)
VALUES (:sku, :sku_normalized, :oid, :pid,
    :price, :tax, :discount, :qty, :sold_at,
    :loc, :source, :receipt)
SQL);
    $insert->execute([
        'sku' => $skuNormalized,
        'sku_normalized' => $skuNormalized,
        'oid' => $orderId,
        'pid' => $paymentId,
        'price' => $salePrice,
        'tax' => $taxAmount,
        'discount' => $discountAmount,
        'qty' => $quantity,
        'sold_at' => $orderCreatedAt,
        'loc' => $locationId,
        'source' => 'square_pos',
        'receipt' => $receiptNumber,
    ]);

    $pdo->prepare("UPDATE intake_items SET status = 'sold', updated_at = datetime('now') WHERE sku_normalized = :sku AND status != 'sold'")
        ->execute(['sku' => $skuNormalized]);

    squareQueueEnqueue($pdo, $skuNormalized, 'inventory_set', 10);

    $pdo->prepare("UPDATE square_catalog_sync SET last_sale_sync_at = datetime('now'), last_origin = 'square', last_correlation_id = :cid WHERE sku_normalized = :sku")
        ->execute(['sku' => $skuNormalized, 'cid' => $correlationId]);

    return [
        'sku' => $skuNormalized, 'status' => 'sold',
        'message' => 'Sale recorded for ' . $skuNormalized,
        'price' => $salePrice,
    ];
}

function squareWebhookProcessInventory(PDO $pdo, array $body, string $correlationId): array
{
    $data = $body['data'] ?? [];
    $object = $data['object'] ?? [];
    $inventoryCount = $object['inventory_count'] ?? $object ?? [];
    $catalogObjectId = (string)($inventoryCount['catalog_object_id'] ?? '');
    $locationId = (string)($inventoryCount['location_id'] ?? '');
    $state = (string)($inventoryCount['state'] ?? '');
    $quantity = (string)($inventoryCount['quantity'] ?? '0');
    $occurredAt = (string)($inventoryCount['occurred_at'] ?? ($body['created_at'] ?? gmdate('Y-m-d\\TH:i:s\\Z')));

    if ($catalogObjectId === '') {
        return ['status' => 'skipped', 'message' => 'No catalog_object_id in inventory event'];
    }

    $config = squareSyncConfig();
    if ($config['location_id'] !== '' && $locationId !== '' && $locationId !== $config['location_id']) {
        return ['status' => 'skipped', 'message' => 'Inventory event for different location'];
    }

    $skuNormalized = squareWebhookResolveSku($pdo, $catalogObjectId, '');
    if ($skuNormalized === null) {
        squareQueueEnqueue($pdo, $catalogObjectId, 'catalog_upsert', 5);
        return ['status' => 'skipped', 'message' => 'No SKU mapping for ' . $catalogObjectId . ' — enqueued catalog lookup'];
    }

    $localItem = squareSyncLoadItem($pdo, $skuNormalized);
    $localStatus = (string)($localItem['status'] ?? '');
    $syncRow = squareSyncLoadRow($pdo, $skuNormalized) ?: [];
    $squareTs = strtotime($occurredAt) ?: time();
    $localTs = strtotime((string)($syncRow['last_local_change_at'] ?? ($localItem['updated_at'] ?? ''))) ?: 0;
    if ($squareTs < $localTs) {
        return ['status' => 'skipped', 'message' => 'Square inventory event is older than the local Pinksheet update'];
    }
    $isSoldLocal = strtoupper(trim($localStatus)) === 'SOLD';
    $inventoryZero = ((int)$quantity) <= 0;

    $newStatus = $localStatus;
    if ($inventoryZero) {
        if (!$isSoldLocal) {
            $pdo->prepare("UPDATE square_catalog_sync SET previous_status = :previous_status WHERE sku_normalized = :sku")
                ->execute(['previous_status' => $localStatus !== '' ? $localStatus : null, 'sku' => $skuNormalized]);
        }
        $newStatus = 'sold';
    } elseif ($isSoldLocal) {
        $newStatus = trim((string)($syncRow['previous_status'] ?? ''));
        if ($newStatus === '') {
            return ['status' => 'skipped', 'message' => 'Positive Square inventory found but no previous Pinksheet status is available'];
        }
    }
    if ($newStatus !== $localStatus || (int)($localItem['quantity'] ?? 0) !== (int)$quantity) {
        $pdo->prepare("UPDATE intake_items SET quantity = :quantity, status = :status, updated_at = datetime('now') WHERE sku_normalized = :sku")
            ->execute(['quantity' => max(0, (int)$quantity), 'status' => $newStatus, 'sku' => $skuNormalized]);
        squareSyncLogEvent([
            'operation' => 'inventory.webhook',
            'direction' => 'pull',
            'sku' => $skuNormalized,
            'correlation_id' => $correlationId,
            'object_type' => 'ITEM_VARIATION',
            'object_id' => $catalogObjectId,
            'status' => 'updated',
            'message' => 'Inventory webhook: marked as sold',
        ]);
    }

    $pdo->prepare("UPDATE square_catalog_sync SET last_inventory_sync = datetime('now'), last_square_change_at = :changed_at, last_origin = 'square', last_correlation_id = :cid, last_synced_quantity = :quantity WHERE sku_normalized = :sku")
        ->execute(['sku' => $skuNormalized, 'cid' => $correlationId, 'changed_at' => gmdate('Y-m-d H:i:s', $squareTs), 'quantity' => max(0, (int)$quantity)]);

    return [
        'status' => 'ok',
        'message' => 'Inventory processed for ' . $skuNormalized . ' (state=' . $state . ', qty=' . $quantity . ')',
    ];
}

function squareWebhookProcessCatalog(PDO $pdo, array $body, string $correlationId): array
{
    $data = $body['data'] ?? [];
    $object = $data['object'] ?? [];
    $catalogObject = $object['catalog_object'] ?? $object ?? [];
    $type = (string)($catalogObject['type'] ?? '');
    $objectId = (string)($catalogObject['id'] ?? '');

    if ($objectId === '') {
        return ['status' => 'skipped', 'message' => 'No catalog object id in event'];
    }

    $skuNormalized = null;
    $variations = $catalogObject['item_data']['variations'] ?? [];
    if ($type === 'ITEM') {
        foreach ($variations as $variation) {
            if (!is_array($variation)) {
                continue;
            }
            $variationSku = strtoupper(trim((string)($variation['item_variation_data']['sku'] ?? '')));
            if ($variationSku !== '') {
                $skuNormalized = $variationSku;
                break;
            }
        }
    } elseif ($type === 'ITEM_VARIATION') {
        $skuNormalized = strtoupper(trim((string)($catalogObject['item_variation_data']['sku'] ?? '')));
    }

    if ($skuNormalized !== null && $skuNormalized !== '') {
        squareQueueEnqueue($pdo, $skuNormalized, 'catalog_upsert', 30);
        squareSyncLogEvent([
            'operation' => 'catalog.webhook',
            'direction' => 'pull',
            'sku' => $skuNormalized,
            'correlation_id' => $correlationId,
            'object_type' => $type,
            'object_id' => $objectId,
            'status' => 'enqueued',
            'message' => 'Catalog webhook: enqueued ' . $skuNormalized . ' for sync',
        ]);
    } else {
        squareSyncLogEvent([
            'operation' => 'catalog.webhook',
            'direction' => 'pull',
            'correlation_id' => $correlationId,
            'object_type' => $type,
            'object_id' => $objectId,
            'status' => 'skipped',
            'message' => 'Catalog webhook: no SKU found in object',
        ]);
    }

    return [
        'status' => 'ok',
        'message' => 'Catalog event processed for ' . ($skuNormalized ?? $objectId),
    ];
}

function squareWebhookResolveSku(PDO $pdo, string $catalogObjectId, string $variationName): ?string
{
    if ($catalogObjectId !== '') {
        $stmt = $pdo->prepare('SELECT sku_normalized FROM square_catalog_sync WHERE square_variation_id = :vid OR square_item_id = :iid LIMIT 1');
        $stmt->execute(['vid' => $catalogObjectId, 'iid' => $catalogObjectId]);
        $mapped = $stmt->fetchColumn();
        if ($mapped !== false && $mapped !== null && $mapped !== '') {
            return strtoupper(trim((string)$mapped));
        }
    }

    if ($variationName !== '') {
        $search = strtoupper(trim($variationName));
        $stmt = $pdo->prepare('SELECT sku_normalized FROM intake_items WHERE sku_normalized = :sku LIMIT 1');
        $stmt->execute(['sku' => $search]);
        $found = $stmt->fetchColumn();
        if ($found !== false && $found !== null && $found !== '') {
            return strtoupper(trim((string)$found));
        }
    }

    return null;
}
