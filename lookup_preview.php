<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance(true);
ensureStorageWritable();

const DB_PATH = __DIR__ . '/data/intake.sqlite';

header('Content-Type: application/json; charset=utf-8');

$sku = trim((string)($_GET['sku'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$limit = (int)($_GET['limit'] ?? PREVIEW_LIMIT);
if ($limit < 1) { $limit = PREVIEW_LIMIT; }
if ($limit > 100) { $limit = 100; }
if (mb_strlen($sku) > MAX_QUERY_LENGTH) {
    $sku = mb_substr($sku, 0, MAX_QUERY_LENGTH);
}
if (mb_strlen($status) > MAX_STATUS_LENGTH) {
    $status = mb_substr($status, 0, MAX_STATUS_LENGTH);
}
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
    $sql .= ' ORDER BY updated_at DESC, id DESC LIMIT ' . (int)$limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Optional thumbnail: pick most recent photo per normalized SKU.
    $thumbs = [];
    $skus = array_filter(array_map(static fn($r) => trim((string)($r['sku'] ?? '')), $rows));
    if ($skus) {
        $norms = array_map(static fn($s) => strtoupper(trim($s)), $skus);
        $placeholders = implode(',', array_fill(0, count($norms), '?'));
        $photoStmt = $pdo->prepare("
            SELECT sku_normalized, id
            FROM sku_photos
            WHERE sku_normalized IN ($placeholders)
            ORDER BY id DESC
        ");
        $photoStmt->execute($norms);
        foreach ($photoStmt->fetchAll() as $p) {
            $norm = trim((string)$p['sku_normalized']);
            if ($norm && !isset($thumbs[$norm])) {
                $thumbs[$norm] = (int)$p['id'];
            }
        }
    }

    $results = array_map(static function (array $row) use ($thumbs): array {
        $sku = trim((string)($row['sku'] ?? ''));
        $norm = strtoupper(trim($sku));
        $photoId = $thumbs[$norm] ?? null;
        $photoUrl = $photoId ? ('photo.php?id=' . $photoId) : null;
        return [
            'sku' => $sku,
            'status' => trim((string)($row['status'] ?? '')),
            'what_is_it' => trim((string)($row['what_is_it'] ?? '')),
            'updated_at' => trim((string)($row['updated_at'] ?? '')),
            'photo_id' => $photoId,
            'photo_url' => $photoUrl,
        ];
    }, $rows);
    echo json_encode($results, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    echo '[]';
}
