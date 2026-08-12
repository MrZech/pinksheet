<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance(true);
ensureStorageWritable();

const DB_PATH = __DIR__ . '/data/intake.sqlite';



function safeStringLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function safeStringSubstring(string $value, int $start, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, $start, $length) : substr($value, $start, $length);
}

$sku = trim((string)($_GET['sku'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$limit = (int)($_GET['limit'] ?? PREVIEW_LIMIT);
$withPhotos = isset($_GET['with_photos']);
if ($limit < 1) { $limit = PREVIEW_LIMIT; }
if ($limit > 100) { $limit = 100; }
if (safeStringLength($sku) > MAX_QUERY_LENGTH) {
    $sku = safeStringSubstring($sku, 0, MAX_QUERY_LENGTH);
}
if (safeStringLength($status) > MAX_STATUS_LENGTH) {
    $status = safeStringSubstring($status, 0, MAX_STATUS_LENGTH);
}
$minPrice = $_GET['min_price'] ?? '';
$maxPrice = $_GET['max_price'] ?? '';
$sort = $_GET['sort'] ?? 'updated_at';
if ($sort !== 'price_asc' && $sort !== 'price_desc') {
    $sort = 'updated_at';
}
if ($sku === '' && $status === '' && $minPrice === '' && $maxPrice === '') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([]);
    exit;
}

if (!is_readable(DB_PATH)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([]);
    exit;
}

try {
    $pdo = pdoConnect(DB_PATH);
    $conditions = [];
    $params = [];
    if ($sku !== '') {
        $normalizedQuery = strtoupper(trim($sku));
        $skuPrefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $sku) . '%';
        $normalizedPrefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $normalizedQuery) . '%';
        $skuAny = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $sku) . '%';
        $matchClause = "(sku IS NOT NULL AND sku <> '' AND sku LIKE :sku_prefix ESCAPE '\\')"
            . " OR (sku_normalized IS NOT NULL AND sku_normalized <> '' AND sku_normalized LIKE :sku_normalized_prefix ESCAPE '\\')"
            . " OR (what_is_it IS NOT NULL AND what_is_it <> '' AND what_is_it LIKE :sku_any ESCAPE '\\')";
        $conditions[] = '(' . $matchClause . ')';
        $params['sku_prefix'] = $skuPrefix;
        $params['sku_normalized_prefix'] = $normalizedPrefix;
        $params['sku_any'] = $skuAny;
    }
    if ($status !== '') {
        $conditions[] = 'status = :status';
        $params['status'] = $status;
    }
    $effectivePrice = 'COALESCE(dispotech_price, ebay_price, 0)';
    if ($minPrice !== '' || $maxPrice !== '') {
        $priceConds = [];
        if ($minPrice !== '') {
            // CAST(... AS REAL): PDO binds execute() params as TEXT, and SQLite
            // then compares prices lexicographically ('123' < '50'), which
            // silently breaks every price filter. Force numeric comparison.
            $priceConds[] = "$effectivePrice >= CAST(:min_price AS REAL)";
            $params['min_price'] = (float)$minPrice;
        }
        if ($maxPrice !== '') {
            $priceConds[] = "$effectivePrice <= CAST(:max_price AS REAL)";
            $params['max_price'] = (float)$maxPrice;
        }
        $conditions[] = '(' . implode(' AND ', $priceConds) . ')';
    }
$sql = 'SELECT id, sku, status, what_is_it, updated_at, dispotech_price, ebay_price FROM intake_items';
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    if ($sort === 'price_asc') {
        $sql .= " ORDER BY $effectivePrice ASC, id DESC";
    } elseif ($sort === 'price_desc') {
        $sql .= " ORDER BY $effectivePrice DESC, id DESC";
    } else {
        $sql .= ' ORDER BY updated_at DESC, id DESC';
    }
    $sql .= ' LIMIT ' . (int)$limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Optional thumbnail: pick most recent photo per normalized SKU, preferring explicit thumbnail flags.
    $thumbs = [];
    $photoCounts = [];
    $skus = array_values(array_filter(array_map(static fn($r) => trim((string)($r['sku'] ?? '')), $rows)));
    if ($skus) {
        $norms = array_values(array_map(static fn($s) => strtoupper(trim($s)), $skus));
        $placeholders = implode(',', array_fill(0, count($norms), '?'));
        $photoStmt = $pdo->prepare("
            SELECT sku_normalized, id, is_thumb
            FROM sku_photos
            WHERE sku_normalized IN ($placeholders)
            ORDER BY is_thumb DESC, id DESC
        ");
        $photoStmt->execute($norms);
        foreach ($photoStmt->fetchAll() as $p) {
            $norm = trim((string)$p['sku_normalized']);
            if ($norm && !isset($thumbs[$norm])) {
                $thumbs[$norm] = (int)$p['id'];
            }
        }
        if ($withPhotos) {
            $countStmt = $pdo->prepare("
                SELECT sku_normalized, COUNT(*) AS c
                FROM sku_photos
                WHERE sku_normalized IN ($placeholders)
                GROUP BY sku_normalized
            ");
            $countStmt->execute($norms);
            foreach ($countStmt->fetchAll() as $row) {
                $n = trim((string)$row['sku_normalized']);
                if ($n !== '') {
                    $photoCounts[$n] = (int)$row['c'];
                }
            }
        }
    }

    $results = array_map(static function (array $row) use ($thumbs, $photoCounts): array {
        $sku = trim((string)($row['sku'] ?? ''));
        $norm = strtoupper(trim($sku));
        $photoId = $thumbs[$norm] ?? null;
        $photoUrl = $photoId ? ('photo.php?id=' . $photoId) : null;
        $dPrice = $row['dispotech_price'] ?? null;
        $ePrice = $row['ebay_price'] ?? null;
        $missingPrice = ($dPrice === null || $dPrice === '') && ($ePrice === null || $ePrice === '');
        return [
            'id' => isset($row['id']) ? (int)$row['id'] : null,
            'sku' => $sku,
            'status' => trim((string)($row['status'] ?? '')),
            'what_is_it' => trim((string)($row['what_is_it'] ?? '')),
            'updated_at' => trim((string)($row['updated_at'] ?? '')),
            'photo_id' => $photoId,
            'photo_url' => $photoUrl,
            'photo_count' => $photoCounts[$norm] ?? 0,
            'missing_price' => $missingPrice,
            'dispotech_price' => $dPrice,
            'ebay_price' => $ePrice,
        ];
    }, $rows);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($results);
    exit;
} catch (Throwable $error) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([]);
    exit;
}
