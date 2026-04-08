<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance(true);
ensureStorageWritable();

const DB_PATH = __DIR__ . '/data/intake.sqlite';

/**
 * Create an archive table (soft delete store) if missing.
 */
function ensureArchiveTable(PDO $pdo): void
{
    // Create table with same schema as intake_items.
    $pdo->exec("CREATE TABLE IF NOT EXISTS intake_deleted AS SELECT * FROM intake_items WHERE 0");
    // Add deleted_at for recovery metadata if it does not already exist.
    $hasDeletedAt = false;
    foreach ($pdo->query("PRAGMA table_info(intake_deleted)") as $col) {
        if ((string)$col['name'] === 'deleted_at') {
            $hasDeletedAt = true;
            break;
        }
    }
    if (!$hasDeletedAt) {
        $pdo->exec("ALTER TABLE intake_deleted ADD COLUMN deleted_at TEXT");
    }
}

header('Content-Type: application/json; charset=utf-8');

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$sku = strtoupper(trim((string)($_POST['sku'] ?? '')));
$confirm = strtoupper(trim((string)($_POST['confirm'] ?? '')));

if ($id <= 0 || $sku === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing id or sku']);
    exit;
}

if ($confirm !== 'DELETE') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Confirm with DELETE']);
    exit;
}

// Detect AJAX via header; default to redirect for form posts.
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$acceptsJson = isset($_SERVER['HTTP_ACCEPT']) && stripos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

try {
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->beginTransaction();
    ensureArchiveTable($pdo);

    // Fetch the row before deletion so we can archive it.
    $fetch = $pdo->prepare('SELECT * FROM intake_items WHERE id = :id AND sku_normalized = :sku LIMIT 1');
    $fetch->execute(['id' => $id, 'sku' => $sku]);
    $row = $fetch->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $row['deleted_at'] = (new DateTime('now'))->format('c');
        // Build an insert with column list minus any SQLite virtual columns.
        $cols = array_keys($row);
        $placeholders = array_map(static fn($c) => ':' . $c, $cols);
        $sql = 'INSERT INTO intake_deleted (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')';
        $archive = $pdo->prepare($sql);
        $archive->execute($row);
    }

    $stmt = $pdo->prepare('DELETE FROM intake_items WHERE id = :id AND sku_normalized = :sku');
    $stmt->execute(['id' => $id, 'sku' => $sku]);
    $count = $stmt->rowCount();

    $pdo->commit();

    $response = ['status' => 'ok', 'deleted' => $count, 'archived' => (bool)$row];
    if ($isAjax || $acceptsJson) {
        echo json_encode($response);
    } else {
        header('Location: index.php?deleted=' . (int)$count);
    }
} catch (Throwable $e) {
    http_response_code(500);
    if ($isAjax || $acceptsJson) {
        echo json_encode(['status' => 'error', 'message' => 'Server error']);
    } else {
        header('Location: index.php?deleted=0');
    }
}
