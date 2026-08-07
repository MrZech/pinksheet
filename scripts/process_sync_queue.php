<?php
declare(strict_types=1);

// Process pending jobs in the Square sync queue.
// Usage:
//   php scripts/process_sync_queue.php              (process up to 10 jobs, then exit)
//   php scripts/process_sync_queue.php --limit=50   (process up to 50 jobs)
//   php scripts/process_sync_queue.php --daemon     (loop continuously)

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../square_sync.php';
require_once __DIR__ . '/../square_sync_queue.php';
require_once __DIR__ . '/../square_audit.php';

$limit = 10;
$daemon = false;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i] ?? '';
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int)substr($arg, 7));
    } elseif ($arg === '--daemon') {
        $daemon = true;
        $limit = 10;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Usage: php scripts/process_sync_queue.php [--limit=N] [--daemon]\n";
        exit(0);
    }
}

ensureStorageWritable();
$pdo = pdoConnect(__DIR__ . '/../data/intake.sqlite');
squareSyncEnsureSchema($pdo);
squareQueueEnsureSchema($pdo);

do {
    $jobs = squareQueueDequeue($pdo, $limit);
    if (empty($jobs)) {
        if (!$daemon) {
            echo "No pending jobs.\n";
            exit(0);
        }
        sleep(5);
        continue;
    }

    foreach ($jobs as $job) {
        $jobId = (int)$job['id'];
        $skuNormalized = (string)$job['sku_normalized'];
        $operation = (string)$job['operation'];
        $correlationId = 'queue-' . $jobId . '-' . bin2hex(random_bytes(4));

        squareQueueMarkProcessing($pdo, $jobId);

        $start = microtime(true);
        $result = processJob($pdo, $skuNormalized, $operation);

        $durationMs = (int)((microtime(true) - $start) * 1000);

        // Treat both 'ok' and 'skipped' as success: a queued job for an item
        // that is already current in Square (status 'skipped') is not a
        // failure — it must not be recorded as failed or it will linger in
        // the queue forever.
        if (in_array($result['status'], ['ok', 'skipped'], true)) {
            squareQueueMarkCompleted($pdo, $jobId);
            echo '[OK] ' . $skuNormalized . ' [' . $operation . '] ' . ($result['message'] ?? '') . ' (' . $durationMs . 'ms)' . PHP_EOL;
            squareAuditLog($pdo, [
                'operation' => $operation,
                'sku_normalized' => $skuNormalized,
                'direction' => 'push',
                'status' => 'success',
                'duration_ms' => $durationMs,
                'queue_job_id' => $jobId,
                'correlation_id' => $correlationId,
                'object_type' => $operation === 'inventory_set' ? 'ITEM_VARIATION' : 'ITEM',
                'response_summary' => $result['message'] ?? '',
            ]);
        } else {
            $errorMsg = $result['message'] ?? 'Unknown error';
            squareQueueMarkFailed($pdo, $jobId, $errorMsg);
            echo '[FAIL] ' . $skuNormalized . ' [' . $operation . '] ' . $errorMsg . ' (' . $durationMs . 'ms)' . PHP_EOL;
            squareAuditLog($pdo, [
                'operation' => $operation,
                'sku_normalized' => $skuNormalized,
                'direction' => 'push',
                'status' => 'failure',
                'duration_ms' => $durationMs,
                'error_message' => $errorMsg,
                'queue_job_id' => $jobId,
                'correlation_id' => $correlationId,
                'object_type' => $operation === 'inventory_set' ? 'ITEM_VARIATION' : 'ITEM',
                'retry_count' => (int)($job['retry_count'] ?? 0) + 1,
            ]);
        }
    }
} while ($daemon);

exit(0);

function processJob(PDO $pdo, string $skuNormalized, string $operation): array
{
    return match ($operation) {
        'catalog_upsert', 'full_sync' => squareSyncItemBySku($pdo, $skuNormalized),
        'inventory_set' => processInventorySet($pdo, $skuNormalized),
        'inventory_pull' => processInventoryPull($pdo, $skuNormalized),
        'photo_upload' => processPhotoUpload($pdo, $skuNormalized),
        default => ['status' => 'error', 'message' => 'Unknown operation: ' . $operation],
    };
}

function processInventorySet(PDO $pdo, string $skuNormalized): array
{
    $config = squareSyncConfig();
    if (!$config['enabled']) {
        return ['status' => 'error', 'message' => 'Square sync is not configured'];
    }

    $syncRow = squareSyncLoadRow($pdo, $skuNormalized);
    if (!$syncRow) {
        return ['status' => 'error', 'message' => 'No sync mapping for ' . $skuNormalized];
    }

    $variationId = (string)($syncRow['square_variation_id'] ?? '');
    if ($variationId === '') {
        return ['status' => 'error', 'message' => 'No square_variation_id for ' . $skuNormalized];
    }

    $item = squareSyncLoadItem($pdo, $skuNormalized);
    $status = (string)($item['status'] ?? '');

    try {
        $itemQty = max(1, (int)($item['quantity'] ?? 1));
        squareSyncSetInventoryCount($config, $variationId, $status, $skuNormalized, $syncRow['payload_hash'] ?? '', $itemQty);
        $pdo->prepare("UPDATE square_catalog_sync SET last_inventory_sync = datetime('now') WHERE sku_normalized = :sku")
            ->execute(['sku' => $skuNormalized]);
        return ['status' => 'ok', 'message' => 'Inventory set for ' . $skuNormalized];
    } catch (Throwable $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

function processPhotoUpload(PDO $pdo, string $skuNormalized): array
{
    return squareSyncItemBySku($pdo, $skuNormalized);
}

function processInventoryPull(PDO $pdo, string $skuNormalized): array
{
    $config = squareSyncConfig();
    if (!$config['enabled']) {
        return ['status' => 'error', 'message' => 'Square sync is not configured'];
    }
    return squareSyncPullItem($pdo, $skuNormalized);
}
