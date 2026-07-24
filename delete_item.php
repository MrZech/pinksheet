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
    $pdo->exec("CREATE TABLE IF NOT EXISTS intake_deleted AS SELECT * FROM intake_items WHERE 0");
    $archiveColumns = [];
    foreach ($pdo->query("PRAGMA table_info(intake_deleted)") as $col) {
        $archiveColumns[(string)$col['name']] = true;
    }
    foreach ($pdo->query("PRAGMA table_info(intake_items)") as $col) {
        $name = (string)$col['name'];
        if ($name === 'id' || isset($archiveColumns[$name])) {
            continue;
        }
        $type = trim((string)($col['type'] ?? ''));
        $definition = 'ALTER TABLE intake_deleted ADD COLUMN ' . $name;
        if ($type !== '') {
            $definition .= ' ' . $type;
        }
        $pdo->exec($definition);
        $archiveColumns[$name] = true;
    }
    if (!isset($archiveColumns['deleted_at'])) {
        $pdo->exec("ALTER TABLE intake_deleted ADD COLUMN deleted_at TEXT");
    }
}

require_csrf();

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$sku = strtoupper(trim((string)($_POST['sku'] ?? '')));
$confirm = strtoupper(trim((string)($_POST['confirm'] ?? '')));

if ($id <= 0) {
    errorResponse('Missing id');
}

if ($confirm !== 'DELETE') {
    errorResponse('Confirm with DELETE');
}

// Detect AJAX via header; default to redirect for form posts.
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$acceptsJson = isset($_SERVER['HTTP_ACCEPT']) && stripos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

try {
    $pdo = pdoConnect(DB_PATH);
    $pdo->beginTransaction();
    ensureArchiveTable($pdo);

    // Fetch the row before deletion so we can archive it.
    $fetch = $pdo->prepare('SELECT * FROM intake_items WHERE id = :id LIMIT 1');
    $fetch->execute(['id' => $id]);
    $row = $fetch->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $pdo->rollBack();
        if ($isAjax || $acceptsJson) {
            errorResponse('Record not found', 404);
        } else {
            header('Location: index.php?deleted=0');
            exit;
        }
    }

    // Preserve the requested SKU in the response flow, but do not let a casing or
    // normalization mismatch block a legitimate delete by id.
    $rowSku = strtoupper(trim((string)($row['sku_normalized'] ?? '')));
    if ($sku !== '' && $rowSku !== '' && $sku !== $rowSku) {
        $sku = $rowSku;
    }

    if ($row) {
        $row['deleted_at'] = (new DateTime('now'))->format('c');
        // Build an insert with column list minus any SQLite virtual columns.
        $cols = array_keys($row);
        $placeholders = array_map(static fn($c) => ':' . $c, $cols);
        $sql = 'INSERT INTO intake_deleted (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')';
        $archive = $pdo->prepare($sql);
        $archive->execute($row);
    }

    $stmt = $pdo->prepare('DELETE FROM intake_items WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $count = $stmt->rowCount();

    $pdo->commit();

    if ($isAjax || $acceptsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'ok',
            'deleted' => $count,
            'archived' => (bool)$row,
        ]);
        exit;
    }
    header('Location: index.php?deleted=' . (int)$count);
    exit;
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($isAjax || $acceptsJson) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Server error']);
        exit;
    }
    header('Location: index.php?deleted=0');
    exit;
}
