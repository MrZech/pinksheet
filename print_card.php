<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$sku = trim((string)($_GET['sku'] ?? ''));
if ($sku === '') {
    http_response_code(400);
    echo 'Missing SKU parameter';
    exit;
}

try {
    $pdo = pdoConnect(__DIR__ . '/data/intake.sqlite');
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Database error';
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, sku, sku_normalized, status, what_is_it, notes, updated_at,
           dispotech_price, reviewed, ram, ssd_gb, cpu, os, battery_health,
           graphics_card, screen_resolution, date_received, source
    FROM intake_items
    WHERE sku = ? OR sku_normalized = ?
    ORDER BY updated_at DESC
    LIMIT 1
");
$stmt->execute([$sku, strtoupper($sku)]);
$item = $stmt->fetch();

if (!$item) {
    http_response_code(404);
    echo 'Item not found';
    exit;
}

$stmt = $pdo->prepare("
    SELECT id FROM sku_photos
    WHERE sku_normalized = ?
    ORDER BY is_thumb DESC, id ASC
    LIMIT 4
");
$stmt->execute([strtoupper($sku)]);
$photos = $stmt->fetchAll();

$laneStatus = htmlspecialchars((string)($item['status'] ?? '') !== '' ? statusLabel((string)$item['status']) : 'Intake', ENT_QUOTES, 'UTF-8');
$reviewedVal = (int)($item['reviewed'] ?? 0);
if ($reviewedVal === 2) {
    $pillClass = 'sold';
    $pillText = 'SOLD';
} elseif ($reviewedVal === 1) {
    $pillClass = 'active';
    $pillText = 'ACTIVE';
} else {
    $pillClass = '';
    $pillText = 'INACTIVE';
}
$whatIsIt = htmlspecialchars($item['what_is_it'] ?? '', ENT_QUOTES, 'UTF-8');
$notes = htmlspecialchars($item['notes'] ?? '', ENT_QUOTES, 'UTF-8');
$updatedAt = htmlspecialchars($item['updated_at'] ?? '', ENT_QUOTES, 'UTF-8');
$price = isset($item['dispotech_price']) && $item['dispotech_price'] !== ''
    ? '$' . number_format((float)$item['dispotech_price'], 2)
    : '';
$displaySku = htmlspecialchars($item['sku'] ?? $sku, ENT_QUOTES, 'UTF-8');
$photoCount = count($photos);

$ram = htmlspecialchars($item['ram'] ?? '', ENT_QUOTES, 'UTF-8');
$ssdGb = htmlspecialchars($item['ssd_gb'] ?? '', ENT_QUOTES, 'UTF-8');
$cpu = htmlspecialchars($item['cpu'] ?? '', ENT_QUOTES, 'UTF-8');
$os = htmlspecialchars($item['os'] ?? '', ENT_QUOTES, 'UTF-8');
$batteryHealth = htmlspecialchars($item['battery_health'] ?? '', ENT_QUOTES, 'UTF-8');
$graphicsCard = htmlspecialchars($item['graphics_card'] ?? '', ENT_QUOTES, 'UTF-8');
$screenRes = htmlspecialchars($item['screen_resolution'] ?? '', ENT_QUOTES, 'UTF-8');
$dateReceived = htmlspecialchars($item['date_received'] ?? '', ENT_QUOTES, 'UTF-8');
$source = htmlspecialchars($item['source'] ?? '', ENT_QUOTES, 'UTF-8');

$thumbId = $photoCount > 0 ? (int)$photos[0]['id'] : 0;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Print - <?= $displaySku ?></title>
  <base href="<?= rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/' ?>">
  <script src="assets/qrcode.min.js?v=<?php echo getAssetVersion(); ?>"></script>
  <style>
    * {
      box-sizing: border-box;
      box-shadow: none !important;
      text-shadow: none !important;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }

    :root {
      --ink: #111;
      --muted: #555;
      --line: #999;
      --accent: #111;
      --border-color: #999;
      --border-strong: #111;
    }

    html, body {
      width: 100%;
      height: 100%;
      margin: 0;
      padding: 0;
      background: #fff;
      color: #111;
      font-family: "Inter", "Segoe UI", Arial, sans-serif;
      font-size: 9pt;
      line-height: 1.25;
    }

    .print-sheet {
      height: 100vh;
      max-height: 100vh;
      display: flex;
      flex-direction: column;
      padding: 0.4in;
      overflow: hidden;
    }

    /* ── Header Row: metadata + QR ──────────────────────────── */
    .print-header-row {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      width: 100%;
      padding-bottom: 8pt;
      margin-bottom: 10pt;
      border-bottom: 1.5pt solid var(--border-strong);
      flex-shrink: 0;
    }

    .header-left-block {
      display: flex;
      gap: 12pt;
      align-items: flex-start;
    }

    .metadata-block {
      display: flex;
      flex-direction: column;
      gap: 1pt;
    }

    .metadata-line {
      font-size: 10pt;
      font-weight: 600;
      color: var(--ink);
    }

    .metadata-line strong {
      font-weight: 800;
      color: var(--muted);
      text-transform: uppercase;
      font-size: 7pt;
      letter-spacing: 0.06em;
      display: inline-block;
      min-width: 36pt;
    }

    .top-thumbnail-img img {
      width: 80px;
      height: auto;
      max-height: 110px;
      object-fit: contain;
      border: 0.5pt solid var(--border-color);
      border-radius: 4px;
      background: #fafafa;
    }

    .header-qr-block {
      flex: 0 0 auto;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .print-qr-code {
      width: 80px;
      height: 80px;
      border: 0.5pt solid #d1d5db;
      border-radius: 4px;
      overflow: hidden;
      background: #ffffff;
    }

    .print-qr-code canvas,
    .print-qr-code img {
      width: 80px !important;
      height: 80px !important;
      display: block;
    }

    /* ── Intake Fields Grid (4 columns) ─────────────────────── */
    .intake-fields-row {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 8pt;
      width: 100%;
      margin-bottom: 8pt;
      flex-shrink: 0;
    }

    .field-block {
      display: flex;
      flex-direction: column;
    }

    .field-label {
      font-size: 6.5pt;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--muted);
      padding-bottom: 3pt;
      margin-bottom: 3pt;
      border-bottom: 0.5pt solid var(--border-color);
    }

    .field-value {
      font-size: 8.5pt;
      font-weight: 500;
      color: var(--ink);
      padding: 4pt 6pt;
      border: 0.5pt solid #b8c2d0;
      border-radius: 4px;
      background: #fcfcfc;
      min-height: 20pt;
      display: flex;
      align-items: center;
    }

    /* ── Hardware Specs Grid (3 columns) ────────────────────── */
    .hardware-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 8pt;
      width: 100%;
      margin-bottom: 8pt;
      flex-shrink: 0;
    }

    .spec-block {
      display: flex;
      flex-direction: column;
    }

    /* ── Notes Section ──────────────────────────────────────── */
    .notes-section {
      width: 100%;
      margin-bottom: 6pt;
      flex-shrink: 0;
    }

    .notes-box {
      width: 100%;
      min-height: 56pt;
      padding: 8pt;
      border: 0.5pt solid #b8c2d0;
      border-radius: 4px;
      font-size: 8.5pt;
      line-height: 1.4;
      color: var(--ink);
      background: #fcfcfc;
    }

    /* ── Photos Section ─────────────────────────────────────── */
    .print-photos-section {
      flex: 1;
      min-height: 0;
      display: flex;
      flex-direction: column;
    }

    .additional-photos-grid {
      display: grid;
      grid-template-columns: repeat(<?= min($photoCount, 4) ?>, 1fr);
      gap: 6px;
      width: 100%;
    }

    .additional-photos-grid img {
      width: 100%;
      max-height: 90px;
      object-fit: contain;
      border: 0.5pt solid var(--border-color);
      border-radius: 4px;
      background: #fafafa;
    }

    /* ── Footer ─────────────────────────────────────────────── */
    .print-footer {
      margin-top: auto;
      padding-top: 4pt;
      border-top: 0.5pt solid #ddd;
      font-size: 6pt;
      color: #bbb;
      text-align: center;
      letter-spacing: 0.06em;
      flex-shrink: 0;
    }

    .print-pill {
      display: inline-block;
      padding: 2pt 10pt;
      border-radius: 4px;
      font-size: 7pt;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      background: #eee;
      border: 0.5pt solid var(--border-color);
      color: var(--muted);
    }

    .print-pill.active {
      background: rgba(54, 173, 163, 0.18);
      border-color: #36ada3;
      color: #2a8a82;
    }

    .print-pill.sold {
      background: rgba(3, 105, 161, 0.18);
      border-color: #0369a1;
      color: #0369a1;
    }

    @media print {
      html, body {
        height: 100vh !important;
        max-height: 100vh !important;
        margin: 0 !important;
        padding: 0 !important;
        overflow: hidden !important;
        background: #ffffff !important;
        color: #000000 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
      }

      .print-sheet {
        height: 100vh !important;
        max-height: 100vh !important;
        padding: 0.4in !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
        page-break-after: avoid !important;
        page-break-before: avoid !important;
        display: flex !important;
        flex-direction: column !important;
      }

      .field-value,
      .notes-box,
      .top-thumbnail-img img,
      .additional-photos-grid img,
      .print-qr-code,
      .print-pill {
        border-radius: 4px !important;
      }

      nav, .sidebar, .kanban-board-header, .action-buttons,
      #print-trigger-btn, .browser-ui-elements {
        display: none !important;
      }
    }
  </style>
