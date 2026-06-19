<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: text/html; charset=utf-8');

/* ── helpers ──────────────────────────────────────────────── */

function statusIcon(bool $ok, string $label): string
{
    if ($ok) {
        return '<span class="ok">&#10003; ' . htmlspecialchars($label) . '</span>';
    }
    return '<span class="fail">&#10007; ' . htmlspecialchars($label) . '</span>';
}

function statusWarn(string $label): string
{
    return '<span class="warn">&#9888; ' . htmlspecialchars($label) . '</span>';
}

function safeIni(string $key): string
{
    $v = ini_get($key);
    return $v !== false && $v !== '' ? $v : '<em>unset</em>';
}

/* ── collect diagnostics ─────────────────────────────────── */

$env = [];
$storage = [];
$db = [];
$pipeline = [];
$serverInfo = [];

/* 2a. PHP Environment */
try {
    $env['php_version'] = PHP_VERSION;
    $env['memory_limit'] = safeIni('memory_limit');
    $env['upload_max_filesize'] = safeIni('upload_max_filesize');
    $env['post_max_size'] = safeIni('post_max_size');
    $env['max_file_uploads'] = safeIni('max_file_uploads');
    $env['server_software'] = $_SERVER['SERVER_SOFTWARE'] ?? 'CLI / unknown';
} catch (Throwable $e) {
    $env['_error'] = $e->getMessage();
}

/* 2b. Directory & Storage Health */
$dirs = [
    'data/' => __DIR__ . '/data',
    'data/sku_photos/' => __DIR__ . '/data/sku_photos',
    'data/chunks/' => __DIR__ . '/data/chunks',
    'data/ebay_images/' => __DIR__ . '/data/ebay_images',
    'logs/' => __DIR__ . '/logs',
];

try {
    foreach ($dirs as $label => $path) {
        $entry = ['label' => $label, 'path' => $path];
        $entry['exists'] = is_dir($path);
        $entry['writable'] = is_dir($path) && is_writable($path);
        $entry['free_space'] = is_dir($path) ? disk_free_space($path) : null;
        $storage[] = $entry;
    }
} catch (Throwable $e) {
    $storage['_error'] = $e->getMessage();
}

/* intake.sqlite file info */
try {
    $dbPath = __DIR__ . '/data/intake.sqlite';
    $storageDb = ['label' => 'data/intake.sqlite', 'path' => $dbPath];
    $storageDb['exists'] = is_file($dbPath);
    $storageDb['size'] = is_file($dbPath) ? filesize($dbPath) : null;
    $storageDb['mtime'] = is_file($dbPath) ? filemtime($dbPath) : null;
} catch (Throwable $e) {
    $storageDb = ['_error' => $e->getMessage()];
}

/* 2c. SQLite Connection Test */
try {
    $pdo = pdoConnect(__DIR__ . '/data/intake.sqlite');
    $intakeCount = (int)$pdo->query('SELECT COUNT(*) FROM intake_items')->fetchColumn();
    $photoCount = (int)$pdo->query('SELECT COUNT(*) FROM sku_photos')->fetchColumn();
    $db['connected'] = true;
    $db['intake_items'] = $intakeCount;
    $db['sku_photos'] = $photoCount;
} catch (Throwable $e) {
    $db['connected'] = false;
    $db['error'] = $e->getMessage();
}

/* 2d. Image Pipeline Mock Test */
$pipelineSteps = [];

