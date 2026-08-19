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

try {
    $pdo = pdoConnect(DB_PATH);
} catch (Throwable $e) {
    errorResponse('Database connection failed', 500);
}

try {
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS intake_drafts (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    sku_normalized  TEXT NOT NULL,
    payload         TEXT NOT NULL,
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
SQL);
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_intake_drafts_sku ON intake_drafts (sku_normalized)");
} catch (Throwable $e) {
    errorResponse('Schema initialization failed', 500);
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
        'ebay_category', 'ebay_category_path', 'ebay_category_id',
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

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    /* ──── GET: return existing draft (if any) ──── */
    if ($method === 'GET') {
        $sku = normalizeSku((string)($_GET['sku'] ?? ''));
        if ($sku === '') {
            successResponse(['has_draft' => false]);
        }
        $stmt = $pdo->prepare('SELECT payload, updated_at FROM intake_drafts WHERE sku_normalized = :sku LIMIT 1');
        $stmt->execute(['sku' => $sku]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            successResponse(['has_draft' => false]);
        }
        $payload = json_decode((string)$row['payload'], true);
        if (!is_array($payload)) {
            successResponse(['has_draft' => false]);
        }
        successResponse([
            'has_draft'  => true,
            'updated_at' => (string)$row['updated_at'],
            'data'       => $payload,
        ]);
    }

    /* ──── POST: upsert draft ──── */
    require_csrf();
    $raw   = file_get_contents('php://input');
    $input = json_decode($raw ?: '{}', true);

    if (!is_array($input)) {
        errorResponse('Invalid JSON payload');
    }

    $sku     = normalizeSku((string)($input['sku'] ?? ''));
    $payload = $input['data'] ?? null;

    if ($sku === '') {
        errorResponse('SKU is required');
    }
    if (!is_array($payload)) {
        errorResponse('Missing or invalid data payload');
    }

    /* Sanitise the incoming field data */
    $sanitised = sanitizePayload($payload);
    $sanitised['sku_normalized'] = $sku;

    $payloadJson = json_encode($sanitised);
    if ($payloadJson === false) {
        errorResponse('Could not encode payload');
    }
    if (strlen($payloadJson) > 65536) {
        errorResponse('Payload too large');
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

    successResponse(['saved_at' => $now]);
} catch (Throwable $e) {
    errorResponse('Internal server error', 500);
}
