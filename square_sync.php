<?php
declare(strict_types=1);

const SQUARE_SYNC_TABLE_SQL = <<<'SQL'
CREATE TABLE IF NOT EXISTS square_catalog_sync (
    sku_normalized TEXT PRIMARY KEY,
    square_item_id TEXT,
    square_item_version INTEGER,
    square_variation_id TEXT,
    square_variation_version INTEGER,
    square_image_id TEXT,
    square_image_photo_id INTEGER,
    payload_hash TEXT,
    last_synced_at TEXT,
    last_error TEXT,
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
SQL;

function squareSyncEnsureSchema(PDO $pdo): void
{
    $pdo->exec(SQUARE_SYNC_TABLE_SQL);
}

function squareSyncConfig(): array
{
    $env = strtolower(trim((string)(getenv('SQUARE_ENVIRONMENT') ?: getenv('SQUARE_ENV') ?: 'sandbox')));
    $enabledRaw = strtolower(trim((string)(getenv('SQUARE_SYNC_ENABLED') ?: '1')));
    $token = trim((string)(getenv('SQUARE_ACCESS_TOKEN') ?: getenv('SQUARE_SANDBOX_ACCESS_TOKEN') ?: ''));
    $locationId = trim((string)(getenv('SQUARE_LOCATION_ID') ?: ''));
    $apiVersion = trim((string)(getenv('SQUARE_API_VERSION') ?: '2026-01-22'));
    $currency = strtoupper(trim((string)(getenv('SQUARE_CURRENCY') ?: 'USD')));
    $defaultQuantity = trim((string)(getenv('SQUARE_DEFAULT_QUANTITY') ?: '1'));

    return [
        'enabled' => !in_array($enabledRaw, ['0', 'false', 'no', 'off'], true) && $token !== '' && $locationId !== '',
        'token' => $token,
        'location_id' => $locationId,
        'api_version' => $apiVersion,
        'currency' => $currency,
        'default_quantity' => is_numeric($defaultQuantity) ? max(0, (int)$defaultQuantity) : 1,
        'base_url' => $env === 'production' ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com',
    ];
}

function squareSyncItemBySku(PDO $pdo, string $skuNormalized): array
{
    $skuNormalized = strtoupper(trim($skuNormalized));
    if ($skuNormalized === '') {
        return ['status' => 'skipped', 'message' => 'SKU is empty'];
    }

    squareSyncEnsureSchema($pdo);
    $config = squareSyncConfig();
    if (!$config['enabled']) {
        return ['status' => 'disabled', 'message' => 'Square sync is not configured'];
    }

    $item = squareSyncLoadItem($pdo, $skuNormalized);
    if (!$item) {
        return ['status' => 'skipped', 'message' => 'SKU not found'];
    }

    try {
        $syncRow = squareSyncLoadRow($pdo, $skuNormalized);
        $photo = squareSyncLoadPreferredPhoto($pdo, $skuNormalized);
        $payloadHash = squareSyncPayloadHash($item, $photo);

        if (
            $syncRow
            && (string)($syncRow['payload_hash'] ?? '') === $payloadHash
            && (string)($syncRow['square_item_id'] ?? '') !== ''
            && (string)($syncRow['square_variation_id'] ?? '') !== ''
            && (string)($syncRow['last_error'] ?? '') === ''
        ) {
            return ['status' => 'skipped', 'message' => 'Square already has the latest payload'];
        }

        $existing = squareSyncFindExistingCatalogObject($config, $syncRow, $skuNormalized);
        $catalogObject = squareSyncBuildCatalogObject($item, $config, $existing);
        $upsertResult = squareSyncApiJson($config, 'POST', '/v2/catalog/object', [
            'idempotency_key' => 'pink-' . substr(hash('sha256', $skuNormalized . $payloadHash), 0, 32),
            'object' => $catalogObject,
        ]);

        $squareItem = squareSyncFindCatalogObject($upsertResult['catalog_object'] ?? null, $upsertResult['id_mappings'] ?? [], 'ITEM');
        $squareVariation = squareSyncFindVariationObject($upsertResult['catalog_object'] ?? null, $upsertResult['id_mappings'] ?? []);
        $itemId = (string)($squareItem['id'] ?? ($existing['item']['id'] ?? ''));
        $variationId = (string)($squareVariation['id'] ?? ($existing['variation']['id'] ?? ''));
        $itemVersion = isset($squareItem['version']) ? (int)$squareItem['version'] : (int)($existing['item']['version'] ?? 0);
        $variationVersion = isset($squareVariation['version']) ? (int)$squareVariation['version'] : (int)($existing['variation']['version'] ?? 0);
        if ($itemId === '' || $variationId === '') {
            throw new RuntimeException('Square did not return catalog item and variation IDs.');
        }

        $imageId = (string)($syncRow['square_image_id'] ?? '');
        $imagePhotoId = isset($syncRow['square_image_photo_id']) ? (int)$syncRow['square_image_photo_id'] : null;
        if ($photo && (int)$photo['id'] !== $imagePhotoId) {
            $image = squareSyncUploadImage($config, $itemId, $skuNormalized, $photo);
            if ($image) {
                $imageId = (string)($image['id'] ?? $imageId);
                $imagePhotoId = (int)$photo['id'];
            }
        }

        squareSyncSetInventoryCount($config, $variationId, (string)($item['status'] ?? ''), $skuNormalized, $payloadHash);
        squareSyncSaveRow($pdo, $skuNormalized, [
            'square_item_id' => $itemId,
            'square_item_version' => $itemVersion,
            'square_variation_id' => $variationId,
            'square_variation_version' => $variationVersion,
            'square_image_id' => $imageId,
            'square_image_photo_id' => $imagePhotoId,
            'payload_hash' => $payloadHash,
            'last_error' => null,
        ]);

        return ['status' => 'ok', 'message' => 'Square catalog synced'];
    } catch (Throwable $e) {
        squareSyncRecordError($pdo, $skuNormalized, $e->getMessage());
        squareSyncLog('SKU ' . $skuNormalized . ': ' . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

function squareSyncLoadItem(PDO $pdo, string $skuNormalized): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM intake_items WHERE sku_normalized = :sku ORDER BY id DESC LIMIT 1');
    $stmt->execute(['sku' => $skuNormalized]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function squareSyncLoadRow(PDO $pdo, string $skuNormalized): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM square_catalog_sync WHERE sku_normalized = :sku LIMIT 1');
    $stmt->execute(['sku' => $skuNormalized]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function squareSyncLoadPreferredPhoto(PDO $pdo, string $skuNormalized): ?array
{
    $columns = $pdo->query('PRAGMA table_info(sku_photos)')->fetchAll(PDO::FETCH_ASSOC);
    $hasThumb = in_array('is_thumb', array_column($columns, 'name'), true);
    $order = $hasThumb ? 'is_thumb DESC, id DESC' : 'id DESC';
    $stmt = $pdo->prepare('SELECT id, sku_normalized, original_name, stored_name, mime_type FROM sku_photos WHERE sku_normalized = :sku ORDER BY ' . $order . ' LIMIT 1');
    $stmt->execute(['sku' => $skuNormalized]);
    $photo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($photo)) {
        return null;
    }
    $dir = preg_replace('/[^A-Z0-9_-]+/', '_', strtoupper(trim($skuNormalized)));
    $dir = trim((string)$dir, '_') ?: 'UNASSIGNED';
    $path = __DIR__ . '/data/sku_photos/' . $dir . '/' . basename((string)$photo['stored_name']);
    if (!is_file($path)) {
        return null;
    }
    $photo['path'] = $path;
    return $photo;
}

function squareSyncPayloadHash(array $item, ?array $photo): string
{
    $fields = [
        'sku', 'sku_normalized', 'status', 'what_is_it', 'date_received', 'source', 'functional',
        'condition', 'is_square', 'care_if_square', 'cords_adapters', 'keep_items_together',
        'picture_taken', 'power_on', 'brand_model', 'ram', 'ssd_gb', 'cpu', 'os', 'battery_health',
        'graphics_card', 'screen_resolution', 'where_it_goes', 'ebay_status', 'ebay_price',
        'dispotech_price', 'in_ebay_room', 'what_box', 'notes',
    ];
    $payload = [];
    foreach ($fields as $field) {
        $payload[$field] = $item[$field] ?? null;
    }
    $payload['photo_id'] = $photo['id'] ?? null;
    return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function squareSyncBuildCatalogObject(array $item, array $config, ?array $existing): array
{
    $sku = strtoupper(trim((string)($item['sku_normalized'] ?? $item['sku'] ?? '')));
    $itemId = (string)($existing['item']['id'] ?? ('#pink-item-' . squareSyncTempId($sku)));
    $variationId = (string)($existing['variation']['id'] ?? ('#pink-var-' . squareSyncTempId($sku)));
    $price = $item['dispotech_price'] ?? $item['ebay_price'] ?? null;

    $variationData = [
        'item_id' => $itemId,
        'name' => 'Default',
        'sku' => $sku,
        'track_inventory' => true,
    ];
    if ($price !== null && $price !== '' && is_numeric($price)) {
        $variationData['pricing_type'] = 'FIXED_PRICING';
        $variationData['price_money'] = [
            'amount' => (int)round(((float)$price) * 100),
            'currency' => $config['currency'],
        ];
    } else {
        $variationData['pricing_type'] = 'VARIABLE_PRICING';
    }

    $variation = [
        'type' => 'ITEM_VARIATION',
        'id' => $variationId,
        'present_at_all_locations' => true,
        'item_variation_data' => $variationData,
    ];
    if (isset($existing['variation']['version'])) {
        $variation['version'] = (int)$existing['variation']['version'];
    }

    $variations = [$variation];
    if (!empty($existing['item']['item_data']['variations']) && is_array($existing['item']['item_data']['variations'])) {
        $variations = [];
        $replaced = false;
        foreach ($existing['item']['item_data']['variations'] as $existingVariation) {
            if (!is_array($existingVariation)) {
                continue;
            }
            $existingVariationSku = strtoupper(trim((string)($existingVariation['item_variation_data']['sku'] ?? '')));
            if ((string)($existingVariation['id'] ?? '') === $variationId || $existingVariationSku === $sku) {
                $variations[] = $variation;
                $replaced = true;
            } else {
                $variations[] = squareSyncSanitizeCatalogObject($existingVariation);
            }
        }
        if (!$replaced) {
            $variations[] = $variation;
        }
    }

    $itemData = [
        'name' => squareSyncItemName($item),
        'description' => squareSyncDescription($item),
        'product_type' => 'REGULAR',
        'variations' => $variations,
    ];
    if (!empty($existing['item']['item_data']['image_ids']) && is_array($existing['item']['item_data']['image_ids'])) {
        $itemData['image_ids'] = array_values($existing['item']['item_data']['image_ids']);
    }

    $object = [
        'type' => 'ITEM',
        'id' => $itemId,
        'present_at_all_locations' => true,
        'item_data' => $itemData,
    ];
    if (isset($existing['item']['version'])) {
        $object['version'] = (int)$existing['item']['version'];
    }
    return $object;
}

function squareSyncSanitizeCatalogObject(array $object): array
{
    unset($object['created_at'], $object['updated_at']);
    return $object;
}

function squareSyncItemName(array $item): string
{
    $parts = [];
    foreach (['brand_model', 'what_is_it'] as $field) {
        $value = trim((string)($item[$field] ?? ''));
        if ($value !== '' && !in_array($value, $parts, true)) {
            $parts[] = $value;
        }
    }
    $name = $parts ? implode(' - ', $parts) : (string)($item['sku_normalized'] ?? $item['sku'] ?? 'Pinksheet Item');
    return squareSyncLimit($name, 512);
}

function squareSyncDescription(array $item): string
{
    $labels = [
        'sku' => 'SKU',
        'status' => 'Pinksheet Status',
        'what_is_it' => 'Item',
        'date_received' => 'Date Received',
        'source' => 'Source',
        'functional' => 'Functional',
        'condition' => 'Condition',
        'is_square' => 'Is Square',
        'care_if_square' => 'Care If Square',
        'cords_adapters' => 'Cords/Adapters',
        'keep_items_together' => 'Keep Items Together',
        'picture_taken' => 'Picture Taken',
        'power_on' => 'Power On',
        'brand_model' => 'Brand/Model',
        'ram' => 'RAM',
        'ssd_gb' => 'SSD',
        'cpu' => 'CPU',
        'os' => 'OS',
        'battery_health' => 'Battery Health',
        'graphics_card' => 'Graphics Card',
        'screen_resolution' => 'Screen Resolution',
        'where_it_goes' => 'Where It Goes',
        'ebay_status' => 'eBay Status',
        'in_ebay_room' => 'In eBay Room',
        'what_box' => 'Box',
        'notes' => 'Notes',
    ];
    $lines = [];
    foreach ($labels as $field => $label) {
        $value = $item[$field] ?? null;
        if ($value === null || $value === '') {
            continue;
        }
        if ($field === 'is_square' || $field === 'care_if_square') {
            $value = ((int)$value) === 1 ? 'Yes' : 'No';
        }
        $lines[] = $label . ': ' . trim((string)$value);
    }
    return squareSyncLimit(implode("\n", $lines), 4000);
}

function squareSyncFindExistingCatalogObject(array $config, ?array $syncRow, string $skuNormalized): ?array
{
    $itemId = trim((string)($syncRow['square_item_id'] ?? ''));
    if ($itemId !== '') {
        try {
            $resp = squareSyncApiJson($config, 'GET', '/v2/catalog/object/' . rawurlencode($itemId) . '?include_related_objects=true');
            $found = squareSyncExtractItemAndVariation($resp['object'] ?? null, $resp['related_objects'] ?? [], $skuNormalized);
            if ($found) {
                return $found;
            }
        } catch (Throwable $e) {
            squareSyncLog('Catalog retrieve failed for ' . $skuNormalized . ': ' . $e->getMessage());
        }
    }

    try {
        $resp = squareSyncApiJson($config, 'POST', '/v2/catalog/search-catalog-items', [
            'text_filter' => $skuNormalized,
            'product_types' => ['REGULAR'],
            'limit' => 10,
        ]);
        foreach (($resp['items'] ?? []) as $item) {
            $found = squareSyncExtractItemAndVariation($item, [], $skuNormalized);
            if ($found) {
                return $found;
            }
        }
    } catch (Throwable $e) {
        squareSyncLog('Catalog search failed for ' . $skuNormalized . ': ' . $e->getMessage());
    }

    return null;
}

function squareSyncExtractItemAndVariation($item, array $related, string $skuNormalized): ?array
{
    if (!is_array($item) || ($item['type'] ?? '') !== 'ITEM') {
        return null;
    }
    $variations = $item['item_data']['variations'] ?? [];
    foreach ($related as $object) {
        if (is_array($object) && ($object['type'] ?? '') === 'ITEM_VARIATION') {
            $variations[] = $object;
        }
    }
    foreach ($variations as $variation) {
        if (!is_array($variation)) {
            continue;
        }
        $variationSku = strtoupper(trim((string)($variation['item_variation_data']['sku'] ?? '')));
        if ($variationSku === $skuNormalized) {
            return ['item' => $item, 'variation' => $variation];
        }
    }
    return null;
}

function squareSyncFindCatalogObject($catalogObject, array $idMappings, string $type): ?array
{
    if (is_array($catalogObject) && ($catalogObject['type'] ?? '') === $type) {
        return $catalogObject;
    }
    foreach ($idMappings as $mapping) {
        if (($mapping['object_type'] ?? '') === $type && !empty($mapping['object_id'])) {
            return ['id' => (string)$mapping['object_id']];
        }
    }
    return null;
}

function squareSyncFindVariationObject($catalogObject, array $idMappings): ?array
{
    if (is_array($catalogObject)) {
        foreach (($catalogObject['item_data']['variations'] ?? []) as $variation) {
            if (is_array($variation) && ($variation['type'] ?? '') === 'ITEM_VARIATION') {
                return $variation;
            }
        }
    }
    foreach ($idMappings as $mapping) {
        if (($mapping['object_type'] ?? '') === 'ITEM_VARIATION' && !empty($mapping['object_id'])) {
            return ['id' => (string)$mapping['object_id']];
        }
    }
    return null;
}

function squareSyncUploadImage(array $config, string $itemId, string $skuNormalized, array $photo): ?array
{
    $mime = (string)($photo['mime_type'] ?? '');
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true)) {
        squareSyncLog('Skipping Square image upload for ' . $skuNormalized . ': unsupported MIME ' . $mime);
        return null;
    }
    if (!class_exists('CURLFile')) {
        throw new RuntimeException('PHP cURL is required for Square image uploads.');
    }

    $name = trim((string)($photo['original_name'] ?? $skuNormalized . '-photo'));
    $request = [
        'idempotency_key' => 'pink-img-' . substr(hash('sha256', $skuNormalized . ':' . (string)$photo['id'] . ':' . (string)filesize((string)$photo['path'])), 0, 28),
        'object_id' => $itemId,
        'is_primary' => true,
        'image' => [
            'type' => 'IMAGE',
            'id' => '#pink-image-' . squareSyncTempId($skuNormalized . '-' . (string)$photo['id']),
            'image_data' => [
                'name' => squareSyncLimit($name !== '' ? $name : $skuNormalized, 255),
                'caption' => 'Pinksheet photo for SKU ' . $skuNormalized,
            ],
        ],
    ];
    $resp = squareSyncApiMultipart($config, '/v2/catalog/images', [
        'request' => json_encode($request, JSON_THROW_ON_ERROR),
        'image_file' => new CURLFile((string)$photo['path'], $mime, $name !== '' ? $name : 'photo'),
    ]);
    return is_array($resp['image'] ?? null) ? $resp['image'] : null;
}

function squareSyncSetInventoryCount(array $config, string $variationId, string $status, string $skuNormalized, string $payloadHash): void
{
    $quantity = strtoupper(trim($status)) === 'SOLD' ? 0 : (int)$config['default_quantity'];
    squareSyncApiJson($config, 'POST', '/v2/inventory/changes/batch-create', [
        'idempotency_key' => 'pink-inv-' . substr(hash('sha256', $skuNormalized . ':' . $variationId . ':' . $quantity . ':' . $payloadHash), 0, 28),
        'changes' => [[
            'type' => 'PHYSICAL_COUNT',
            'physical_count' => [
                'catalog_object_id' => $variationId,
                'location_id' => $config['location_id'],
                'quantity' => (string)$quantity,
                'state' => 'IN_STOCK',
                'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
        ]],
    ]);
}

function squareSyncSaveRow(PDO $pdo, string $skuNormalized, array $data): void
{
    $stmt = $pdo->prepare(<<<'SQL'
INSERT INTO square_catalog_sync (
    sku_normalized, square_item_id, square_item_version, square_variation_id, square_variation_version,
    square_image_id, square_image_photo_id, payload_hash, last_synced_at, last_error, updated_at
) VALUES (
    :sku_normalized, :square_item_id, :square_item_version, :square_variation_id, :square_variation_version,
    :square_image_id, :square_image_photo_id, :payload_hash, datetime('now'), :last_error, datetime('now')
)
ON CONFLICT(sku_normalized) DO UPDATE SET
    square_item_id = excluded.square_item_id,
    square_item_version = excluded.square_item_version,
    square_variation_id = excluded.square_variation_id,
    square_variation_version = excluded.square_variation_version,
    square_image_id = excluded.square_image_id,
    square_image_photo_id = excluded.square_image_photo_id,
    payload_hash = excluded.payload_hash,
    last_synced_at = excluded.last_synced_at,
    last_error = excluded.last_error,
    updated_at = excluded.updated_at
SQL);
    $stmt->execute([
        'sku_normalized' => $skuNormalized,
        'square_item_id' => $data['square_item_id'] ?? null,
        'square_item_version' => $data['square_item_version'] ?? null,
        'square_variation_id' => $data['square_variation_id'] ?? null,
        'square_variation_version' => $data['square_variation_version'] ?? null,
        'square_image_id' => $data['square_image_id'] ?? null,
        'square_image_photo_id' => $data['square_image_photo_id'] ?? null,
        'payload_hash' => $data['payload_hash'] ?? null,
        'last_error' => $data['last_error'] ?? null,
    ]);
}

function squareSyncRecordError(PDO $pdo, string $skuNormalized, string $message): void
{
    squareSyncEnsureSchema($pdo);
    $stmt = $pdo->prepare(<<<'SQL'
INSERT INTO square_catalog_sync (sku_normalized, last_error, updated_at)
VALUES (:sku_normalized, :last_error, datetime('now'))
ON CONFLICT(sku_normalized) DO UPDATE SET
    last_error = excluded.last_error,
    updated_at = excluded.updated_at
SQL);
    $stmt->execute([
        'sku_normalized' => $skuNormalized,
        'last_error' => squareSyncLimit($message, 1000),
    ]);
}

function squareSyncApiJson(array $config, string $method, string $path, ?array $body = null): array
{
    $headers = [
        'Square-Version: ' . $config['api_version'],
        'Authorization: Bearer ' . $config['token'],
        'Accept: application/json',
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    $payload = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR);
    return squareSyncCurl($config['base_url'] . $path, $method, $headers, $payload);
}

function squareSyncApiMultipart(array $config, string $path, array $fields): array
{
    return squareSyncCurl($config['base_url'] . $path, 'POST', [
        'Square-Version: ' . $config['api_version'],
        'Authorization: Bearer ' . $config['token'],
        'Accept: application/json',
    ], $fields);
}

function squareSyncCurl(string $url, string $method, array $headers, $payload): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required for Square sync.');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($raw === false || $err !== '') {
        throw new RuntimeException('Square request failed: ' . $err);
    }
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Square returned a non-JSON response with status ' . $code);
    }
    if ($code < 200 || $code >= 300) {
        $errors = [];
        foreach (($decoded['errors'] ?? []) as $error) {
            if (is_array($error)) {
                $errors[] = trim((string)($error['category'] ?? '') . ' ' . (string)($error['code'] ?? '') . ': ' . (string)($error['detail'] ?? ''));
            }
        }
        throw new RuntimeException('Square API error ' . $code . ': ' . ($errors ? implode('; ', $errors) : substr((string)$raw, 0, 500)));
    }
    return $decoded;
}

function squareSyncTempId(string $value): string
{
    return substr(preg_replace('/[^A-Za-z0-9_-]+/', '-', $value) ?: 'sku', 0, 24) . '-' . substr(hash('sha256', $value), 0, 10);
}

function squareSyncLimit(string $value, int $max): string
{
    if (strlen($value) <= $max) {
        return $value;
    }
    return substr($value, 0, max(0, $max - 3)) . '...';
}

function squareSyncLog(string $message): void
{
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @file_put_contents($dir . '/square_sync.log', '[' . date('c') . '] ' . $message . PHP_EOL, FILE_APPEND);
}
