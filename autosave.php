<?php
/**
 * Autosave endpoint — upsert pattern.
 *
 * Accepts a JSON payload { sku, data: { ... } } and performs
 * an upsert against the intake_drafts table, keyed on
 * sku_normalized.  Always writes the SKU from the request;
 * never silently discards incoming data.
 *
 * GET  ?sku=FOO  — retrieve a saved draft for the given SKU.
 * POST           — save/overwrite draft for the given SKU.
 */

require_once __DIR__ . '/config.php';
checkMaintenance();
ensureStorageWritable();

const DB_DIR  = __DIR__ . '/data';
const DB_PATH = __DIR__ . '/data/intake.sqlite';

if (!is_dir(DB_DIR)) {
    mkdir(DB_DIR, 0777, true);
}

$pdo = new PDO('sqlite:' . DB_PATH, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS intake_drafts (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    sku_normalized  TEXT NOT NULL,
    payload         TEXT NOT NULL,
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
SQL);
$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_intake_drafts_sku ON intake_drafts (sku_normalized)");

function normalizeSku(string $sku): string
{
    return strtoupper(trim($sku));
}

/** Send a JSON response and exit. */
function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo function_exists('json_encode') ? json_encode($data) : '{"error":"json extension not available"}';
    exit;
}

/** Validate and sanitize incoming payload against expected field types. */
function sanitizePayload(array $raw): array
{
    $allowed = [
        'sku', 'status', 'what_is_it', 'date_received', 'source',
        'functional', 'condition', 'is_square', 'care_if_square',
        'cords_adapters', 'keep_items_together', 'picture_taken',
        'power_on', 'brand_model', 'ram', 'ssd_gb', 'cpu', 'os',
        'battery_health', 'graphics_card', 'screen_resolution',
        'diagnostics_test_ran', 'wifi_card_installed', 'compatible_os',
        'where_it_goes', 'ebay_status', 'ebay_price', 'dispotech_price',
        'in_ebay_room', 'what_box', 'notes',
        'prompt_sku', 'prompt_output', 'chatgpt_output', 'final_output',
    ];
    $clean = [];
    foreach ($raw as $key => $value) {
        if (!in_array($key, $allowed, true)) {
            continue;
        }
        if (is_string($value)) {
            $clean[$key] = trim($value);
        } elseif (is_bool($value)) {
            $clean[$key] = $value;
        } elseif (is_numeric($value)) {
            $clean[$key] = $value;
        }
    }
    return $clean;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ──── GET: return existing draft (if any) ──── */
if ($method === 'GET') {
    $sku = normalizeSku((string)($_GET['sku'] ?? ''));
    if ($sku === '') {
        jsonResponse(['status' => 'ok', 'has_draft' => false]);
    }
    $stmt = $pdo->prepare('SELECT payload, updated_at FROM intake_drafts WHERE sku_normalized = :sku LIMIT 1');
    $stmt->execute(['sku' => $sku]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        jsonResponse(['status' => 'ok', 'has_draft' => false]);
    }
    $payload = json_decode((string)$row['payload'], true);
    if (!is_array($payload)) {
        jsonResponse(['status' => 'ok', 'has_draft' => false]);
    }
    jsonResponse([
        'status'     => 'ok',
        'has_draft'  => true,
        'updated_at' => (string)$row['updated_at'],
        'data'       => $payload,
    ]);
}

/* ──── POST: upsert draft ──── */
$raw   = file_get_contents('php://input');
$input = json_decode($raw ?: '{}', true);

if (!is_array($input)) {
    jsonResponse(['status' => 'error', 'message' => 'Invalid JSON payload'], 400);
}

$sku     = normalizeSku((string)($input['sku'] ?? ''));
$payload = $input['data'] ?? null;

if ($sku === '') {
    jsonResponse(['status' => 'error', 'message' => 'SKU is required'], 400);
}
if (!is_array($payload)) {
    jsonResponse(['status' => 'error', 'message' => 'Missing or invalid data payload'], 400);
}

/* Sanitise the incoming field data */
$sanitised = sanitizePayload($payload);
$sanitised['sku_normalized'] = $sku;

$payloadJson = json_encode($sanitised);
if ($payloadJson === false) {
    jsonResponse(['status' => 'error', 'message' => 'Could not encode payload'], 400);
}
if (strlen($payloadJson) > 65536) {
    jsonResponse(['status' => 'error', 'message' => 'Payload too large'], 400);
}

$now = (new DateTime('now'))->format('c');

/*
 * Upsert: INSERT a new row, or UPDATE the existing one
 * when a conflict on the unique sku_normalized index occurs.
 * SQLite 3.24+ supports ON CONFLICT … DO UPDATE SET.
 */
$stmt = $pdo->prepare(<<<'SQL'
INSERT INTO intake_drafts (sku_normalized, payload, updated_at)
VALUES (:sku, :payload, :updated_at)
ON CONFLICT(sku_normalized) DO UPDATE SET
    payload    = :payload2,
    updated_at = :updated_at2
SQL);
$stmt->execute([
    'sku'         => $sku,
    'payload'     => $payloadJson,
    'updated_at'  => $now,
    'payload2'    => $payloadJson,
    'updated_at2' => $now,
]);

jsonResponse(['status' => 'ok', 'saved_at' => $now]);
