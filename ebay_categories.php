<?php
declare(strict_types=1);

/**
 * ebay_categories.php — JSON source for the intake "eBay Category" combobox.
 *
 * Returns the live snapshot (data/ebay_categories.json) when it exists and
 * is valid, otherwise the bundled fallback list. Refreshing the snapshot is
 * a CLI-only operation so the web request can never trigger a slow network
 * call:
 *
 *     php scripts/refresh_ebay_categories.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/ebay_categories.php';

checkMaintenance(true);
ensureStorageWritable();

$list = ebayCategoryList();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'ok' => true,
    'source' => $list['source'],
    'generated_at' => $list['generated_at'],
    'count' => count($list['categories']),
    'categories' => $list['categories'],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit;
