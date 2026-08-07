<?php
declare(strict_types=1);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/../../square_sync.php';

function reconDetectIssues(PDO $pdo, int $runId, bool $fetchSquareCatalog = true): array
{
    $detected = 0;
    $startAll = microtime(true);

    // Phase 1: Local checks (no Square API calls)
    $detected += reconCheckMissingMappings($pdo, $runId);
    $detected += reconCheckNeverSynced($pdo, $runId);
    $detected += reconCheckStuckRetries($pdo, $runId);
    $detected += reconCheckPriceMismatches($pdo, $runId);
    $detected += reconCheckInventoryMismatches($pdo, $runId);
    $detected += reconCheckMissingImages($pdo, $runId);
    $detected += reconCheckSoldInSquareNotPinksheet($pdo, $runId);

    // Phase 2: Cross-reference with Square catalog
    $apiMade = 0;
    $apiFailed = 0;
    if ($fetchSquareCatalog) {
        $config = squareSyncConfig();
        if ($config['enabled']) {
            $result = reconCheckOrphanedItems($pdo, $runId, $config);
            $detected += $result['detected'];
            $apiMade = $result['api_made'] ?? 0;
            $apiFailed = $result['api_failed'] ?? 0;
        }
    }

    $runtime = microtime(true) - $startAll;
    reconUpdateRun($pdo, $runId, [
        'total_devices_checked' => (int)$pdo->query("SELECT COUNT(*) FROM intake_items WHERE sku_normalized IS NOT NULL AND TRIM(sku_normalized) <> ''")->fetchColumn(),
        'issues_detected' => $detected,
        'api_requests_made' => $apiMade,
        'api_requests_failed' => $apiFailed,
        'runtime_seconds' => round($runtime, 3),
    ]);

    return ['detected' => $detected, 'runtime' => $runtime, 'api_made' => $apiMade, 'api_failed' => $apiFailed];
}

function reconCheckMissingMappings(PDO $pdo, int $runId): int
{
    $stmt = $pdo->query(<<<'SQL'
SELECT i.sku_normalized, i.status, i.dispotech_price, i.ebay_price
FROM intake_items i
LEFT JOIN square_catalog_sync s ON s.sku_normalized = i.sku_normalized
WHERE s.sku_normalized IS NULL
  AND i.sku_normalized IS NOT NULL AND TRIM(i.sku_normalized) <> ''
SQL);
    $count = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sku = (string)($row['sku_normalized'] ?? '');
        if ($sku === '') continue;
        $price = $row['dispotech_price'] ?? $row['ebay_price'] ?? null;
        reconAddIssue($pdo, $runId, [
            'sku_normalized' => $sku,
            'issue_type' => 'missing_catalog_mapping',
            'severity' => $price !== null && $price !== '' && (float)$price > 0 ? 'warning' : 'info',
            'description' => "SKU $sku has no Square catalog mapping",
            'auto_repairable' => true,
            'repair_action' => 'catalog_upsert',
        ]);
        $count++;
    }
    return $count;
}

function reconCheckNeverSynced(PDO $pdo, int $runId): int
{
    $stmt = $pdo->query(<<<'SQL'
SELECT s.sku_normalized
FROM square_catalog_sync s
WHERE s.last_synced_at IS NULL
  AND (s.square_item_id IS NULL OR s.square_item_id = '')
ORDER BY s.sku_normalized
SQL);
    $count = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sku = (string)($row['sku_normalized'] ?? '');
        if ($sku === '') continue;
        reconAddIssue($pdo, $runId, [
            'sku_normalized' => $sku,
            'issue_type' => 'never_synced',
            'severity' => 'warning',
            'description' => "SKU $sku has a sync row but has never been pushed to Square",
            'auto_repairable' => true,
            'repair_action' => 'full_sync',
        ]);
        $count++;
    }
    return $count;
}

