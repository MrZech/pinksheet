<?php
declare(strict_types=1);

/**
 * sync_google_sheet.php — push the inventory to a live Google Sheet.
 *
 * Gives the boss a single spreadsheet link that always shows the current
 * inventory (SKU, status, description, category, qty, prices, last update).
 * The app reaches OUT to Google, so this works even though the app itself
 * is LAN-only. Run it from cron (see setup_linux_cron.sh) or by hand:
 *
 *   php scripts/sync_google_sheet.php
 *
 * Setup (full steps in deploy/README.md):
 *   1. Google Cloud console → enable the Google Sheets API.
 *   2. Create a service account + JSON key, download it to the server.
 *   3. Create the spreadsheet, share it with the service account e-mail
 *      (Editor), and copy the spreadsheet id from its URL.
 *   4. In .env:
 *        GOOGLE_SERVICE_ACCOUNT_FILE=/opt/pinksheet/data/google-sheets-key.json
 *        GOOGLE_SPREADSHEET_ID=1AbC...XYZ
 *        GOOGLE_SHEET_TAB=Inventory          (optional, default "Inventory")
 *
 * The script is cron-safe: if it is not configured it prints a note and
 * exits 0, so an unconfigured install never spams the cron log with errors.
 *
 * Auth uses the standard service-account flow: a self-signed RS256 JWT is
 * exchanged for an OAuth access token at https://oauth2.googleapis.com/token,
 * then the tab is cleared and rewritten with values.update. No third-party
 * SDK or composer dependency is required — only openssl plus either cURL or
 * allow_url_fopen, all standard in PHP builds.
 */

/*
 * Build the JWT assertion for the Google OAuth service-account flow.
 *
 * @param array $key        Parsed service-account JSON (client_email, private_key).
 * @param int   $now        Unix timestamp for iat.
 * @param int   $ttlSeconds Lifetime of the assertion (max 3600 for Google).
 */
function googleSheetBuildJwt(array $key, int $now, int $ttlSeconds = 3600): string
{
    $b64url = static function (string $s): string {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    };

    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claims = [
        'iss'   => (string)($key['client_email'] ?? ''),
        'scope' => 'https://www.googleapis.com/auth/spreadsheets',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + $ttlSeconds,
    ];

    $signingInput = $b64url((string)json_encode($header)) . '.' . $b64url((string)json_encode($claims));

    $privateKey = (string)($key['private_key'] ?? '');
    if ($privateKey === '' || !function_exists('openssl_sign')) {
        throw new RuntimeException('Google Sheets sync requires the openssl extension and a service-account private key.');
    }

    $signature = '';
    if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Could not sign the Google Sheets service-account JWT.');
    }

    return $signingInput . '.' . $b64url($signature);
}

/**
 * Perform an HTTP request against the Google APIs, preferring cURL and
 * falling back to allow_url_fopen. Returns [status, body].
 *
 * @return array{0:int,1:string}
 */
function googleSheetHttp(string $method, string $url, array $headers, string $body = ''): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => array_values($headers),
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new RuntimeException('Google Sheets request failed: ' . $error);
        }
        return [$status, (string)$response];
    }

    if (ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", array_values($headers)),
                'content'       => $body,
                'timeout'       => 60,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException('Google Sheets request failed: neither cURL nor allow_url_fopen is available.');
        }
        $status = 0;
        // PHP 8.4+ exposes the last response headers via a function; older
        // PHP populates the predefined $http_response_header variable.
        if (function_exists('http_get_last_response_headers')) {
            $lastHeaders = http_get_last_response_headers();
        } else {
            $lastHeaders = $http_response_header ?? null;
        }
        if (is_array($lastHeaders) && isset($lastHeaders[0]) && preg_match('#^HTTP/\S+\s+(\d+)#', $lastHeaders[0], $m)) {
            $status = (int)$m[1];
        }
        return [$status, $response];
    }

    throw new RuntimeException('Google Sheets sync needs cURL or allow_url_fopen enabled in PHP.');
}

/**
 * Exchange a signed JWT for an OAuth access token.
 */
function googleSheetFetchAccessToken(array $key, string $jwt): string
{
    [$status, $body] = googleSheetHttp(
        'POST',
        'https://oauth2.googleapis.com/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ])
    );

    $parsed = json_decode($body, true);
    if ($status !== 200 || !is_array($parsed) || empty($parsed['access_token'])) {
        throw new RuntimeException('Google OAuth token request failed (HTTP ' . $status . '): ' . substr($body, 0, 500));
    }
    return (string)$parsed['access_token'];
}

/**
 * Build the 2D value grid for the sheet: one header row, then one row per
 * item, mirroring the columns of the CSV/ZIP export.
 *
 * @param array $rows Rows from the intake_items query.
 * @return array<int, array<int, string>>
 */
