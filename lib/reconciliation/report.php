<?php
declare(strict_types=1);

require_once __DIR__ . '/schema.php';

function reconGenerateReport(PDO $pdo, int $runId): array
{
    $run = $pdo->prepare("SELECT * FROM reconciliation_runs WHERE id = :id");
    $run->execute(['id' => $runId]);
    $runData = $run->fetch(PDO::FETCH_ASSOC);
    if (!$runData) {
        return ['error' => 'Run not found'];
    }

    $issues = $pdo->prepare("SELECT * FROM reconciliation_issues WHERE run_id = :rid ORDER BY issue_type, severity");
    $issues->execute(['rid' => $runId]);
    $allIssues = $issues->fetchAll(PDO::FETCH_ASSOC);

    $breakdown = [];
    $byType = [];
    foreach ($allIssues as $issue) {
        $type = (string)($issue['issue_type'] ?? 'unknown');
        if (!isset($byType[$type])) {
            $byType[$type] = ['total' => 0, 'repaired' => 0, 'failed' => 0, 'pending' => 0];
        }
        $byType[$type]['total']++;
        $status = (string)($issue['repair_status'] ?? 'pending');
        if ($status === 'auto_repaired') $byType[$type]['repaired']++;
        elseif ($status === 'failed') $byType[$type]['failed']++;
        else $byType[$type]['pending']++;
    }
    arsort($byType);

    $errorSummary = [];
    foreach ($allIssues as $issue) {
        $status = (string)($issue['repair_status'] ?? '');
        if ($status === 'failed' || $status === 'manual_required') {
            $type = (string)($issue['issue_type'] ?? 'unknown');
            $errorSummary[] = [
                'type' => $type,
                'sku' => (string)($issue['sku_normalized'] ?? ''),
                'description' => (string)($issue['description'] ?? ''),
                'result' => (string)($issue['repair_result'] ?? ''),
            ];
        }
    }

    $reportData = [
        'run_id' => $runId,
        'trigger_type' => (string)($runData['trigger_type'] ?? ''),
        'started_at' => (string)($runData['started_at'] ?? ''),
        'completed_at' => (string)($runData['completed_at'] ?? ''),
        'runtime_seconds' => (float)($runData['runtime_seconds'] ?? 0),
        'total_devices_checked' => (int)($runData['total_devices_checked'] ?? 0),
        'issues_detected' => (int)($runData['issues_detected'] ?? 0),
        'issues_repaired' => (int)($runData['issues_repaired'] ?? 0),
        'manual_actions_required' => (int)($runData['manual_actions_required'] ?? 0),
        'api_requests_made' => (int)($runData['api_requests_made'] ?? 0),
        'api_requests_failed' => (int)($runData['api_requests_failed'] ?? 0),
    ];

    $insert = $pdo->prepare(<<<'SQL'
INSERT OR REPLACE INTO reconciliation_reports
    (run_id, trigger_type, started_at, completed_at, runtime_seconds,
     total_devices_checked, issues_detected, issues_repaired,
     manual_actions_required, api_requests_made, api_requests_failed,
     breakdown_json, error_summary)
VALUES (:run_id, :trigger_type, :started_at, :completed_at, :runtime_seconds,
        :total_devices_checked, :issues_detected, :issues_repaired,
        :manual_actions_required, :api_requests_made, :api_requests_failed,
        :breakdown_json, :error_summary)
SQL);
    $insert->execute([
        'run_id' => $runId,
        'trigger_type' => $reportData['trigger_type'],
        'started_at' => $reportData['started_at'],
        'completed_at' => $reportData['completed_at'],
        'runtime_seconds' => $reportData['runtime_seconds'],
        'total_devices_checked' => $reportData['total_devices_checked'],
        'issues_detected' => $reportData['issues_detected'],
        'issues_repaired' => $reportData['issues_repaired'],
        'manual_actions_required' => $reportData['manual_actions_required'],
        'api_requests_made' => $reportData['api_requests_made'],
        'api_requests_failed' => $reportData['api_requests_failed'],
        'breakdown_json' => json_encode($byType),
        'error_summary' => json_encode($errorSummary),
    ]);

    return $reportData;
}

function reconGetReport(PDO $pdo, int $runId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM reconciliation_reports WHERE run_id = :rid");
    $stmt->execute(['rid' => $runId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    $row['breakdown'] = json_decode((string)($row['breakdown_json'] ?? '{}'), true);
    $row['errors'] = json_decode((string)($row['error_summary'] ?? '[]'), true);
    return $row;
}

function reconGetLatestReport(PDO $pdo): ?array
{
    $stmt = $pdo->query("SELECT * FROM reconciliation_reports ORDER BY id DESC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['breakdown'] = json_decode((string)($row['breakdown_json'] ?? '{}'), true);
    $row['errors'] = json_decode((string)($row['error_summary'] ?? '[]'), true);
    return $row;
}

function reconGetRunHistory(PDO $pdo, int $limit = 20): array
{
    $stmt = $pdo->prepare("SELECT * FROM reconciliation_runs ORDER BY id DESC LIMIT :lim");
    $stmt->execute(['lim' => $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
