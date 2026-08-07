<?php
declare(strict_types=1);

require_once __DIR__ . '/../../square_sync.php';

const RECON_TABLE_RUNS = <<<'SQL'
CREATE TABLE IF NOT EXISTS reconciliation_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    trigger_type TEXT NOT NULL CHECK (trigger_type IN ('manual', 'scheduled', 'ondemand')),
    started_at TEXT,
    completed_at TEXT,
    status TEXT NOT NULL DEFAULT 'running' CHECK (status IN ('running', 'completed', 'failed', 'cancelled')),
    total_devices_checked INTEGER DEFAULT 0,
    issues_detected INTEGER DEFAULT 0,
    issues_repaired INTEGER DEFAULT 0,
    manual_actions_required INTEGER DEFAULT 0,
    api_requests_made INTEGER DEFAULT 0,
    api_requests_failed INTEGER DEFAULT 0,
    runtime_seconds REAL DEFAULT 0,
    error_message TEXT,
    summary TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
SQL;

const RECON_TABLE_ISSUES = <<<'SQL'
CREATE TABLE IF NOT EXISTS reconciliation_issues (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id INTEGER NOT NULL,
    sku_normalized TEXT,
    issue_type TEXT NOT NULL,
    severity TEXT NOT NULL DEFAULT 'warning' CHECK (severity IN ('info', 'warning', 'critical')),
    description TEXT NOT NULL,
    pinksheet_value TEXT,
    square_value TEXT,
    auto_repairable INTEGER NOT NULL DEFAULT 0,
    repair_action TEXT,
    repair_status TEXT DEFAULT 'pending' CHECK (repair_status IN ('pending', 'auto_repaired', 'skipped', 'failed', 'manual_required')),
    repair_result TEXT,
    repaired_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (run_id) REFERENCES reconciliation_runs(id) ON DELETE CASCADE
);
SQL;

const RECON_TABLE_ALERTS = <<<'SQL'
CREATE TABLE IF NOT EXISTS reconciliation_alerts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    alert_type TEXT NOT NULL,
    severity TEXT NOT NULL DEFAULT 'warning' CHECK (severity IN ('info', 'warning', 'critical')),
    title TEXT NOT NULL,
    description TEXT,
    sku_normalized TEXT,
    dismissed INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    resolved_at TEXT
);
SQL;

const RECON_TABLE_REPORTS = <<<'SQL'
CREATE TABLE IF NOT EXISTS reconciliation_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id INTEGER NOT NULL UNIQUE,
    trigger_type TEXT NOT NULL,
    started_at TEXT,
    completed_at TEXT,
    runtime_seconds REAL DEFAULT 0,
    total_devices_checked INTEGER DEFAULT 0,
    issues_detected INTEGER DEFAULT 0,
    issues_repaired INTEGER DEFAULT 0,
    manual_actions_required INTEGER DEFAULT 0,
    api_requests_made INTEGER DEFAULT 0,
    api_requests_failed INTEGER DEFAULT 0,
    breakdown_json TEXT,
    error_summary TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (run_id) REFERENCES reconciliation_runs(id) ON DELETE CASCADE
);
SQL;

function reconEnsureSchema(PDO $pdo): void
{
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec(RECON_TABLE_RUNS);
    $pdo->exec(RECON_TABLE_ISSUES);
    $pdo->exec(RECON_TABLE_ALERTS);
    $pdo->exec(RECON_TABLE_REPORTS);

    $cols = $pdo->query("PRAGMA table_info(square_catalog_sync)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('last_recon_sync_at', $cols, true)) {
        try { $pdo->exec("ALTER TABLE square_catalog_sync ADD COLUMN last_recon_sync_at TEXT"); } catch (Throwable $e) {}
    }
}

function reconCreateRun(PDO $pdo, string $triggerType): int
{
    reconEnsureSchema($pdo);
    $stmt = $pdo->prepare("INSERT INTO reconciliation_runs (trigger_type, started_at, status) VALUES (:type, datetime('now'), 'running')");
    $stmt->execute(['type' => $triggerType]);
    return (int)$pdo->lastInsertId();
}

function reconUpdateRun(PDO $pdo, int $runId, array $data): void
{
    $set = [];
    $params = ['id' => $runId];
    foreach ($data as $col => $val) {
        $set[] = "$col = :$col";
        $params[$col] = $val;
    }
    if (empty($set)) return;
    $pdo->prepare('UPDATE reconciliation_runs SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
}

function reconAddIssue(PDO $pdo, int $runId, array $issue): void
{
    $stmt = $pdo->prepare(<<<'SQL'
INSERT INTO reconciliation_issues (run_id, sku_normalized, issue_type, severity, description, pinksheet_value, square_value, auto_repairable, repair_action)
VALUES (:run_id, :sku, :type, :severity, :desc, :ps_val, :sq_val, :repairable, :action)
SQL);
    $stmt->execute([
        'run_id' => $runId,
        'sku' => $issue['sku_normalized'] ?? null,
        'type' => $issue['issue_type'] ?? 'unknown',
        'severity' => $issue['severity'] ?? 'warning',
        'desc' => $issue['description'] ?? '',
        'ps_val' => $issue['pinksheet_value'] ?? null,
        'sq_val' => $issue['square_value'] ?? null,
        'repairable' => $issue['auto_repairable'] ?? 0 ? 1 : 0,
        'action' => $issue['repair_action'] ?? null,
    ]);
}

function reconUpdateIssue(PDO $pdo, int $issueId, array $data): void
{
    $set = [];
    $params = ['id' => $issueId];
    foreach ($data as $col => $val) {
        $set[] = "$col = :$col";
        $params[$col] = $val;
    }
    if (empty($set)) return;
    $pdo->prepare('UPDATE reconciliation_issues SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
}

function reconAddAlert(PDO $pdo, string $alertType, string $severity, string $title, ?string $description = null, ?string $skuNormalized = null): void
{
    $pdo->prepare(<<<'SQL'
INSERT INTO reconciliation_alerts (alert_type, severity, title, description, sku_normalized)
VALUES (:type, :severity, :title, :desc, :sku)
SQL)->execute([
        'type' => $alertType,
        'severity' => $severity,
        'title' => $title,
        'desc' => $description,
        'sku' => $skuNormalized,
    ]);
}

function reconDismissAlert(PDO $pdo, int $alertId): void
{
    $pdo->prepare("UPDATE reconciliation_alerts SET dismissed = 1, resolved_at = datetime('now') WHERE id = :id")->execute(['id' => $alertId]);
}

function reconActiveAlerts(PDO $pdo, ?string $severity = null): array
{
    $sql = "SELECT * FROM reconciliation_alerts WHERE dismissed = 0";
    $params = [];
    if ($severity !== null) {
        $sql .= " AND severity = :severity";
        $params['severity'] = $severity;
    }
    $sql .= " ORDER BY CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END, created_at DESC LIMIT 50";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