function reconCheckStuckRetries(PDO $pdo, int $runId): int
{
    $stmt = $pdo->query(<<<'SQL'
SELECT s.sku_normalized, s.last_error, s.updated_at
FROM square_catalog_sync s
WHERE s.last_error IS NOT NULL AND s.last_error <> ''
  AND (s.last_synced_at IS NULL OR s.last_synced_at < datetime('now', '-1 day'))
ORDER BY s.updated_at DESC
LIMIT 200
SQL);
    $count = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sku = (string)($row['sku_normalized'] ?? '');
        if ($sku === '') continue;
        reconAddIssue($pdo, $runId, [
            'sku_normalized' => $sku,
            'issue_type' => 'stuck_retry',
            'severity' => 'warning',
            'description' => "SKU $sku has a persistent sync error: " . ($row['last_error'] ?? ''),
            'pinksheet_value' => $row['last_error'] ?? '',
            'auto_repairable' => true,
            'repair_action' => 'full_sync',
        ]);
        $count++;
    }

    $queueStuck = $pdo->query(<<<'SQL'
SELECT q.id, q.sku_normalized, q.operation, q.retry_count, q.last_error
FROM sync_queue q
WHERE q.status = 'dead_letter'
ORDER BY q.updated_at DESC
LIMIT 100
SQL);
    while ($row = $queueStuck->fetch(PDO::FETCH_ASSOC)) {
        $sku = (string)($row['sku_normalized'] ?? '');
        reconAddIssue($pdo, $runId, [
            'sku_normalized' => $sku,
            'issue_type' => 'queue_dead_letter',
            'severity' => 'warning',
            'description' => "Queue dead letter for $sku (" . ($row['operation'] ?? '') . '): ' . ($row['last_error'] ?? ''),
            'pinksheet_value' => 'retries: ' . ($row['retry_count'] ?? 0),
            'auto_repairable' => true,
            'repair_action' => 'reset_queue',
        ]);
        $count++;
    }

    return $count;
}

function reconCheckPriceMismatches(PDO $pdo, int $runId): int
{
    $stmt = $pdo->query(<<<'SQL'
SELECT i.sku_normalized, i.dispotech_price, i.ebay_price,
       s.square_item_id, s.square_variation_id, s.last_synced_at
FROM intake_items i
JOIN square_catalog_sync s ON s.sku_normalized = i.sku_normalized
WHERE s.last_synced_at IS NOT NULL
  AND (i.dispotech_price IS NOT NULL OR i.ebay_price IS NOT NULL)
  AND i.sku_normalized IS NOT NULL AND TRIM(i.sku_normalized) <> ''
ORDER BY i.sku_normalized
SQL);
    $count = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sku = (string)($row['sku_normalized'] ?? '');
        if ($sku === '') continue;
        $psPrice = $row['dispotech_price'] ?? $row['ebay_price'] ?? null;
        if ($psPrice === null || $psPrice === '') continue;
        $psPrice = (float)$psPrice;
        if ($psPrice <= 0) continue;

        // We can't directly compare prices without fetching the Square object.
        // Instead, check if the hash likely changed. If price differs from what
        // was last synced, flag it.
        $item = squareSyncLoadItem($pdo, $sku);
        $photo = squareSyncLoadPreferredPhoto($pdo, $sku);
        if (!$item) continue;
        $currentHash = squareSyncPayloadHash($item, $photo);
        $syncRow = squareSyncLoadRow($pdo, $sku);
        $storedHash = (string)($syncRow['payload_hash'] ?? '');
        $error = (string)($syncRow['last_error'] ?? '');

        if ($storedHash !== '' && $currentHash !== $storedHash && $error === '') {
            reconAddIssue($pdo, $runId, [
                'sku_normalized' => $sku,
                'issue_type' => 'pending_update',
                'severity' => 'info',
                'description' => "SKU $sku has pending changes (hash mismatch) — needs re-sync",
                'auto_repairable' => true,
                'repair_action' => 'full_sync',
            ]);
            $count++;
        }
    }
    return $count;
}

