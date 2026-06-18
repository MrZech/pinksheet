<?php
/**
 * save_ebay_layout.php — Persist image positions for the eBay Listing Image Composer
 *
 * Accepts a SKU and a JSON-encoded array of positions, then updates the
 * pos_x / pos_y columns in ebay_listing_images for the given SKU.
 *
 * POST params:
 *   sku        — normalized SKU string
 *   positions  — JSON string: [{"id": N, "x": 100.0, "y": 200.0}, ...]
 *   csrf_token — CSRF token
 *
 * JSON response:
 *   { "status": "ok", "updated": 5 }
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance(true);
ensureStorageWritable();

header('Content-Type: application/json; charset=utf-8');
require_csrf();

const LAYOUT_DB = __DIR__ . '/data/intake.sqlite';

/* ── Validate ─────────────────────────────────────────────────── */
$sku = normalizeSku((string)($_POST['sku'] ?? ''));
if ($sku === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'SKU is required.']);
    exit;
}

$positionsRaw = (string)($_POST['positions'] ?? '');
if ($positionsRaw === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'positions JSON is required.']);
    exit;
}

$positions = json_decode($positionsRaw, true);
if (!is_array($positions)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'positions must be a valid JSON array.']);
    exit;
}

/* ── Update DB ────────────────────────────────────────────────── */
try {
    $pdo = new PDO('sqlite:' . LAYOUT_DB, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // Ensure table exists (migration for endpoints that may not have it yet)
    $pdo->exec("CREATE TABLE IF NOT EXISTS ebay_listing_images (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sku_normalized TEXT NOT NULL,
        original_name TEXT NOT NULL,
        stored_name TEXT NOT NULL,
        mime_type TEXT NOT NULL,
        file_size INTEGER NOT NULL DEFAULT 0,
        pos_x REAL NOT NULL DEFAULT 0,
        pos_y REAL NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("UPDATE ebay_listing_images SET pos_x = :x, pos_y = :y WHERE id = :id AND sku_normalized = :sku");
    $updated = 0;

    foreach ($positions as $pos) {
        $id = (int)($pos['id'] ?? 0);
        $x  = (float)($pos['x'] ?? 0);
        $y  = (float)($pos['y'] ?? 0);
        if ($id <= 0) continue; // skip placeholder IDs (negative)

        $stmt->execute(['x' => $x, 'y' => $y, 'id' => $id, 'sku' => $sku]);
        if ($stmt->rowCount() > 0) {
            $updated++;
        }
    }

    $pdo->commit();
    echo json_encode(['status' => 'ok', 'updated' => $updated]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error.']);
}
