<?php
declare(strict_types=1);

/**
 * Generate ZPL label data for a given SKU.
 *
 * Accepts GET or POST.  Returns JSON with the raw ZPL string and
 * label metadata for client-side preview / printing.
 *
 * Parameters (GET or POST):
 *   sku         string  (required)  SKU to look up
 *   preset      string  (optional)  "compact" (default) or "detail"
 *   dpi         int     (optional)  203 (default) or 300
 *   font_size   int     (optional)  SKU font size in dots (24-64)
 *   code_type   string  (optional)  "qr" (default), "code128", or "none"
 *   show_details int    (optional)  0 or 1
 *
 * POST with Content-Type application/json:
 *   Body may contain full item data (sku, itemName, description, date, etc.)
 *   plus the label parameters above.  Any field provided in the JSON body
 *   overrides the database record for that SKU.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/assets/zpl.php';
checkMaintenance(true);

const LABEL_MAX_SKU_LENGTH = 64;
const LABEL_MAX_NAME_LENGTH = 120;
const LABEL_MAX_DESC_LENGTH = 160;
const LABEL_DEFAULT_DPI = 203;
const LABEL_DPI_OPTIONS = [203, 300];
const LABEL_PRESET_OPTIONS = ['compact', 'detail'];
const LABEL_CODE_OPTIONS = ['qr', 'code128', 'none'];
const LABEL_FONT_MIN = 24;
const LABEL_FONT_MAX = 64;

/* ── Parse input ─────────────────────────────────────────────── */
$input = [];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $rawBody = file_get_contents('php://input');
        if ($rawBody !== false && $rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $input = $decoded;
            }
        }
    } else {
        $input = $_POST;
    }
} else {
    $input = $_GET;
}

/* ── Validation ──────────────────────────────────────────────── */
$sku = trim((string)($input['sku'] ?? ''));
if ($sku === '') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'SKU is required.']);
    exit;
}

$preset   = in_array($input['preset'] ?? '', LABEL_PRESET_OPTIONS, true) ? $input['preset'] : 'compact';
$dpi      = in_array((int)($input['dpi'] ?? 0), LABEL_DPI_OPTIONS, true) ? (int)$input['dpi'] : LABEL_DEFAULT_DPI;
$codeType = in_array($input['code_type'] ?? '', LABEL_CODE_OPTIONS, true) ? $input['code_type'] : '';
$fontSize = isset($input['font_size']) ? max(LABEL_FONT_MIN, min(LABEL_FONT_MAX, (int)$input['font_size'])) : 0;
$showDetails = isset($input['show_details']) ? !empty($input['show_details']) : null;

/* ── Determine item data ─────────────────────────────────────── */

// 1. Use fields provided in the request body (client-side overrides).
$itemName    = trim((string)($input['itemName'] ?? ''));
$description = trim((string)($input['description'] ?? ''));
$date        = trim((string)($input['date'] ?? ''));

// 2. If any core field is missing, try to load from the database.
$dbItem = null;
if ($itemName === '' || $description === '' || $date === '') {
    try {
        $dbPath = __DIR__ . '/data/intake.sqlite';
        if (is_file($dbPath) && is_readable($dbPath)) {
            $pdo = new PDO('sqlite:' . $dbPath, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $stmt = $pdo->prepare('SELECT sku, what_is_it, date_received, notes, brand_model FROM intake_items WHERE sku_normalized = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([strtoupper($sku)]);
            $dbItem = $stmt->fetch();
        }
    } catch (PDOException $e) {
        // DB unavailable — continue with whatever data we have.
    }
}

/* ── Build item array ────────────────────────────────────────── */
$presetDef = zplPreset($preset);

$item = [
    'sku'          => $sku,
    'itemName'     => $itemName !== '' ? $itemName : ($dbItem['what_is_it'] ?? ''),
    'description'  => $description !== '' ? $description : ($dbItem['notes'] ?? ''),
    'date'         => $date !== '' ? $date : ($dbItem['date_received'] ?? ''),
    'labelPreset'  => $preset,
    'dpi'          => $dpi,
    'skuFontSize'  => $fontSize > 0 ? $fontSize : $presetDef['defaultFont'],
    'codeType'     => $codeType !== '' ? $codeType : $presetDef['defaultCode'],
    'showDetails'  => $showDetails !== null ? $showDetails : $presetDef['defaultDetails'],
];

/* ── Generate ZPL ────────────────────────────────────────────── */
$zpl = generateLabelZpl($item);

/* ── Response ────────────────────────────────────────────────── */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'status'     => 'ok',
    'zpl'        => $zpl,
    'sku'        => $item['sku'],
    'itemName'   => $item['itemName'],
    'labelPreset' => $presetDef['name'],
    'widthIn'    => $presetDef['widthIn'],
    'heightIn'   => $presetDef['heightIn'],
    'dpi'        => $dpi,
]);