try {
    /* Step 1: create 1x1 PNG */
    $tmpDir = sys_get_temp_dir();
    $tmpFile = $tmpDir . '/' . bin2hex(random_bytes(8)) . '.png';
    $img = @imagecreatetruecolor(1, 1);
    if ($img === false) {
        throw new RuntimeException('imagecreatetruecolor() failed — GD may be missing');
    }
    $pngOk = @imagepng($img, $tmpFile);
    $pixelColor = @imagecolorallocate($img, 255, 0, 0);
    @imagesetpixel($img, 0, 0, $pixelColor !== false ? $pixelColor : 0);
    @imagedestroy($img);
    if (!$pngOk || !is_file($tmpFile)) {
        throw new RuntimeException('Failed to write temp PNG');
    }
    $pipelineSteps[] = ['name' => 'Create 1x1 PNG', 'ok' => true];

    /* Step 2: detectUploadMimeType */
    $detectedMime = detectUploadMimeType($tmpFile, 'test.png');
    $mimeOk = $detectedMime === 'image/png';
    $pipelineSteps[] = [
        'name' => 'detectUploadMimeType returns image/png',
        'ok' => $mimeOk,
        'detail' => $detectedMime,
    ];
    if (!$mimeOk) {
        throw new RuntimeException('MIME mismatch: expected image/png, got ' . $detectedMime);
    }

    /* Step 3: pipeline simulation (replicates processUploadedPhoto logic
     * without move_uploaded_file, which requires HTTP POST upload context) */
    $photoDir = defined('PHOTO_UPLOAD_DIR') ? PHOTO_UPLOAD_DIR : (__DIR__ . '/data/sku_photos');
    $skuDirName = normalizedSkuDirectory('ZZ-HEALTHCHECK');
    $skuDir = $photoDir . '/' . $skuDirName;

    $dirReady = is_dir($skuDir) || @mkdir($skuDir, 0777, true);
    if (!$dirReady) {
        throw new RuntimeException('Could not create SKU directory: ' . $skuDir);
    }

    /* MIME validation (same logic as processUploadedPhoto) */
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($detectedMime, $allowedMimes, true)) {
        throw new RuntimeException('MIME not in allowed list: ' . $detectedMime);
    }

    /* GD decode check (same defence as processUploadedPhoto) */
    $raw = @file_get_contents($tmpFile);
    if ($raw === false || $raw === '') {
        throw new RuntimeException('Failed to read temp file for GD check');
    }
    $gdCheck = @imagecreatefromstring($raw);
    if (!$gdCheck) {
        throw new RuntimeException('GD rejected the image (polyglot defence)');
    }
    imagedestroy($gdCheck);
    unset($raw);

    /* Write file to SKU directory using copy (move_uploaded_file only
     * works for real HTTP POST uploads, so we use copy+unlink instead) */
    $storedName = bin2hex(random_bytes(16)) . '.png';
    $destPath = $skuDir . '/' . $storedName;
    if (!@copy($tmpFile, $destPath)) {
        throw new RuntimeException('Failed to copy file to SKU directory');
    }
    @unlink($tmpFile);

    $pipelineSteps[] = [
        'name' => 'Pipeline write to disk',
        'ok' => true,
        'detail' => $skuDirName . '/' . $storedName,
    ];

    /* Step 4: clean up stored file */
    $cleanedFile = false;
    if (is_file($destPath)) {
        @unlink($destPath);
        $cleanedFile = true;
    }
    if (is_dir($skuDir) && count(@scandir($skuDir)) <= 2) {
        @rmdir($skuDir);
    }
    $pipelineSteps[] = [
        'name' => 'Clean up stored file',
        'ok' => $cleanedFile,
    ];

    /* Step 5: clean up DB records */
    if (isset($pdo)) {
        try {
            $del = $pdo->prepare('DELETE FROM sku_photos WHERE sku_normalized = ?');
            $del->execute([$skuDirName]);
        } catch (Throwable $dbErr) {
            // non-fatal
        }
        $pipelineSteps[] = [
            'name' => 'Clean up DB records (sku_photos)',
            'ok' => true,
        ];
    }
} catch (Throwable $e) {
    $pipelineSteps[] = ['name' => 'Pipeline error: ' . $e->getMessage(), 'ok' => false];
    @unlink($tmpFile ?? '');
}

$pipeline['steps'] = $pipelineSteps;

