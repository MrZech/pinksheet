<?php
/**
 * check_square_env.php — validate the local .env Square configuration.
 *
 * Usage:
 *   php scripts/check_square_env.php
 *
 * Prints a per-setting report and, when an access token is present and not a
 * placeholder, tests it against the Square API for the configured environment
 * (read-only GET /v2/locations). Exits 0 when production-ready, 1 otherwise.
 *
 * No secrets are printed — tokens/keys are shown as present/missing only.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php'; // loads .env

$env      = strtolower(trim((string)(getenv('SQUARE_ENVIRONMENT') ?: 'sandbox')));
$token    = trim((string)(getenv('SQUARE_ACCESS_TOKEN') ?: ''));
$location = trim((string)(getenv('SQUARE_LOCATION_ID') ?: ''));
$sig      = trim((string)(getenv('SQUARE_WEBHOOK_SIGNATURE_KEY') ?: ''));
$hookUrl  = trim((string)(getenv('SQUARE_WEBHOOK_NOTIFICATION_URL') ?: ''));
$qz       = trim((string)(getenv('QZ_ALLOWED_ORIGINS') ?: ''));

$placeholderPrefix = 'replace-with-';

$rows = [];
$ok  = true;

function addRow(string $label, string $state, bool $good, string $note = ''): void
{
    global $rows, $ok;
    $rows[] = [
        'label' => $label,
        'state' => $state,
        'good'  => $good,
        'note'  => $note,
    ];
    if (!$good) {
        $ok = false;
    }
}

/* ── SQUARE_ENVIRONMENT ─────────────────────────────────── */
if ($env === 'production') {
    addRow('SQUARE_ENVIRONMENT', 'production', true);
} else {
    addRow('SQUARE_ENVIRONMENT', $env !== '' ? $env : 'unset', false,
        'Set to production for live sync (sandbox only for testing).');
}

/* ── SQUARE_ACCESS_TOKEN ────────────────────────────────── */
$tokenOk = false;
if ($token === '' || str_starts_with($token, $placeholderPrefix)) {
    addRow('SQUARE_ACCESS_TOKEN', 'missing / placeholder', false,
        'Paste the Production access token from the Square Developer Dashboard (Apps > your app > Credentials > Production > Access token > Show).');
} elseif (strlen($token) < 20) {
    addRow('SQUARE_ACCESS_TOKEN', 'present (suspiciously short)', false,
        'Real Square tokens are much longer — check you copied the whole token.');
} else {
    $base = $env === 'production' ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';
    $ch = curl_init($base . '/v2/locations');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Square-Version: ' . (trim((string)getenv('SQUARE_API_VERSION')) ?: '2026-07-15'),
        ],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CAINFO => __DIR__ . '/../certs/cacert.pem',
    ]);
    $body  = curl_exec($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($code === 200) {
        $data = json_decode((string)$body, true);
        $n = count($data['locations'] ?? []);
        addRow('SQUARE_ACCESS_TOKEN', 'valid (' . $env . ' API: HTTP 200, ' . $n . ' location' . ($n === 1 ? '' : 's') . ')', true);
        $tokenOk = true;
    } elseif ($error !== '') {
        addRow('SQUARE_ACCESS_TOKEN', 'test failed (network: ' . $error . ')', false);
    } else {
        addRow('SQUARE_ACCESS_TOKEN', 'REJECTED by ' . $env . ' API (HTTP ' . $code . ')', false,
            'Square says this token is not authorized for ' . $env . '. Common causes: it is a Sandbox token pasted into a production setup (or vice-versa), it was revoked, or it is a placeholder.');
    }
}

/* ── SQUARE_LOCATION_ID ─────────────────────────────────── */
if ($location !== '' && !str_starts_with($location, $placeholderPrefix)) {
    addRow('SQUARE_LOCATION_ID', 'set', true);
} else {
    addRow('SQUARE_LOCATION_ID', 'missing / placeholder', false,
        'Find it in the Dashboard: Locations > your location, or via the API.');
}

/* ── SQUARE_WEBHOOK_SIGNATURE_KEY ───────────────────────── */
if ($sig !== '' && !str_starts_with($sig, $placeholderPrefix)) {
    addRow('SQUARE_WEBHOOK_SIGNATURE_KEY', 'set', true);
} else {
    addRow('SQUARE_WEBHOOK_SIGNATURE_KEY', 'missing / placeholder', false,
        'Dashboard: Webhooks > your subscription > Signature key.');
}

/* ── SQUARE_WEBHOOK_NOTIFICATION_URL ────────────────────── */
if ($hookUrl === '') {
    addRow('SQUARE_WEBHOOK_NOTIFICATION_URL', 'unset', false,
        'Must be the exact HTTPS URL registered in the Square webhook subscription.');
} elseif (!str_starts_with($hookUrl, 'https://')) {
    addRow('SQUARE_WEBHOOK_NOTIFICATION_URL', $hookUrl, false,
        'Square only delivers to HTTPS URLs.');
} elseif (str_contains($hookUrl, '127.0.0.1') || str_contains($hookUrl, 'localhost') || str_contains($hookUrl, 'ngrok')) {
    addRow('SQUARE_WEBHOOK_NOTIFICATION_URL', $hookUrl, false,
        'Square must reach this URL from the internet — a localhost/ngrok URL cannot receive live events.');
} else {
    addRow('SQUARE_WEBHOOK_NOTIFICATION_URL', $hookUrl, true);
}

/* ── QZ_ALLOWED_ORIGINS ─────────────────────────────────── */
if ($qz === '') {
    addRow('QZ_ALLOWED_ORIGINS', 'unset (defaults to *)', true,
        'Works from any origin on a trusted LAN. Tighten to the exact origin if you prefer.');
} elseif ($qz === '*') {
    addRow('QZ_ALLOWED_ORIGINS', '*', true, 'Fine on a trusted LAN; tighten if you want.');
} else {
    addRow('QZ_ALLOWED_ORIGINS', $qz, true);
}

/* ── Output ─────────────────────────────────────────────── */
echo "Square .env configuration check\n";
echo str_repeat('-', 64) . "\n";
foreach ($rows as $r) {
    $mark = $r['good'] ? 'OK  ' : 'FAIL';
    printf("%s  %-34s %s\n", $mark, $r['label'], $r['state']);
    if ($r['note'] !== '') {
        printf("       %s\n", $r['note']);
    }
}
echo str_repeat('-', 64) . "\n";
if ($ok) {
    echo "RESULT: production-ready\n";
    exit(0);
}
echo "RESULT: NOT ready — fix the FAIL rows above\n";
exit(1);