function googleSheetBuildRows(array $rows): array
{
    $grid = [['SKU', 'Status', 'What is it?', 'eBay Category', 'Qty', 'Dispotech Price', 'eBay Price', 'Updated']];

    foreach ($rows as $row) {
        $category = trim((string)($row['ebay_category'] ?? ''));
        if ($category === '') {
            $category = trim((string)($row['ebay_category_path'] ?? ''));
        }
        $grid[] = [
            (string)($row['sku'] ?? ''),
            (string)($row['status'] ?? ''),
            (string)($row['what_is_it'] ?? ''),
            $category,
            (string)($row['quantity'] ?? 1),
            (string)($row['dispotech_price'] ?? ''),
            (string)($row['ebay_price'] ?? ''),
            (string)($row['updated_at'] ?? ''),
        ];
    }

    return $grid;
}

/**
 * CLI entry point. Returns the process exit code (0 = success / nothing to do).
 */
function googleSheetRun(): int
{
    $spreadsheetId = trim((string)getenv('GOOGLE_SPREADSHEET_ID'));
    $keyFile = trim((string)getenv('GOOGLE_SERVICE_ACCOUNT_FILE'));
    $tab = trim((string)getenv('GOOGLE_SHEET_TAB'));
    if ($tab === '') {
        $tab = 'Inventory';
    }

    if ($spreadsheetId === '' || $keyFile === '') {
        fwrite(STDERR, "[sync_google_sheet] not configured — set GOOGLE_SPREADSHEET_ID and GOOGLE_SERVICE_ACCOUNT_FILE in .env (see deploy/README.md).\n");
        return 0; // cron-safe: nothing to do is not an error
    }

    if (!function_exists('openssl_sign')) {
        fwrite(STDERR, "[sync_google_sheet] ERROR: the openssl PHP extension is required for Google OAuth signing.\n");
        return 1;
    }

    if (!is_readable($keyFile)) {
        fwrite(STDERR, "[sync_google_sheet] ERROR: service account key file is not readable: $keyFile\n");
        return 1;
    }

    $key = json_decode((string)file_get_contents($keyFile), true);
    if (!is_array($key) || empty($key['client_email']) || empty($key['private_key'])) {
        fwrite(STDERR, "[sync_google_sheet] ERROR: key file is not a valid Google service-account JSON document.\n");
        return 1;
    }

    $db = __DIR__ . '/../data/intake.sqlite';
    if (!is_readable($db)) {
        fwrite(STDERR, "[sync_google_sheet] ERROR: inventory database is not readable: $db\n");
        return 1;
    }

    try {
        $pdo = pdoConnect($db);

        // Self-heal the eBay category columns, matching the export endpoints.
        $cols = array_column($pdo->query('PRAGMA table_info(intake_items)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        foreach (['ebay_category' => 'TEXT', 'ebay_category_path' => 'TEXT', 'ebay_category_id' => 'TEXT'] as $col => $def) {
            if (!in_array($col, $cols, true)) {
                $pdo->exec("ALTER TABLE intake_items ADD COLUMN $col $def");
            }
        }

        $rows = $pdo->query(
            'SELECT sku, status, what_is_it, ebay_category, ebay_category_path, quantity, dispotech_price, ebay_price, updated_at
             FROM intake_items
             ORDER BY updated_at DESC, id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $grid = googleSheetBuildRows($rows);
        $token = googleSheetFetchAccessToken($key, googleSheetBuildJwt($key, time()));

        $baseUrl = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . '/values/';
        $authHeaders = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];

        // Clear the whole tab first so items removed from inventory disappear
        // from the sheet instead of lingering as stale rows.
        [$clearStatus, $clearBody] = googleSheetHttp(
            'POST',
            $baseUrl . rawurlencode($tab) . ':clear',
            $authHeaders,
            '{}'
        );
        if ($clearStatus !== 200) {
            throw new RuntimeException('Clearing the sheet tab failed (HTTP ' . $clearStatus . '): ' . substr($clearBody, 0, 500));
        }

        // Rewrite the whole grid. RAW input means values are stored as-is.
        $range = $tab . '!A1:H' . count($grid);
        [$updateStatus, $updateBody] = googleSheetHttp(
            'PUT',
            $baseUrl . rawurlencode($range) . '?valueInputOption=RAW',
            $authHeaders,
            (string)json_encode(['values' => $grid])
        );
        if ($updateStatus !== 200) {
            throw new RuntimeException('Updating the sheet tab failed (HTTP ' . $updateStatus . '): ' . substr($updateBody, 0, 500));
        }

        echo '[sync_google_sheet] OK: pushed ' . (count($grid) - 1) . " rows to tab \"$tab\".\n";
        return 0;
    } catch (Throwable $error) {
        fwrite(STDERR, '[sync_google_sheet] ERROR: ' . $error->getMessage() . "\n");
        return 1;
    }
}

/*
 * Only run the CLI flow when this file is executed directly. When it is
 * included from tests (or any other context), only the functions above are
 * defined — and config.php is deliberately not loaded in that case, so the
 * test suite never clashes with the app's constants.
 */
$googleSheetIsMain = (realpath($_SERVER['argv'][0] ?? '') ?: '') === realpath(__FILE__);
if ($googleSheetIsMain) {
    require_once __DIR__ . '/../config.php';
    exit(googleSheetRun());
}
