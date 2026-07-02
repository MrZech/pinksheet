<?php
/**
 * AJAX endpoint — returns kanban cards for one or all lanes as JSON.
 * Called by kanban.php after the page shell is visible.
 *
 * GET params:
 *   lane  (optional) — URL-encoded lane name; omit to return all lanes
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance(true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$validLanes = ['Intake', 'Tested', 'Ready for eBay Listing', 'Dispo Tech Store', 'eBay Listed', 'SOLD'];

try {
    $pdo = pdoConnect(__DIR__ . '/data/intake.sqlite');
    $pdo->exec('PRAGMA cache_size = -8000');
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db_connect']);
    exit;
}

// Ensure column exists (safe no-op if already present)
try {
    $pdo->exec("ALTER TABLE intake_items ADD COLUMN ready INTEGER NOT NULL DEFAULT 0");
} catch (Throwable $e) { /* already exists */ }

// Optionally filter to a single lane
$requestedLane = isset($_GET['lane']) ? trim((string)$_GET['lane']) : '';
if ($requestedLane !== '' && !in_array($requestedLane, $validLanes, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_lane']);
    exit;
}

// Build WHERE clause
$whereExtra = '';
$params     = [];
if ($requestedLane !== '') {
    $whereExtra = " AND status = :status";
    $params['status'] = $requestedLane;
}

// Fetch items — deduplicate by sku_normalized keeping refurb or most-recent
$items = $pdo->prepare("
    SELECT id, sku, sku_normalized, status, what_is_it, notes, updated_at, dispotech_price, reviewed, ready
    FROM intake_items
    WHERE sku IS NOT NULL AND sku != ''
    $whereExtra
    ORDER BY updated_at DESC, id DESC
    LIMIT 500
");
$items->execute($params);
$rows = $items->fetchAll(PDO::FETCH_ASSOC);

// Deduplicate (same logic as kanban.php)
$deduped = [];
foreach ($rows as $row) {
    $norm = strtoupper(trim((string)($row['sku_normalized'] ?? $row['sku'] ?? '')));
    if ($norm === '') continue;

    if (!isset($deduped[$norm])) {
        $deduped[$norm] = $row;
        continue;
    }
    $curRefurb = str_contains(strtolower($deduped[$norm]['what_is_it'] . ' ' . $deduped[$norm]['notes']), 'refurb');
    $newRefurb = str_contains(strtolower($row['what_is_it'] . ' ' . $row['notes']), 'refurb');
    if ($newRefurb && !$curRefurb) {
        $deduped[$norm] = $row;
        continue;
    }
    if ($newRefurb === $curRefurb) {
        if ($row['updated_at'] > $deduped[$norm]['updated_at'] ||
            ($row['updated_at'] === $deduped[$norm]['updated_at'] && (int)$row['id'] > (int)$deduped[$norm]['id'])) {
            $deduped[$norm] = $row;
        }
    }
}
$rows = array_values($deduped);

// Fetch thumbnails in one batch
$thumbs = [];
$skus   = array_values(array_filter(array_map(
    static fn($r) => strtoupper(trim((string)($r['sku'] ?? ''))),
    $rows
)));
if ($skus) {
    foreach (array_chunk($skus, 900) as $chunk) {
        $ph   = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare("
            SELECT sku_normalized, id
            FROM sku_photos
            WHERE sku_normalized IN ($ph)
            ORDER BY is_thumb DESC, id DESC
        ");
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $n = trim((string)$p['sku_normalized']);
            if ($n && !isset($thumbs[$n])) {
                $thumbs[$n] = (int)$p['id'];
            }
        }
    }
}

// Build response grouped by lane
$result = [];
foreach ($validLanes as $lane) {
    $result[$lane] = [];
}

// Detect base URL for QR links
$isHttps  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (isset($_SERVER['HTTP_CF_VISITOR']) && str_contains($_SERVER['HTTP_CF_VISITOR'], '"scheme":"https"'));
$protocol = $isHttps ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';

foreach ($rows as $row) {
    $status = $row['status'] ?? '';
    if (!in_array($status, $validLanes, true)) {
        $status = 'Intake';
    }
    $sku  = trim((string)($row['sku'] ?? ''));
    $norm = strtoupper($sku);
    $result[$status][] = [
        'id'       => (int)($row['id'] ?? 0),
        'sku'      => $sku,
        'norm'     => $norm,
        'what'     => (string)($row['what_is_it'] ?? ''),
        'updated'  => (string)($row['updated_at'] ?? ''),
        'price'    => isset($row['dispotech_price']) && $row['dispotech_price'] !== '' ? (float)$row['dispotech_price'] : null,
        'reviewed' => !empty($row['reviewed']),
        'ready'    => !empty($row['ready']),
        'thumb_id' => $thumbs[$norm] ?? null,
        'qr_url'   => $protocol . '://' . $host . '/intake.php?sku=' . urlencode($norm),
    ];
}

// If single lane requested, only return that lane
if ($requestedLane !== '') {
    echo json_encode(['lane' => $requestedLane, 'cards' => $result[$requestedLane]], JSON_UNESCAPED_SLASHES);
} else {
    echo json_encode(['lanes' => $result], JSON_UNESCAPED_SLASHES);
}
