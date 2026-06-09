<?php
/**
 * One-time migration: rename status 'eBay' -> 'eBay Listed'
 *
 * Safe to run multiple times — if no rows have status='eBay' it does nothing.
 *
 * Usage (from the project root on the server):
 *   php migrate_ebay_status.php
 *
 * Delete this file after running it.
 */
declare(strict_types=1);

$dbPath = __DIR__ . '/data/intake.sqlite';

if (!is_file($dbPath)) {
    echo "ERROR: Database not found at $dbPath\n";
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// ── Step 1: Count affected rows so you know what will change ──────────
$count = (int) $pdo
    ->query("SELECT COUNT(*) FROM intake_items WHERE status = 'eBay'")
    ->fetchColumn();

if ($count === 0) {
    echo "Nothing to do — no items found with status 'eBay'.\n";
    echo "Either the migration already ran, or all items were already on the new status.\n";
    exit(0);
}

echo "Found $count item(s) with status = 'eBay'.\n";
echo "These will be updated to status = 'eBay Listed'.\n";
echo "\nType YES to confirm and run the update, or anything else to cancel: ";

$confirm = trim((string) fgets(STDIN));

if ($confirm !== 'YES') {
    echo "Cancelled. No changes were made.\n";
    exit(0);
}

// ── Step 2: Run the update ────────────────────────────────────────────
$stmt = $pdo->prepare(
    "UPDATE intake_items
     SET status = 'eBay Listed', updated_at = datetime('now')
     WHERE status = 'eBay'"
);
$stmt->execute();
$updated = $stmt->rowCount();

echo "\nDone. $updated item(s) updated from 'eBay' to 'eBay Listed'.\n";
echo "These items will now appear in the 'eBay Listed' column on the status board.\n";
echo "\nYou can now delete this file: migrate_ebay_status.php\n";