</head>
<body>
  <div class="print-sheet">
    <!-- ═══ Header Row: metadata + QR ═══ -->
    <div class="print-header-row">
      <div class="header-left-block">
        <?php if ($thumbId > 0): ?>
          <div class="top-thumbnail-img">
            <img src="photo.php?id=<?= $thumbId ?>" alt="" loading="eager">
          </div>
        <?php endif; ?>
        <div class="metadata-block">
          <div class="metadata-line"><strong>SKU</strong> <?= $displaySku ?></div>
          <div class="metadata-line">
            <strong>Status</strong>
            <span class="print-pill<?= $pillClass !== '' ? ' ' . $pillClass : '' ?>"><?= $pillText ?></span>
          </div>
          <?php if ($price !== ''): ?>
            <div class="metadata-line"><strong>Price</strong> <?= $price ?></div>
          <?php endif; ?>
          <div class="metadata-line"><strong>Updated</strong> <?= $updatedAt ?></div>
        </div>
      </div>
      <div class="header-qr-block">
        <div class="print-qr-code" id="print-qr-code"
             data-url="<?php
                 $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                     || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                     || (isset($_SERVER['HTTP_CF_VISITOR']) && str_contains($_SERVER['HTTP_CF_VISITOR'], '"scheme":"https"'));
                 $protocol = $isHttps ? 'https' : 'http';
                 $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                 echo htmlspecialchars($protocol . '://' . $host . '/card.php?sku=' . urlencode($displaySku), ENT_QUOTES, 'UTF-8');
             ?>"></div>
      </div>
    </div>

    <!-- ═══ Intake Fields Row (4 columns) ═══ -->
    <div class="intake-fields-row">
      <div class="field-block">
        <div class="field-label">SKU</div>
        <div class="field-value"><?= $displaySku ?></div>
      </div>
      <div class="field-block">
        <div class="field-label">WHAT IS IT?</div>
        <div class="field-value"><?= $whatIsIt ?></div>
      </div>
      <div class="field-block">
        <div class="field-label">DATE RECEIVED</div>
        <div class="field-value"><?= $dateReceived ?></div>
      </div>
      <div class="field-block">
        <div class="field-label">WHERE DID IT COME FROM?</div>
        <div class="field-value"><?= $source ?></div>
      </div>
    </div>

    <!-- ═══ Hardware Specs Grid (3 columns) ═══ -->
    <div class="hardware-grid">
      <div class="spec-block">
        <div class="field-label">RAM</div>
        <div class="field-value"><?= $ram ?></div>
      </div>
      <div class="spec-block">
        <div class="field-label">SSD GB</div>
        <div class="field-value"><?= $ssdGb ?></div>
      </div>
      <div class="spec-block">
        <div class="field-label">CPU</div>
        <div class="field-value"><?= $cpu ?></div>
      </div>
      <div class="spec-block">
        <div class="field-label">OS</div>
        <div class="field-value"><?= $os ?></div>
      </div>
      <div class="spec-block">
        <div class="field-label">BATTERY HEALTH</div>
        <div class="field-value"><?= $batteryHealth ?></div>
      </div>
      <div class="spec-block">
        <div class="field-label">GRAPHICS CARD</div>
        <div class="field-value"><?= $graphicsCard ?></div>
      </div>
      <div class="spec-block">
        <div class="field-label">SCREEN RESOLUTION</div>
        <div class="field-value"><?= $screenRes ?></div>
      </div>
    </div>

    <!-- ═══ Notes ═══ -->
    <?php if ($notes !== ''): ?>
    <div class="notes-section">
      <div class="field-label" style="margin-bottom: 4px;">NOTES</div>
      <div class="notes-box"><?= nl2br($notes) ?></div>
    </div>
    <?php endif; ?>

    <!-- ═══ Photos ═══ -->
    <?php if ($photoCount > 1): ?>
    <div class="print-photos-section">
      <div class="field-label" style="margin-bottom: 4px;">PHOTOS (<?= $photoCount ?>)</div>
      <div class="additional-photos-grid">
        <?php for ($i = 1; $i < $photoCount; $i++): ?>
          <img src="photo.php?id=<?= (int)$photos[$i]['id'] ?>" alt="" loading="lazy">
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="print-footer">Dispo.Tech — Card Print</div>
  </div>
  <script>
    (function () {
      var qrEl = document.getElementById('print-qr-code');
      if (qrEl && typeof QRCode !== 'undefined') {
        var url = qrEl.getAttribute('data-url');
        if (url) {
          try {
            new QRCode(qrEl, {
              text: url,
              width: 80,
              height: 80,
              colorDark: '#111111',
              colorLight: '#ffffff',
              correctLevel: QRCode.CorrectLevel.H
            });
          } catch (e) {}
        }
      }
    })();
  </script>
</body>
</html>
