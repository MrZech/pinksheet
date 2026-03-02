<?php
declare(strict_types=1);

const DB_PATH = __DIR__ . '/data/intake.sqlite';

header('Content-Type: application/json; charset=utf-8');

$sku = trim((string)($_GET['sku'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
if ($sku === '' && $status === '') {
    echo '[]';
    exit;
}

if (!is_readable(DB_PATH)) {
    echo '[]';
    exit;
}

try {
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $conditions = [];
    $params = [];
    if ($sku !== '') {
        $escaped = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $sku) . '%';
        $matchClause = "(sku IS NOT NULL AND sku <> '' AND sku LIKE :sku ESCAPE '\\')"
            . " OR (what_is_it IS NOT NULL AND what_is_it <> '' AND what_is_it LIKE :sku ESCAPE '\\')";
        $conditions[] = '(' . $matchClause . ')';
        $params['sku'] = $escaped;
    }
    if ($status !== '') {
        $conditions[] = 'status = :status';
        $params['status'] = $status;
    }
    $sql = 'SELECT sku, status, what_is_it, updated_at FROM intake_items';
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY updated_at DESC, id DESC LIMIT 7';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = array_map(static fn (array $row): array => [
        'sku' => trim((string)($row['sku'] ?? '')),
        'status' => trim((string)($row['status'] ?? '')),
        'what_is_it' => trim((string)($row['what_is_it'] ?? '')),
        'updated_at' => trim((string)($row['updated_at'] ?? '')),
    ], $stmt->fetchAll());
    echo json_encode($results, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    echo '[]';
}