/* 2e. Server Timestamp */
try {
    $tz = date_default_timezone_get();
    $now = new DateTimeImmutable('now');
    $serverInfo['time'] = $now->format('Y-m-d H:i:s');
    $serverInfo['timezone'] = $tz;
    $serverInfo['uptime'] = null;
    if (PHP_OS_FAMILY === 'Windows') {
        $out = @shell_exec('systeminfo 2>NUL | findstr /C:"System Boot Time" /C:"Up Time"');
        if ($out !== null && $out !== '') {
            $serverInfo['uptime'] = trim($out);
        } else {
            $out2 = @shell_exec('powershell -NoProfile -Command "(Get-Date) - (Get-CimInstance Win32_OperatingSystem).LastBootUpTime | Format-Table -AutoSize" 2>NUL');
            if ($out2 !== null && $out2 !== '') {
                $serverInfo['uptime'] = trim($out2);
            }
        }
    } else {
        $uptime = @file_get_contents('/proc/uptime');
        if ($uptime !== false) {
            $secs = (int)floor((float)$uptime);
            $days = intdiv($secs, 86400);
            $hours = intdiv($secs % 86400, 3600);
            $mins = intdiv($secs % 3600, 60);
            $serverInfo['uptime'] = "{$days}d {$hours}h {$mins}m";
        }
    }
} catch (Throwable $e) {
    $serverInfo['_error'] = $e->getMessage();
}

