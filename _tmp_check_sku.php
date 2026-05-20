<?php
$pdo = new PDO('sqlite:data/intake.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$rows = $pdo->query("SELECT id, sku, sku_normalized, what_is_it, notes, status, updated_at FROM intake_items WHERE sku_normalized LIKE '%2620%' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) { echo 'No rows found' . PHP_EOL; }
foreach ($rows as $r) {
    echo $r['id'] . ' | SKU: ' . $r['sku'] . ' | norm: ' . $r['sku_normalized'] . ' | what: ' . $r['what_is_it'] . ' | status: ' . $r['status'] . ' | notes: ' . $r['notes'] . ' | updated: ' . $r['updated_at'] . PHP_EOL;
}
