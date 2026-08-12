<?php
declare(strict_types=1);

/**
 * Device-aware entry point for card QR codes.
 *
 * Every QR code printed on a card (kanban board, print_card, intake sheet)
 * points here. Depending on the device that scans it:
 *   - Phones  -> mobile_action.php (lightweight card view + photo upload)
 *   - Desktop -> intake.php (the existing full intake sheet for that SKU)
 *
 * Keeping one URL for both means a single printed QR works everywhere and
 * the destination can change without re-printing cards.
 */

require_once __DIR__ . '/config.php';
checkMaintenance();

$sku = isset($_GET['sku']) ? normalizeSku((string)$_GET['sku']) : '';
if ($sku === '') {
    http_response_code(400);
    exit('Missing sku parameter');
}

$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isMobile = preg_match('/Android|iPhone|iPad|iPod|Mobile|Opera Mini|IEMobile|Silk|BlackBerry|Windows Phone/i', $ua) === 1
    || ($_SERVER['HTTP_SEC_CH_UA_MOBILE'] ?? '') === '?1';

$target = $isMobile
    ? 'mobile_action.php?sku=' . urlencode($sku)
    : 'intake.php?sku=' . urlencode($sku);

header('Location: ' . $target, true, 302);
exit;
