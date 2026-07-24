<?php
$pdo = new PDO('sqlite:data/intake.sqlite');
$total = microtime(true);

$s = microtime(true);
$rows = $pdo->query("SELECT COUNT(*) FROM intake_items")->fetchColumn();
printf("Total items: %d\n", $rows);

$s1 = microtime(true);
$items = $pdo->query("
    SELECT id, sku, sku_normalized, status, what_is_it, notes, updated_at, dispotech_price, reviewed
    FROM intake_items
    WHERE sku IS NOT NULL AND TRIM(sku) <> ''
    ORDER BY updated_at DESC, id DESC
    LIMIT 2000
")->fetchAll();
printf("Kanban query (2000): %.0fms, %d rows\n", (microtime(true)-$s1)*1000, count($items));

$s2 = microtime(true);
$items2 = $pdo->query("
    SELECT sku, status, what_is_it, updated_at, dispotech_price, ebay_price
    FROM intake_items
    WHERE sku IS NOT NULL AND TRIM(sku) <> ''
    ORDER BY updated_at DESC, id DESC
    LIMIT 200
")->fetchAll();
printf("Home/lookup query (200): %.0fms, %d rows\n", (microtime(true)-$s2)*1000, count($items2));

$norms = array_values(array_unique(array_filter(array_map(fn($r) => strtoupper(trim($r['sku'] ?? '')), $items2))));
$placeholders = implode(',', array_fill(0, count($norms), '?'));

$s3 = microtime(true);
$ph = $pdo->prepare("SELECT sku_normalized, id FROM sku_photos WHERE sku_normalized IN ($placeholders) ORDER BY is_thumb DESC, id DESC");
$ph->execute($norms);
$photos = $ph->fetchAll();
printf("Photo query (old, no LIMIT): %.0fms, %d rows\n", (microtime(true)-$s3)*1000, count($photos));

$s4 = microtime(true);
$ph2 = $pdo->prepare("SELECT sku_normalized, MAX(id) AS id FROM sku_photos WHERE sku_normalized IN ($placeholders) GROUP BY sku_normalized");
$ph2->execute($norms);
$photos2 = $ph2->fetchAll();
printf("Photo query (GROUP BY MAX): %.0fms, %d rows\n", (microtime(true)-$s4)*1000, count($photos2));

printf("Total: %.0fms\n", (microtime(true)-$total)*1000);
