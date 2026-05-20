<?php
/**
 * One-time script: removes the duplicate D-2620-104 row that does NOT
 * mention "refurb" in what_is_it or notes. Archives it to intake_deleted first.
 *
 * Run once on the server:  php _delete_duplicate_sku.php
 * Then delete this file.
 */
declare(strict_types=1);

$pdo = new PDO('sqlite:' . __DIR__ . '/data/intake.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Ensure soft-delete table exists with deleted_at column.
$pdo->exec("CREATE TABLE IF NOT EXISTS intake_deleted AS SELECT * FROM intake_items WHERE 0");
$cols = array_column($pdo->query("PRAGMA table_info(intake_deleted)")->fetchAll(), 'name');
if (!in_array('deleted_at', $cols, true)) {
    $pdo->exec("ALTER TABLE intake_deleted ADD COLUMN deleted_at TEXT");
}

// Fetch all rows for this SKU.
$rows = $pdo->query("
    SELECT * FROM intake_items
    WHERE sku_normalized = 'D-2620-104'
    ORDER BY id ASC
")->fetchAll();

echo 'Found ' . count($rows) . ' row(s) for D-2620-104:' . PHP_EOL;
foreach ($rows as $r) {
    echo '  id=' . $r['id']
        . ' | what_is_it=' . $r['what_is_it']
        . ' | notes=' . $r['notes']
        . ' | status=' . $r['status']
        . ' | updated=' . $r['updated_at'] . PHP_EOL;
}

if (count($rows) < 2) {
    echo 'Less than 2 rows found — nothing to delete.' . PHP_EOL;
    exit(0);
}

// Find the row that does NOT mention refurb (case-insensitive).
$toDelete = null;
$toKeep   = null;
foreach ($rows as $r) {
    $combined = strtolower((string)($r['what_is_it'] ?? '') . ' ' . (string)($r['notes'] ?? ''));
    if (str_contains($combined, 'refurb')) {
        $toKeep = $r;
    } else {
        $toDelete = $r;
    }
}

// If both or neither mention refurb, fall back to deleting the older (lower id) row.
if ($toDelete === null || $toKeep === null) {
    echo 'Could not distinguish by "refurb" keyword — deleting the older row (lower id).' . PHP_EOL;
    usort($rows, static fn($a, $b) => (int)$a['id'] <=> (int)$b['id']);
    $toDelete = $rows[0];
    $toKeep   = $rows[1];
}

echo PHP_EOL . 'KEEPING  id=' . $toKeep['id']   . ' | what_is_it=' . $toKeep['what_is_it']   . ' | notes=' . $toKeep['notes']   . PHP_EOL;
echo 'DELETING id=' . $toDelete['id'] . ' | what_is_it=' . $toDelete['what_is_it'] . ' | notes=' . $toDelete['notes'] . PHP_EOL;

echo PHP_EOL . 'Proceed? Type YES to confirm: ';
$confirm = trim((string)fgets(STDIN));
if ($confirm !== 'YES') {
    echo 'Aborted.' . PHP_EOL;
    exit(0);
}

$pdo->beginTransaction();

// Archive the row.
$toDelete['deleted_at'] = (new DateTime('now'))->format('c');
$colNames   = array_keys($toDelete);
$colPlaces  = array_map(static fn($c) => ':' . $c, $colNames);
$pdo->prepare(
    'INSERT INTO intake_deleted (' . implode(',', $colNames) . ') VALUES (' . implode(',', $colPlaces) . ')'
)->execute($toDelete);

// Delete from live table.
$pdo->prepare('DELETE FROM intake_items WHERE id = :id')->execute(['id' => $toDelete['id']]);

$pdo->commit();

echo 'Done. Row id=' . $toDelete['id'] . ' archived to intake_deleted and removed from intake_items.' . PHP_EOL;
echo 'You can recover it with undo_delete.php if needed.' . PHP_EOL;