/* ── render HTML ──────────────────────────────────────────── */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pinksheet — Health Dashboard</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen,Ubuntu,sans-serif;background:#f4f5f7;color:#1a1a2e;line-height:1.6;padding:2rem 1rem}
.container{max-width:960px;margin:0 auto}
h1{font-size:1.75rem;margin-bottom:.25rem;display:flex;align-items:center;gap:.5rem}
h1 small{font-size:.85rem;font-weight:400;color:#666}
.subtitle{color:#555;margin-bottom:2rem}
.card{background:#fff;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.1);padding:1.25rem 1.5rem;margin-bottom:1.25rem}
.card h2{font-size:1.15rem;margin-bottom:.85rem;padding-bottom:.45rem;border-bottom:1px solid #e9ecef}
table{width:100%;border-collapse:collapse;font-size:.92rem}
th,td{text-align:left;padding:.5rem .4rem;border-bottom:1px solid #f0f0f0}
th{font-weight:600;color:#555;width:34%}
td{font-family:ui-monospace,SFMono-Regular,'Cascadia Code','Fira Code',Consolas,monospace;word-break:break-all}
tr:last-child td,tr:last-child th{border-bottom:none}
.ok{color:#1b7a2b}
.fail{color:#c12b2b}
.warn{color:#9e6a03}
.icon{font-size:1.1rem;margin-right:.3rem}
.step-row{display:flex;align-items:baseline;gap:.6rem;padding:.35rem 0;border-bottom:1px solid #f0f0f0}
.step-row:last-child{border-bottom:none}
.step-name{flex:1}
.step-detail{color:#777;font-size:.82rem;font-family:ui-monospace,SFMono-Regular,Consolas,monospace}
.free-space{color:#555;font-size:.82rem}
@media(max-width:600px){body{padding:1rem .5rem}.card{padding:1rem}}
</style>
</head>
<body>
<div class="container">

<h1>&#9881; Pinksheet <small>Health Dashboard</small></h1>
<p class="subtitle">Diagnostic report generated at <?= date('Y-m-d H:i:s') ?></p>

<!-- 2a. PHP Environment -->
<div class="card">
<h2>&#8203;PHP Environment</h2>
<table>
<?php if (isset($env['_error'])): ?>
<tr><td colspan="2" class="fail"><?= htmlspecialchars($env['_error']) ?></td></tr>
<?php else: ?>
<tr><th>PHP Version</th><td><?= htmlspecialchars($env['php_version']) ?></td></tr>
<tr><th>memory_limit</th><td><?= htmlspecialchars($env['memory_limit']) ?></td></tr>
<tr><th>upload_max_filesize</th><td><?= htmlspecialchars($env['upload_max_filesize']) ?></td></tr>
<tr><th>post_max_size</th><td><?= htmlspecialchars($env['post_max_size']) ?></td></tr>
<tr><th>max_file_uploads</th><td><?= htmlspecialchars($env['max_file_uploads']) ?></td></tr>
<tr><th>Server Software</th><td><?= htmlspecialchars($env['server_software']) ?></td></tr>
<?php endif; ?>
</table>
</div>

<!-- 2b. Directory & Storage Health -->
<div class="card">
<h2>&#8203;Directory &amp; Storage Health</h2>
<table>
<?php foreach ($storage as $entry): ?>
<?php if (isset($entry['label'])): ?>
<tr>
<th><?= htmlspecialchars($entry['label']) ?></th>
<td>
<?php if (!$entry['exists']): ?>
<?= statusWarn('does not exist') ?>
<?php elseif (!$entry['writable']): ?>
<?= statusIcon(false, 'not writable') ?>
<?php else: ?>
<?= statusIcon(true, 'writable') ?>
<?php endif; ?>
<?php if ($entry['free_space'] !== null): ?>
<div class="free-space">Free: <?= htmlspecialchars(humanBytes((int)$entry['free_space'])) ?></div>
<?php endif; ?>
</td>
</tr>
<?php endif; ?>
<?php endforeach; ?>
<?php if (isset($storageDb)): ?>
<tr>
<th>data/intake.sqlite</th>
<td>
<?php if (!$storageDb['exists']): ?>
<?= statusIcon(false, 'not found') ?>
<?php else: ?>
<?= statusIcon(true, 'exists') ?>
<div class="free-space">Size: <?= htmlspecialchars(humanBytes((int)$storageDb['size'])) ?> &middot; Modified: <?= $storageDb['mtime'] ? date('Y-m-d H:i:s', $storageDb['mtime']) : '?' ?></div>
<?php endif; ?>
</td>
</tr>
<?php endif; ?>
<?php if (isset($storage['_error'])): ?>
<tr><td colspan="2" class="fail"><?= htmlspecialchars($storage['_error']) ?></td></tr>
<?php endif; ?>
</table>
</div>

<!-- 2c. SQLite Connection Test -->
<div class="card">
<h2>&#8203;SQLite Connection Test</h2>
<table>
<?php if ($db['connected'] ?? false): ?>
<tr><th>Connection</th><td><?= statusIcon(true, 'connected') ?></td></tr>
<tr><th>intake_items count</th><td><?= htmlspecialchars((string)($db['intake_items'] ?? '?')) ?></td></tr>
<tr><th>sku_photos count</th><td><?= htmlspecialchars((string)($db['sku_photos'] ?? '?')) ?></td></tr>
<?php else: ?>
<tr><th>Connection</th><td><?= statusIcon(false, 'failed') ?></td></tr>
<tr><td colspan="2" class="fail"><?= htmlspecialchars($db['error'] ?? 'unknown error') ?></td></tr>
<?php endif; ?>
</table>
</div>

<!-- 2d. Image Pipeline Mock Test -->
<div class="card">
<h2>&#8203;Image Pipeline Mock Test</h2>
<p style="color:#555;margin-bottom:.65rem;font-size:.88rem">SKU: <code>ZZ-HEALTHCHECK</code></p>
<div>
<?php if (empty($pipeline['steps'])): ?>
<p class="fail">No pipeline steps executed.</p>
<?php else: ?>
<?php foreach ($pipeline['steps'] as $step): ?>
<div class="step-row">
<span class="icon"><?= $step['ok'] ? '<span class="ok">&#10003;</span>' : '<span class="fail">&#10007;</span>' ?></span>
<span class="step-name"><?= htmlspecialchars($step['name']) ?></span>
<?php if (isset($step['detail'])): ?>
<span class="step-detail">(<?= htmlspecialchars($step['detail']) ?>)</span>
<?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
</div>

<!-- 2e. Server Timestamp -->
<div class="card">
<h2>&#8203;Server Timestamp</h2>
<table>
<?php if (isset($serverInfo['_error'])): ?>
<tr><td colspan="2" class="fail"><?= htmlspecialchars($serverInfo['_error']) ?></td></tr>
<?php else: ?>
<tr><th>Server Time</th><td><?= htmlspecialchars($serverInfo['time'] ?? '?') ?></td></tr>
<tr><th>Timezone</th><td><?= htmlspecialchars($serverInfo['timezone'] ?? '?') ?></td></tr>
<tr><th>Uptime</th><td><?= $serverInfo['uptime'] !== null ? htmlspecialchars($serverInfo['uptime']) : '<em>unavailable</em>' ?></td></tr>
<?php endif; ?>
</table>
</div>

</div>
</body>
</html>
