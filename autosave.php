<?php
require_once __DIR__ . '/config.php';
checkMaintenance();
ensureStorageWritable();

const DB_DIR = __DIR__ . '/data';
const DB_PATH = __DIR__ . '/data/intake.sqlite';

if (!is_dir(DB_DIR)) {
    mkdir(DB_DIR, 0777, true);
}

$pdo = new PDO('sqlite:' . DB_PATH, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS intake_drafts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sku_normalized TEXT NOT NULL,
    payload TEXT NOT NULL,
    version INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
SQL);
$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_intake_drafts_sku ON intake_drafts (sku_normalized)");

function normalizeSku(string $sku): string
{
    return strtoupper(trim($sku));
}

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $sku = normalizeSku((string)($_GET['sku'] ?? ''));
    if ($sku === '') {
        jsonResponse(['status' => 'ok', 'has_draft' => false]);
    }
    $stmt = $pdo->prepare('SELECT payload, version, updated_at FROM intake_drafts WHERE sku_normalized = :sku LIMIT 1');
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
        'status' => 'ok',
        'has_draft' => true,
        'version' => (int)$row['version'],
        'updated_at' => (string)$row['updated_at'],
        'data' => $payload,
    ]);
}

// POST autosave
$raw = file_get_contents('php://input');
$input = json_decode($raw ?: '{}', true);
if (!is_array($input)) {
    jsonResponse(['status' => 'error', 'message' => 'Invalid JSON'], 400);
}

$sku = normalizeSku((string)($input['sku'] ?? ''));
$payload = $input['data'] ?? null;
$clientVersion = isset($input['version']) ? (int)$input['version'] : 0;

if ($sku === '') {
    jsonResponse(['status' => 'error', 'message' => 'SKU is required'], 400);
}
if (!is_array($payload)) {
    jsonResponse(['status' => 'error', 'message' => 'Missing payload'], 400);
}

$payloadJson = json_encode($payload);
if ($payloadJson === false) {
    jsonResponse(['status' => 'error', 'message' => 'Could not encode payload'], 400);
}
// Limit payload size to ~64 KB to avoid abuse.
if (strlen($payloadJson) > 65536) {
    jsonResponse(['status' => 'error', 'message' => 'Payload too large'], 400);
}

$existing = $pdo->prepare('SELECT id, version, payload, updated_at FROM intake_drafts WHERE sku_normalized = :sku LIMIT 1');
$existing->execute(['sku' => $sku]);
$row = $existing->fetch(PDO::FETCH_ASSOC);

if ($row && $clientVersion > 0 && (int)$row['version'] !== $clientVersion) {
    jsonResponse([
        'status' => 'conflict',
        'server_version' => (int)$row['version'],
        'server_updated_at' => (string)$row['updated_at'],
        'server_data' => json_decode((string)$row['payload'], true),
    ], 409);
}

$now = (new DateTime('now'))->format('c');

if ($row) {
    $newVersion = ((int)$row['version']) + 1;
    $update = $pdo->prepare('UPDATE intake_drafts SET payload = :payload, version = :version, updated_at = :updated_at WHERE id = :id');
    $update->execute([
        'payload' => $payloadJson,
        'version' => $newVersion,
        'updated_at' => $now,
        'id' => (int)$row['id'],
    ]);
    jsonResponse(['status' => 'ok', 'version' => $newVersion, 'saved_at' => $now]);
}

$insert = $pdo->prepare('INSERT INTO intake_drafts (sku_normalized, payload, version, updated_at) VALUES (:sku, :payload, 1, :updated_at)');
$insert->execute([
    'sku' => $sku,
    'payload' => $payloadJson,
    'updated_at' => $now,
]);
jsonResponse(['status' => 'ok', 'version' => 1, 'saved_at' => $now]);
