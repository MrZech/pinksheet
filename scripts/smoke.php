<?php
// Lightweight smoke test for local dev. Requires a running dev server:
// php -S 127.0.0.1:8765 -t .

declare(strict_types=1);

$base = 'http://127.0.0.1:8765';
$tests = [
    [
        'name' => 'health',
        'url' => $base . '/health.php',
    ],
    [
        'name' => 'home page',
        'url' => $base . '/home.php',
    ],
    [
        'name' => 'lookup page',
        'url' => $base . '/lookup.php',
    ],
    [
        'name' => 'intake page',
        'url' => $base . '/intake.php',
    ],
    [
        'name' => 'prompt page',
        'url' => $base . '/prompt_builder.php',
    ],
    [
        'name' => 'lookup preview (sku=TEST)',
        'url' => $base . '/lookup_preview.php?sku=TEST&limit=3',
    ],
    [
        'name' => 'autosave POST',
        'url' => $base . '/autosave.php',
        'method' => 'POST',
        'body' => json_encode([
            'sku' => 'SMOKE123',
            'data' => ['sku' => 'SMOKE123', 'what_is_it' => 'Smoke Laptop'],
            'version' => 0,
        ], JSON_THROW_ON_ERROR),
        'headers' => [
            'Content-Type: application/json',
        ],
    ],
];

$ok = true;
foreach ($tests as $test) {
    $method = $test['method'] ?? 'GET';
    $headers = $test['headers'] ?? [];
    $body = $test['body'] ?? null;
    $contextOpts = [
        'http' => [
            'method' => $method,
            'ignore_errors' => true,
            'timeout' => 5,
            'header' => $headers,
            'content' => $body,
        ],
    ];
    $context = stream_context_create($contextOpts);
    $resp = @file_get_contents($test['url'], false, $context);
    $statusLine = $http_response_header[0] ?? 'HTTP/0.0 000';
    [$httpVer, $statusCode] = array_pad(explode(' ', $statusLine, 3), 2, '000');
    $statusCode = (int)$statusCode;
    $pass = $statusCode >= 200 && $statusCode < 400;
    $ok = $ok && $pass;
    echo '[' . ($pass ? 'OK' : 'FAIL') . '] ' . $test['name'] . ' -> ' . $statusCode . PHP_EOL;
    if (!$pass) {
        echo "  URL: {$test['url']}" . PHP_EOL;
        if ($resp) {
            echo "  Body: " . substr($resp, 0, 280) . PHP_EOL;
        }
    }
}

// Optional photo upload test using curl extension
if (function_exists('curl_init')) {
    $tmpPng = tempnam(sys_get_temp_dir(), 'smoke_png_');
    // 1x1 transparent PNG
    $pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/xcAAn0B9WgKsPkAAAAASUVORK5CYII=');
    file_put_contents($tmpPng, $pngData);
    $sku = 'SMOKEPHOTO';
    $ch = curl_init($base . '/upload_photo.php');
    $post = [
        'sku' => $sku,
        'photo' => new CURLFile($tmpPng, 'image/png', 'smoke.png'),
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_TIMEOUT => 8,
    ]);
    $respBody = curl_exec($ch);
    $err = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $pass = !$err && $status >= 200 && $status < 400 && strpos((string)$respBody, '"status":"ok"') !== false;
    $ok = $ok && $pass;
    echo '[' . ($pass ? 'OK' : 'FAIL') . '] photo upload' . PHP_EOL;
    if (!$pass) {
        echo '  Status: ' . $status . PHP_EOL;
        echo '  Error: ' . $err . PHP_EOL;
        if ($respBody) {
            echo '  Body: ' . substr($respBody, 0, 280) . PHP_EOL;
        }
    }
    @unlink($tmpPng);
} else {
    echo '[SKIP] photo upload (curl extension not available)' . PHP_EOL;
}

exit($ok ? 0 : 1);
