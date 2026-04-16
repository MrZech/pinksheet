<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance(true);
ensureStorageWritable();

const DB_PATH = __DIR__ . '/data/intake.sqlite';

header('Content-Type: application/json; charset=utf-8');

function normalizeSku(string $sku): string
{
    return strtoupper(trim($sku));
}

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_THROW_ON_ERROR);
    exit;
}

function readInput(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $input = json_decode($raw, true);
    return is_array($input) ? $input : [];
}

$pdo = new PDO('sqlite:' . DB_PATH, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS script_cache (
    sku_normalized TEXT PRIMARY KEY,
    sku_display TEXT NOT NULL,
    prompt_text TEXT,
    chatgpt_text TEXT,
    final_text TEXT,
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
SQL);
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_script_cache_updated_at ON script_cache (updated_at)");

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $sku = normalizeSku((string)($_GET['sku'] ?? ''));
    if ($sku === '') {
        jsonResponse(['status' => 'ok', 'has_cache' => false]);
    }
    $stmt = $pdo->prepare('SELECT sku_normalized, sku_display, prompt_text, chatgpt_text, final_text, updated_at FROM script_cache WHERE sku_normalized = :sku LIMIT 1');
    $stmt->execute(['sku' => $sku]);
    $row = $stmt->fetch();
    if (!$row) {
        jsonResponse(['status' => 'ok', 'has_cache' => false]);
    }
    jsonResponse([
        'status' => 'ok',
        'has_cache' => true,
        'data' => $row,
    ]);
}

if ($method !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

$input = readInput();
$sku = normalizeSku((string)($input['sku'] ?? ''));
$skuDisplay = trim((string)($input['sku_display'] ?? $sku));
$promptText = trim((string)($input['prompt_text'] ?? ''));
$chatgptText = trim((string)($input['chatgpt_text'] ?? ''));
$finalText = trim((string)($input['final_text'] ?? ''));

if ($sku === '') {
    jsonResponse(['status' => 'error', 'message' => 'SKU is required'], 400);
}
if ($skuDisplay === '') {
    $skuDisplay = $sku;
}

$now = (new DateTimeImmutable('now'))->format('c');

$stmt = $pdo->prepare(<<<'SQL'
INSERT INTO script_cache (sku_normalized, sku_display, prompt_text, chatgpt_text, final_text, updated_at)
VALUES (:sku_normalized, :sku_display, :prompt_text, :chatgpt_text, :final_text, :updated_at)
ON CONFLICT(sku_normalized) DO UPDATE SET
    sku_display = excluded.sku_display,
    prompt_text = excluded.prompt_text,
    chatgpt_text = excluded.chatgpt_text,
    final_text = excluded.final_text,
    updated_at = excluded.updated_at
SQL);
$stmt->execute([
    'sku_normalized' => $sku,
    'sku_display' => $skuDisplay,
    'prompt_text' => $promptText !== '' ? $promptText : null,
    'chatgpt_text' => $chatgptText !== '' ? $chatgptText : null,
    'final_text' => $finalText !== '' ? $finalText : null,
    'updated_at' => $now,
]);

jsonResponse([
    'status' => 'ok',
    'saved_at' => $now,
]);
