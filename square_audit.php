<?php
declare(strict_types=1);

const SQUARE_AUDIT_TABLE_SQL = <<<'SQL'
CREATE TABLE IF NOT EXISTS sync_audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    timestamp TEXT NOT NULL DEFAULT (datetime('now')),
    operation TEXT NOT NULL,
    sku_normalized TEXT,
    direction TEXT NOT NULL CHECK (direction IN ('push', 'pull')),
    status TEXT NOT NULL CHECK (status IN ('success', 'failure', 'skipped')),
    duration_ms INTEGER,
    retry_count INTEGER DEFAULT 0,
    error_message TEXT,
    object_type TEXT,
    object_id TEXT,
    queue_job_id INTEGER,
    webhook_id TEXT,
    correlation_id TEXT,
    request_body_hash TEXT,
    response_summary TEXT
);
SQL;

function squareAuditEnsureSchema(PDO $pdo): void
{
    $pdo->exec(SQUARE_AUDIT_TABLE_SQL);
    $cols = $pdo->query("PRAGMA table_info(sync_audit_log)")->fetchAll(PDO::FETCH_COLUMN, 1);
    $optionalColumns = [
        'object_type' => 'TEXT',
        'object_id' => 'TEXT',
        'queue_job_id' => 'INTEGER',
        'webhook_id' => 'TEXT',
        'correlation_id' => 'TEXT',
    ];
    foreach ($optionalColumns as $column => $definition) {
        if (!in_array($column, $cols, true)) {
            $pdo->exec('ALTER TABLE sync_audit_log ADD COLUMN ' . $column . ' ' . $definition);
        }
    }
}

function squareAuditLog(PDO $pdo, array $entry): void
{
    squareAuditEnsureSchema($pdo);
    $stmt = $pdo->prepare(<<<'SQL'
INSERT INTO sync_audit_log (
    operation, sku_normalized, direction, status,
    duration_ms, retry_count, error_message,
    object_type, object_id, queue_job_id, webhook_id, correlation_id,
    request_body_hash, response_summary
) VALUES (
    :operation, :sku_normalized, :direction, :status,
    :duration_ms, :retry_count, :error_message,
    :object_type, :object_id, :queue_job_id, :webhook_id, :correlation_id,
    :request_body_hash, :response_summary
)
SQL);
    $stmt->execute([
        'operation' => $entry['operation'] ?? '',
        'sku_normalized' => $entry['sku_normalized'] ?? null,
        'direction' => $entry['direction'] ?? 'push',
        'status' => squareAuditNormalizeStatus((string)($entry['status'] ?? 'success')),
        'duration_ms' => isset($entry['duration_ms']) ? (int)$entry['duration_ms'] : null,
        'retry_count' => isset($entry['retry_count']) ? (int)$entry['retry_count'] : 0,
        'error_message' => $entry['error_message'] ?? null,
        'object_type' => $entry['object_type'] ?? null,
        'object_id' => $entry['object_id'] ?? null,
        'queue_job_id' => isset($entry['queue_job_id']) ? (int)$entry['queue_job_id'] : null,
        'webhook_id' => $entry['webhook_id'] ?? null,
        'correlation_id' => $entry['correlation_id'] ?? null,
        'request_body_hash' => $entry['request_body_hash'] ?? null,
        'response_summary' => $entry['response_summary'] ?? null,
    ]);
}

function squareAuditNormalizeStatus(string $status): string
{
    return match ($status) {
        'ok', 'success', 'completed', 'logged' => 'success',
        'error', 'failure', 'failed', 'dead_letter' => 'failure',
        default => 'skipped',
    };
}
