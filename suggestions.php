<?php
declare(strict_types=1);

const DB_PATH = __DIR__ . '/data/intake.sqlite';

// Provide a lightweight JSON API that surfaces recent SKU/description matches for lookup autocomplete.

header('Content-Type: application/json; charset=utf-8');

$term = trim((string)($_GET['q'] ?? ''));
if ($term === '') {
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
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
    $stmt = $pdo->prepare(<<<'SQL'
        SELECT sku, what_is_it
        FROM intake_items
        WHERE (sku IS NOT NULL AND sku <> '' AND sku LIKE :term ESCAPE '\\')
          OR (what_is_it IS NOT NULL AND what_is_it <> '' AND what_is_it LIKE :term ESCAPE '\\')
        ORDER BY updated_at DESC, id DESC
        LIMIT 40
    SQL);
    $stmt->execute(['term' => $like]);
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
    echo json_encode($suggestions, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    echo '[]';
}