function reconCheckInventoryMismatches(PDO $pdo, int $runId): int
{
    $stmt = $pdo->query(<<<'SQL'
SELECT i.sku_normalized, i.status, s.square_variation_id, s.last_synced_at, s.last_error
FROM intake_items i
JOIN square_catalog_sync s ON s.sku_normalized = i.sku_normalized
WHERE s.square_variation_id IS NOT NULL AND s.square_variation_id <> ''
  AND s.last_synced_at IS NOT NULL
  AND i.sku_normalized IS NOT NULL AND TRIM(i.sku_normalized) <> ''
  AND (s.last_error IS NULL OR s.last_error = '')
SQL);
    $count = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sku = (string)($row['sku_normalized'] ?? '');
        if ($sku === '') continue;
        $status = strtolower(trim((string)($row['status'] ?? '')));
        $shouldBeSold = $status === 'sold';

        // Check if sales_history says it's sold but intake_items doesn't
        $historyCheck = $pdo->prepare("SELECT COUNT(*) FROM sales_history WHERE sku_normalized = :sku");
        $historyCheck->execute(['sku' => $sku]);
        $hasSaleRecord = (int)$historyCheck->fetchColumn() > 0;

        if ($hasSaleRecord && !$shouldBeSold) {
            reconAddIssue($pdo, $runId, [
                'sku_normalized' => $sku,
                'issue_type' => 'sold_in_square_not_marked',
                'severity' => 'critical',
                'description' => "SKU $sku has Square sale records but is not marked as sold in Pinksheet",
                'pinksheet_value' => 'status=' . ($row['status'] ?? ''),
                'square_value' => 'has sale record',
                'auto_repairable' => true,
                'repair_action' => 'mark_sold',
            ]);
            $count++;
        }
    }
    return $count;
}

function reconCheckMissingImages(PDO $pdo, int $runId): int
{
    $stmt = $pdo->query(<<<'SQL'
SELECT p.sku_normalized, COUNT(*) as photo_count, s.square_image_id
FROM sku_photos p
LEFT JOIN square_catalog_sync s ON s.sku_normalized = p.sku_normalized
WHERE s.square_image_id IS NULL OR s.square_image_id = ''
GROUP BY p.sku_normalized
HAVING photo_count > 0
ORDER BY photo_count DESC
LIMIT 100
SQL);
    $count = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sku = (string)($row['sku_normalized'] ?? '');
        if ($sku === '') continue;
        reconAddIssue($pdo, $runId, [
            'sku_normalized' => $sku,
            'issue_type' => 'missing_image',
            'severity' => 'warning',
            'description' => "SKU $sku has " . ($row['photo_count'] ?? 0) . ' photo(s) but no Square image mapping',
            'pinksheet_value' => 'photos: ' . ($row['photo_count'] ?? 0),
            'auto_repairable' => true,
            'repair_action' => 'full_sync',
        ]);
        $count++;
    }
    return $count;
}

function reconCheckSoldInSquareNotPinksheet(PDO $pdo, int $runId): int
{
    $stmt = $pdo->query(<<<'SQL'
SELECT DISTINCT h.sku_normalized, i.status
FROM sales_history h
LEFT JOIN intake_items i ON i.sku_normalized = h.sku_normalized
WHERE (i.status IS NULL OR LOWER(TRIM(i.status)) != 'sold')
  AND h.sku_normalized IS NOT NULL AND TRIM(h.sku_normalized) <> ''
ORDER BY h.sold_at DESC
LIMIT 100
SQL);
    $count = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sku = (string)($row['sku_normalized'] ?? '');
        if ($sku === '') continue;
        $currentStatus = (string)($row['status'] ?? '');
        reconAddIssue($pdo, $runId, [
            'sku_normalized' => $sku,
            'issue_type' => 'sold_in_square_not_marked',
            'severity' => 'critical',
            'description' => "SKU $sku was sold via Square but status is '$currentStatus' (not 'sold')",
            'pinksheet_value' => 'status=' . $currentStatus,
            'square_value' => 'sale recorded in sales_history',
            'auto_repairable' => true,
            'repair_action' => 'mark_sold',
        ]);
        $count++;
    }
    return $count;
}

