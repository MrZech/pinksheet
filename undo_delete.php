<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance(true);
ensureStorageWritable();

const DB_PATH = __DIR__ . '/data/intake.sqlite';

header('Content-Type: application/json; charset=utf-8');

/**
 * Ensure the archive table used for soft deletes exists.
 */
function ensureArchiveTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS intake_deleted AS SELECT * FROM intake_items WHERE 0");
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

try {
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    ensureArchiveTable($pdo);

    $pdo->beginTransaction();

    // Restore the most recent deletion.
    $deleted = $pdo->query("SELECT * FROM intake_deleted ORDER BY deleted_at DESC, rowid DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$deleted) {
        $pdo->rollBack();
        echo json_encode(['status' => 'empty', 'message' => 'Nothing to undo']);
        exit;
    }

    $originalId = (int)($deleted['id'] ?? 0);
    $restoredSku = (string)($deleted['sku'] ?? '');
    $deletedAt = (string)($deleted['deleted_at'] ?? '');

    // Prepare insert back into intake_items without the old primary key.
    unset($deleted['id'], $deleted['deleted_at']);
    $columns = array_keys($deleted);
    $placeholders = array_map(static fn($c) => ':' . $c, $columns);
    $insert = $pdo->prepare(
        'INSERT INTO intake_items (' . implode(',', $columns) . ') VALUES (' . implode(',', $placeholders) . ')'
    );
    $insert->execute($deleted);
    $newId = (int)$pdo->lastInsertId();

    $remove = $pdo->prepare('DELETE FROM intake_deleted WHERE id = :id AND deleted_at = :deleted_at');
    $remove->execute(['id' => $originalId, 'deleted_at' => $deletedAt]);

    $pdo->commit();

    echo json_encode([
        'status' => 'ok',
        'restored_sku' => $restoredSku,
        'new_id' => $newId,
        'original_id' => $originalId,
        'deleted_at' => $deletedAt,
    ]);
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Undo failed']);
}
