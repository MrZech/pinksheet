<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance(true);
ensureStorageWritable();

const DB_PATH = __DIR__ . '/data/intake.sqlite';

// Provide a lightweight JSON API that surfaces recent SKU/description matches for lookup autocomplete.

try {
    $term = trim((string)($_GET['q'] ?? ''));
} catch (Throwable $error) {
    $term = '';
}
if ($term === '') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([]);
    exit;
}
if (function_exists('mb_strlen') && function_exists('mb_substr')) {
    if (mb_strlen($term) > MAX_QUERY_LENGTH) {
        $term = mb_substr($term, 0, MAX_QUERY_LENGTH);
    }
} elseif (strlen($term) > MAX_QUERY_LENGTH) {
    $term = substr($term, 0, MAX_QUERY_LENGTH);
}

if (!is_readable(DB_PATH)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([]);
    exit;
}

try {
    $pdo = pdoConnect(DB_PATH);
    $normalizedTerm = strtoupper(trim($term));
    $prefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
    $normalizedPrefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $normalizedTerm) . '%';
    $any = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
    $sql = <<<SQL
        SELECT sku, what_is_it
        FROM intake_items
        WHERE (sku IS NOT NULL AND sku <> '' AND sku LIKE :prefix ESCAPE '\\')
          OR (sku_normalized IS NOT NULL AND sku_normalized <> '' AND sku_normalized LIKE :normalized_prefix ESCAPE '\\')
          OR (what_is_it IS NOT NULL AND what_is_it <> '' AND what_is_it LIKE :any ESCAPE '\\')
        ORDER BY updated_at DESC, id DESC
        LIMIT {SUGGESTION_LIMIT}
    SQL;
    $stmt = $pdo->prepare(str_replace('{SUGGESTION_LIMIT}', (string)SUGGESTION_LIMIT, $sql));
    $stmt->execute([
        'prefix' => $prefix,
        'normalized_prefix' => $normalizedPrefix,
        'any' => $any,
    ]);
    $suggestions = [];
    $seen = [];
    foreach ($stmt->fetchAll() as $row) {
        $sku = trim((string)($row['sku'] ?? ''));
        if ($sku === '' || isset($seen[$sku])) {
            continue;
        }
        $seen[$sku] = true;
        $labelParts = [$sku];
        $whatIsIt = trim((string)($row['what_is_it'] ?? ''));
        if ($whatIsIt !== '') {
            $labelParts[] = $whatIsIt;
        }
        $suggestions[] = [
            'value' => $sku,
            'label' => implode(' — ', $labelParts),
        ];
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($suggestions);
    exit;
} catch (Throwable $error) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([]);
    exit;
}
