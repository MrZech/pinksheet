<?php
declare(strict_types=1);

/**
 * refresh_ebay_categories.php — pull eBay's live electronics category tree
 * and write it to data/ebay_categories.json for the intake combobox.
 *
 * Uses the eBay Commerce Taxonomy API with an OAuth client-credentials token,
 * so it needs eBay developer credentials in .env:
 *
 *     EBAY_CLIENT_ID=...
 *     EBAY_CLIENT_SECRET=...
 *     EBAY_MARKETPLACE_ID=EBAY_US     (optional, default EBAY_US)
 *
 * Usage:
 *     php scripts/refresh_ebay_categories.php
 *     php scripts/refresh_ebay_categories.php --marketplace EBAY_GB
 *
 * The script only flattens the configured top-level electronics categories
 * (overridable with EBAY_TOP_CATEGORY_NAMES, comma-separated). Leaf entries
 * keep their real eBay category id, name, and breadcrumb path.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/ebay_categories.php';

const EBAY_TOKEN_URL = 'https://api.ebay.com/identity/v1/oauth2/token';
const EBAY_TAXONOMY_URL = 'https://api.ebay.com/commerce/taxonomy/v1/category_tree/';

$marketplace = 'EBAY_US';
foreach ($argv as $i => $arg) {
    if ($arg === '--marketplace' && isset($argv[$i + 1])) {
        $marketplace = strtoupper(trim($argv[$i + 1]));
    }
}
$marketplace = strtoupper(trim((string)(getenv('EBAY_MARKETPLACE_ID') ?: $marketplace)));

$clientId = trim((string)(getenv('EBAY_CLIENT_ID') ?: ''));
$clientSecret = trim((string)(getenv('EBAY_CLIENT_SECRET') ?: ''));

if ($clientId === '' || $clientSecret === '') {
    fwrite(STDERR, "eBay credentials are missing.\n");
    fwrite(STDERR, "Set EBAY_CLIENT_ID and EBAY_CLIENT_SECRET in .env, then re-run.\n");
    exit(2);
}

$topNames = array_values(array_filter(array_map('trim', explode(',', (string)(getenv('EBAY_TOP_CATEGORY_NAMES') ?: '')))));
if ($topNames === []) {
    $topNames = [
        'Computers/Tablets & Networking',
        'Consumer Electronics',
        'Cell Phones, Smart Watches & Accessories',
        'Video Games & Consoles',
    ];
}
$topIds = array_values(array_filter(array_map('trim', explode(',', (string)(getenv('EBAY_TOP_CATEGORY_IDS') ?: '')))));

function ebayRefreshCurl(string $url, array $headers, ?string $postFields = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required to refresh eBay categories.');
    }
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
    ];
    if ($postFields !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $postFields;
    }
    curl_setopt_array($ch, $opts);
    $caBundle = dirname(__DIR__) . '/certs/cacert.pem';
    if (is_file($caBundle)) {
        curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
    }
    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false || $error !== '') {
        throw new RuntimeException('eBay request failed: ' . ($error !== '' ? $error : 'unknown transport error'));
    }
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded) || $status < 200 || $status >= 300) {
        $summary = is_array($decoded) ? substr((string)json_encode($decoded), 0, 500) : substr((string)$raw, 0, 500);
        throw new RuntimeException('eBay API error ' . $status . ': ' . $summary);
    }
    return $decoded;
}

function ebayRefreshToken(string $clientId, string $clientSecret): string
{
    $auth = base64_encode($clientId . ':' . $clientSecret);
    $resp = ebayRefreshCurl(EBAY_TOKEN_URL, [
        'Authorization: Basic ' . $auth,
        'Content-Type: application/x-www-form-urlencoded',
    ], http_build_query([
        'grant_type' => 'client_credentials',
        'scope' => 'https://api.ebay.com/oauth/api_scope',
    ]));
    $token = trim((string)($resp['access_token'] ?? ''));
    if ($token === '') {
        throw new RuntimeException('eBay did not return an access token.');
    }
    return $token;
}

/**
 * @param array<string, mixed> $node
 * @param list<string> $path
 * @param list<array{id: string, name: string, path: string}> $out
 */
function ebayRefreshFlatten(array $node, array $path, array &$out): void
{
    $cat = is_array($node['category'] ?? null) ? $node['category'] : [];
    $name = trim((string)($cat['categoryName'] ?? ''));
    $id = trim((string)($cat['categoryId'] ?? ''));
    $currentPath = $name === '' ? $path : array_merge($path, [$name]);
    $children = is_array($node['childCategoryTreeNodes'] ?? null) ? $node['childCategoryTreeNodes'] : [];
    $isLeaf = !empty($cat['leafCategoryTreeNode']);

    if (($isLeaf || $children === []) && $name !== '' && count($currentPath) >= 2) {
        $out[] = ['id' => $id, 'name' => $name, 'path' => implode(' > ', $currentPath)];
        return;
    }

    foreach ($children as $child) {
        if (is_array($child)) {
            ebayRefreshFlatten($child, $currentPath, $out);
        }
    }
}

$token = ebayRefreshToken($clientId, $clientSecret);
$tree = ebayRefreshCurl(EBAY_TAXONOMY_URL . rawurlencode($marketplace), [
    'Authorization: Bearer ' . $token,
    'Accept: application/json',
]);

$root = $tree['rootCategoryNode'] ?? null;
if (!is_array($root)) {
    throw new RuntimeException('eBay category tree did not include a root node.');
}

$topNodes = [];
foreach (($root['childCategoryTreeNodes'] ?? []) as $node) {
    if (!is_array($node)) {
        continue;
    }
    $cat = is_array($node['category'] ?? null) ? $node['category'] : [];
    $name = trim((string)($cat['categoryName'] ?? ''));
    $id = trim((string)($cat['categoryId'] ?? ''));
    if (in_array($id, $topIds, true) || in_array($name, $topNames, true)) {
        $topNodes[] = $node;
    }
}

if ($topNodes === []) {
    $matched = $topIds !== [] ? implode(', ', $topIds) : implode(', ', $topNames);
    throw new RuntimeException('No top-level electronics categories matched (' . $matched . ') in marketplace ' . $marketplace . '.');
}

$leaves = [];
foreach ($topNodes as $topNode) {
    ebayRefreshFlatten($topNode, [], $leaves);
}

if ($leaves === []) {
    throw new RuntimeException('No leaf categories found under the matched electronics categories.');
}

// Preserve curated Dispo Tech-specific categories that live outside the
// electronics tops (POS, test & measurement, electrical). A live refresh
// must never drop them just because they are not under the electronics tree.
$bundled = ebayCategoryNormalizeList(ebayCategoryBundled());
$topNamesLookup = array_fill_keys($topNames, true);
foreach ($bundled as $entry) {
    $entryTop = trim(explode(' > ', $entry['path'], 2)[0] ?? '');
    if (isset($topNamesLookup[$entryTop])) {
        continue; // the live tree already covers this electronics top
    }
    $leaves[] = $entry;
}

$leaves = ebayCategoryNormalizeList($leaves);

$payload = [
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'source' => 'ebay',
    'marketplace_id' => $marketplace,
    'tree_version' => (string)($tree['categoryTreeVersion'] ?? ''),
    'categories' => $leaves,
];

$target = ebayCategorySnapshotPath();
$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false || file_put_contents($target, $json . PHP_EOL) === false) {
    throw new RuntimeException('Could not write ' . $target);
}

echo 'Wrote ' . count($leaves) . ' eBay categories to ' . $target . PHP_EOL;
exit(0);
