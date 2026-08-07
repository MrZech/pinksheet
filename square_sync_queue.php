<?php
declare(strict_types=1);

const SQUARE_QUEUE_TABLE_SQL = <<<'SQL'
CREATE TABLE IF NOT EXISTS sync_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sku_normalized TEXT NOT NULL,
    operation TEXT NOT NULL CHECK (operation IN ('catalog_upsert', 'inventory_set', 'inventory_pull', 'photo_upload', 'full_sync')),
    priority INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'processing', 'completed', 'failed', 'retrying', 'dead_letter')),
    retry_count INTEGER NOT NULL DEFAULT 0,
    max_retries INTEGER NOT NULL DEFAULT 10,
    last_error TEXT,
    last_attempt_at TEXT,
    next_retry_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(sku_normalized, operation)
);
SQL;

function squareQueueEnsureSchema(PDO $pdo): void
{
    $pdo->exec(SQUARE_QUEUE_TABLE_SQL);
}

function squareQueueEnqueue(PDO $pdo, string $skuNormalized, string $operation, int $priority = 0): void
{
    squareQueueEnsureSchema($pdo);
    $stmt = $pdo->prepare(<<<'SQL'
INSERT OR IGNORE INTO sync_queue (sku_normalized, operation, priority)
VALUES (:sku_normalized, :operation, :priority)
SQL);
    $stmt->execute([
        'sku_normalized' => $skuNormalized,
        'operation' => $operation,
        'priority' => $priority,
    ]);
}

function squareQueueDequeue(PDO $pdo, int $limit = 10): array
{
    squareQueueEnsureSchema($pdo);
    // Atomically claim jobs so overlapping workers (a scheduled run while a
    // manual run is active) never process the same job twice.  RETURNING
    // requires SQLite >= 3.35 (bundled with PHP 8.1+).
    $stmt = $pdo->prepare(<<<'SQL'
UPDATE sync_queue
SET status = 'processing', last_attempt_at = datetime('now'), updated_at = datetime('now')
WHERE id IN (
    SELECT id FROM sync_queue
    WHERE status IN ('queued', 'retrying')
      AND (next_retry_at IS NULL OR next_retry_at <= datetime('now'))
    ORDER BY priority DESC, created_at ASC
    LIMIT :lim
)
RETURNING id, sku_normalized, operation, priority
SQL);
    $stmt->execute(['lim' => $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function squareQueueMarkProcessing(PDO $pdo, int $jobId): void
{
    $stmt = $pdo->prepare("UPDATE sync_queue SET status = 'processing', last_attempt_at = datetime('now'), updated_at = datetime('now') WHERE id = :id");
    $stmt->execute(['id' => $jobId]);
}

function squareQueueMarkCompleted(PDO $pdo, int $jobId): void
{
    $stmt = $pdo->prepare("UPDATE sync_queue SET status = 'completed', updated_at = datetime('now') WHERE id = :id");
    $stmt->execute(['id' => $jobId]);
}

function squareQueueMarkFailed(PDO $pdo, int $jobId, string $error): void
{
    $row = $pdo->prepare('SELECT retry_count, max_retries FROM sync_queue WHERE id = :id');
    $row->execute(['id' => $jobId]);
    $job = $row->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
        return;
    }

    $retryCount = (int)($job['retry_count'] ?? 0) + 1;
    $maxRetries = (int)($job['max_retries'] ?? 10);
    $nextRetry = squareQueueNextRetry($retryCount);
    $newStatus = $retryCount >= $maxRetries ? 'dead_letter' : 'retrying';

    $stmt = $pdo->prepare(<<<'SQL'
UPDATE sync_queue SET
    status = :status,
    retry_count = :retry_count,
    last_error = :error,
    last_attempt_at = datetime('now'),
    next_retry_at = :next_retry,
    updated_at = datetime('now')
WHERE id = :id
SQL);
    $stmt->execute([
        'id' => $jobId,
        'status' => $newStatus,
        'retry_count' => $retryCount,
        'error' => $error,
        'next_retry' => $nextRetry,
    ]);
}

function squareQueueNextRetry(int $retryCount): string
{
    $delays = [30, 60, 120, 300, 600, 1800, 3600, 7200, 14400, 28800];
    $index = min($retryCount - 1, count($delays) - 1);
    $delaySeconds = $delays[$index] + random_int(0, 30);
    return date('Y-m-d H:i:s', time() + $delaySeconds);
}

function squareQueueResetDeadLetter(PDO $pdo, ?string $skuNormalized = null): void
{
    squareQueueEnsureSchema($pdo);
    if ($skuNormalized !== null) {
        $stmt = $pdo->prepare("UPDATE sync_queue SET status = 'queued', retry_count = 0, last_error = NULL, updated_at = datetime('now') WHERE status = 'dead_letter' AND sku_normalized = :sku");
        $stmt->execute(['sku' => $skuNormalized]);
    } else {
        $pdo->exec("UPDATE sync_queue SET status = 'queued', retry_count = 0, last_error = NULL, updated_at = datetime('now') WHERE status = 'dead_letter'");
    }
}

function squareQueueStats(PDO $pdo): array
{
    squareQueueEnsureSchema($pdo);
    $stats = [];
    foreach (['queued', 'processing', 'completed', 'failed', 'retrying', 'dead_letter'] as $status) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sync_queue WHERE status = :s");
        $stmt->execute(['s' => $status]);
        $stats[$status] = (int)$stmt->fetchColumn();
    }
    return $stats;
}
