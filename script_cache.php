<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance(true);
ensureStorageWritable();

const DB_PATH = __DIR__ . '/data/intake.sqlite';



function readInput(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $input = json_decode($raw, true);
    return is_array($input) ? $input : [];
}

try {
    $pdo = pdoConnect(DB_PATH);
} catch (Throwable $e) {
    errorResponse('Database connection failed', 500);
}

try {
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
} catch (Throwable $e) {
    errorResponse('Schema initialization failed', 500);
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $sku = normalizeSku((string)($_GET['sku'] ?? ''));
        if ($sku === '') {
            successResponse(['status' => 'ok', 'has_cache' => false]);
        }
        $stmt = $pdo->prepare('SELECT sku_normalized, sku_display, prompt_text, chatgpt_text, final_text, updated_at FROM script_cache WHERE sku_normalized = :sku LIMIT 1');
        $stmt->execute(['sku' => $sku]);
        $row = $stmt->fetch();
        if (!$row) {
            successResponse(['status' => 'ok', 'has_cache' => false]);
        }
        successResponse([
            'status' => 'ok',
            'has_cache' => true,
            'data' => $row,
        ]);
    }

    if ($method !== 'POST') {
        errorResponse('Method not allowed', 405);
    }

    require_csrf();

    $input = readInput();
    $sku = normalizeSku((string)($input['sku'] ?? ''));
    $skuDisplay = trim((string)($input['sku_display'] ?? $sku));
    $promptText = (string)($input['prompt_text'] ?? '');
    $chatgptText = (string)($input['chatgpt_text'] ?? '');
    $finalText = (string)($input['final_text'] ?? '');

    if ($sku === '') {
        errorResponse('SKU is required', 400);
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

    successResponse([
        'status' => 'ok',
        'saved_at' => $now,
    ]);
} catch (Throwable $e) {
    errorResponse('Internal server error', 500);
}