function reconCheckOrphanedItems(PDO $pdo, int $runId, array $config): array
{
    $detected = 0;
    $apiMade = 0;
    $apiFailed = 0;

    try {
        $cursor = null;
        $squareSkus = [];

        do {
            $params = [
                'include_deleted_objects' => false,
                'types' => ['ITEM'],
                'limit' => 200,
            ];
            if ($cursor !== null) {
                $params['cursor'] = $cursor;
            }

            $resp = squareSyncApiJson($config, 'POST', '/v2/catalog/search', $params);
            $apiMade++;

            foreach (($resp['objects'] ?? []) as $obj) {
                if (!is_array($obj) || ($obj['type'] ?? '') !== 'ITEM') continue;
                $variations = $obj['item_data']['variations'] ?? [];
                foreach ($variations as $var) {
                    if (!is_array($var)) continue;
                    $sku = strtoupper(trim((string)($var['item_variation_data']['sku'] ?? '')));
                    if ($sku !== '') {
                        $squareSkus[$sku] = [
                            'item_id' => (string)($obj['id'] ?? ''),
                            'variation_id' => (string)($var['id'] ?? ''),
                            'name' => (string)($obj['item_data']['name'] ?? ''),
                        ];
                    }
                }
            }

            $cursor = $resp['cursor'] ?? null;
        } while ($cursor !== null);

        // Check for orphaned items (in Square but not in Pinksheet or sync table)
        foreach ($squareSkus as $sku => $sqData) {
            $stmt = $pdo->prepare("SELECT 1 FROM intake_items WHERE sku_normalized = :sku LIMIT 1");
            $stmt->execute(['sku' => $sku]);
            $inPinksheet = (bool)$stmt->fetchColumn();

            if (!$inPinksheet) {
                $syncStmt = $pdo->prepare("SELECT 1 FROM square_catalog_sync WHERE sku_normalized = :sku LIMIT 1");
                $syncStmt->execute(['sku' => $sku]);
                $inSync = (bool)$syncStmt->fetchColumn();

                reconAddIssue($pdo, $runId, [
                    'sku_normalized' => $sku,
                    'issue_type' => 'orphaned_square_item',
                    'severity' => 'warning',
                    'description' => "Square catalog item '$sku' (" . $sqData['name'] . ") has no Pinksheet counterpart" . ($inSync ? ' (sync mapping exists but item deleted)' : ''),
                    'square_value' => 'item_id=' . $sqData['item_id'],
                    'auto_repairable' => false,
                    'repair_action' => 'manual_review',
                ]);
                $detected++;
            }
        }

        // Check duplicate catalog IDs
        $seenIds = [];
        foreach ($squareSkus as $sku => $sqData) {
            $iid = $sqData['item_id'];
            if (isset($seenIds[$iid])) {
                reconAddIssue($pdo, $runId, [
                    'sku_normalized' => $sku,
                    'issue_type' => 'duplicate_catalog_id',
                    'severity' => 'critical',
                    'description' => "Square item ID $iid mapped to multiple SKUs: " . $seenIds[$iid] . " and $sku",
                    'square_value' => 'item_id=' . $iid,
                    'auto_repairable' => false,
                    'repair_action' => 'manual_review',
                ]);
                $detected++;
            }
            $seenIds[$iid] = $sku;
        }

    } catch (Throwable $e) {
        squareSyncLog('Reconciliation catalog fetch failed: ' . $e->getMessage());
        $apiFailed++;
    }

    return ['detected' => $detected, 'api_made' => $apiMade, 'api_failed' => $apiFailed];
}
