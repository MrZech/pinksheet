<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance(true);
ensureStorageWritable();

const CP_DB_PATH = __DIR__ . '/data/intake.sqlite';
const CP_MAX_RESULTS = 50;

if (!is_readable(CP_DB_PATH)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['results' => []]);
    exit;
}

try {
    $pdo = pdoConnect(CP_DB_PATH);

    // Ensure indexes exist for searchable fields
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cp_brand_model ON intake_items(brand_model)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cp_serial_number ON intake_items(serial_number)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cp_notes ON intake_items(notes)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cp_where_it_goes ON intake_items(where_it_goes)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cp_cpu ON intake_items(cpu)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cp_ram ON intake_items(ram)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cp_ssd_gb ON intake_items(ssd_gb)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cp_graphics_card ON intake_items(graphics_card)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cp_os ON intake_items(os)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cp_source ON intake_items(source)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cp_battery_health ON intake_items(battery_health)');

    $q = trim((string)($_GET['q'] ?? ''));
    $recent = ($_GET['recent'] ?? '') === '1';

    $fields = 'id, sku, sku_normalized, status, what_is_it, brand_model, serial_number, notes, where_it_goes, updated_at, cpu, ram, ssd_gb, graphics_card, os, battery_health, source';

    if ($recent) {
        $stmt = $pdo->query("
            SELECT $fields
            FROM intake_items
            WHERE sku IS NOT NULL AND TRIM(sku) <> ''
            ORDER BY updated_at DESC, id DESC
            LIMIT 20
        ");
        $rows = $stmt->fetchAll();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['results' => $rows]);
        exit;
    }

    if ($q === '') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['results' => []]);
        exit;
    }

    if (mb_strlen($q) > 200) {
        $q = mb_substr($q, 0, 200);
    }

    $likeQ = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $prefixQ = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $normQ = strtoupper(trim($q));
    $likeNorm = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $normQ) . '%';

    // Strategy: search across all indexed text fields using LIKE,
    // then rank by relevance on the application side.
    $sql = "
        SELECT $fields
        FROM intake_items
        WHERE sku IS NOT NULL AND TRIM(sku) <> ''
          AND (
                 sku LIKE :like_q ESCAPE '\\'
              OR sku_normalized LIKE :like_norm ESCAPE '\\'
              OR serial_number LIKE :like_q ESCAPE '\\'
              OR brand_model LIKE :like_q ESCAPE '\\'
              OR what_is_it LIKE :like_q ESCAPE '\\'
              OR notes LIKE :like_q ESCAPE '\\'
              OR where_it_goes LIKE :like_q ESCAPE '\\'
              OR status LIKE :like_q ESCAPE '\\'
              OR cpu LIKE :like_q ESCAPE '\\'
              OR ram LIKE :like_q ESCAPE '\\'
              OR ssd_gb LIKE :like_q ESCAPE '\\'
              OR graphics_card LIKE :like_q ESCAPE '\\'
              OR os LIKE :like_q ESCAPE '\\'
              OR battery_health LIKE :like_q ESCAPE '\\'
              OR source LIKE :like_q ESCAPE '\\'
          )
        ORDER BY
          CASE
            WHEN sku = :exact_q THEN 1
            WHEN serial_number = :exact_q THEN 2
            WHEN sku LIKE :prefix_q ESCAPE '\\' THEN 3
            WHEN serial_number LIKE :prefix_q ESCAPE '\\' THEN 4
            WHEN brand_model LIKE :prefix_q ESCAPE '\\' THEN 5
            ELSE 6
          END,
          updated_at DESC, id DESC
        LIMIT " . CP_MAX_RESULTS;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':like_q' => $likeQ,
        ':like_norm' => $likeNorm,
        ':exact_q' => $q,
        ':prefix_q' => $prefixQ,
    ]);
    $rows = $stmt->fetchAll();

    // Rank: apply relevance scoring
    $exactNorm = strtoupper(trim($q));
    foreach ($rows as &$row) {
        $row['_score'] = 0;
        $sku = trim((string)($row['sku'] ?? ''));
        $serial = trim((string)($row['serial_number'] ?? ''));
        $skuNorm = strtoupper($sku);
        $serialNorm = strtoupper($serial);
        $model = trim((string)($row['brand_model'] ?? ''));

        if (strcasecmp($sku, $q) === 0 || strcasecmp($serial, $q) === 0) {
            $row['_score'] = 1000;
        } elseif ($skuNorm === $exactNorm || $serialNorm === $exactNorm) {
            $row['_score'] = 900;
        } elseif (strpos($skuNorm, $exactNorm) === 0 || strpos($serialNorm, $exactNorm) === 0) {
            $row['_score'] = 700;
        } elseif (stripos($model, $q) === 0) {
            $row['_score'] = 500;
        } elseif (stripos($model, $q) !== false) {
            $row['_score'] = 400;
        } elseif (stripos(trim((string)($row['what_is_it'] ?? '')), $q) !== false) {
            $row['_score'] = 300;
        } else {
            $row['_score'] = 100;
        }
    }
    unset($row);

    usort($rows, static fn($a, $b) => $b['_score'] <=> $a['_score'] ?: ($b['updated_at'] ?? '') <=> ($a['updated_at'] ?? ''));

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['results' => $rows]);
    exit;
} catch (Throwable $error) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['results' => [], 'error' => 'Search failed']);
    exit;
}
