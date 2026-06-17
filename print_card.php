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
    $pdo = new PDO('sqlite:' . __DIR__ . '/data/intake.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Database error';
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, sku, sku_normalized, status, what_is_it, notes, updated_at, dispotech_price, reviewed
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

$laneStatus = htmlspecialchars($item['status'] ?? 'Intake', ENT_QUOTES, 'UTF-8');
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
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Print - <?= $displaySku ?></title>
  <base href="<?= rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/' ?>">
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
      font-size: 10pt;
      line-height: 1.3;
    }

    .print-card-container {
      height: 100vh;
      max-height: 100vh;
      display: flex;
      flex-direction: column;
      justify-content: flex-start;
      padding: 20px;
      overflow: hidden;
    }

    .print-card-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      padding-bottom: 8pt;
      margin-bottom: 10pt;
      border-bottom: 1.5pt solid #111;
      flex-shrink: 0;
    }

    .print-card-header h1 {
      font-size: 18pt;
      font-weight: 800;
      margin: 0;
      letter-spacing: -0.02em;
    }

    .print-card-status {
      font-size: 9pt;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: #555;
    }

    .print-card-body {
      display: flex;
      flex-direction: column;
      gap: 6pt;
      flex: 1;
      min-height: 0;
    }

    .print-card-field {
      border: 0.5pt solid #ccc;
      border-top: 1.5pt solid #111;
      padding: 6pt 8pt;
      border-radius: 4px;
    }

    .print-card-field h2 {
      font-size: 6.5pt;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      color: #555;
      margin: 0 0 4pt;
      padding: 0;
      border: none;
    }

    .print-card-field .value {
      font-size: 10pt;
      font-weight: 500;
      color: #111;
      line-height: 1.4;
    }

    .print-card-meta-row {
      display: flex;
      gap: 8pt;
      flex-shrink: 0;
    }

    .print-card-meta-row .print-card-field {
      flex: 1;
      min-width: 0;
    }

    .print-card-pill {
      display: inline-block;
      padding: 4pt 16pt;
      border-radius: 4px;
      font-size: 8pt;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      background: #eee;
      border: 1pt solid #999;
      color: #555;
    }

    .print-card-pill.active {
      background: rgba(54, 173, 163, 0.18);
      border-color: #36ada3;
      color: #2a8a82;
    }

    .print-card-pill.sold {
      background: rgba(3, 105, 161, 0.18);
      border-color: #0369a1;
      color: #0369a1;
    }

    .print-photo-grid {
      display: grid;
      grid-template-columns: repeat(<?= min($photoCount, 4) ?>, 1fr);
      gap: 6px;
      width: 100%;
    }

    .print-photo-grid img {
      width: 100%;
      max-height: 120px;
      object-fit: contain;
      border: 0.5pt solid #ccc;
      border-radius: 4px;
      background: #fafafa;
    }

    .print-card-footer {
      margin-top: 6pt;
      padding-top: 4pt;
      border-top: 0.5pt solid #ddd;
      font-size: 6pt;
      color: #bbb;
      text-align: center;
      letter-spacing: 0.06em;
      flex-shrink: 0;
    }

    .notes-box {
      border-radius: 4px;
      border: 1px solid #b8c2d0;
      padding: 10px;
    }

    @media print {
      html, body {
        height: 100vh !important;
        max-height: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        overflow: hidden !important;
        background: #ffffff !important;
        color: #000000 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
      }

      .print-card-container {
        height: 100vh !important;
        max-height: 100vh !important;
        display: flex !important;
        flex-direction: column !important;
        justify-content: flex-start !important;
        padding: 20px !important;
        overflow: hidden !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
        page-break-after: avoid !important;
        page-break-before: avoid !important;
      }

      .print-card-body {
        flex: 1 !important;
        min-height: 0 !important;
      }

      .print-photo-grid {
        display: grid !important;
        grid-template-columns: repeat(<?= min($photoCount, 4) ?>, 1fr) !important;
        gap: 6px !important;
        width: 100% !important;
      }

      .print-photo-grid img {
        max-height: 120px !important;
        width: auto !important;
        object-fit: contain !important;
        margin-bottom: 0 !important;
      }

      .print-card-field,
      .notes-box,
      textarea,
      input[type="text"],
      input[type="number"],
      select {
        border-radius: 4px !important;
        border: 1px solid #b8c2d0 !important;
        padding: 10px !important;
      }

      .print-card-pill {
        border-radius: 4px !important;
      }

      nav, .sidebar, .kanban-board-header, .action-buttons, #print-trigger-btn {
        display: none !important;
      }
    }
  </style>
</head>
<body>
  <div class="print-card-container">
    <div class="print-card-header">
      <h1><?= $displaySku ?></h1>
      <div class="print-card-status"><?= $laneStatus ?></div>
    </div>

    <div class="print-card-body">
      <?php if ($whatIsIt !== ''): ?>
      <div class="print-card-field">
        <h2>Description</h2>
        <div class="value"><?= $whatIsIt ?></div>
      </div>
      <?php endif; ?>

      <div class="print-card-meta-row">
        <div class="print-card-field">
          <h2>Status</h2>
          <span class="print-card-pill<?= $pillClass !== '' ? ' ' . $pillClass : '' ?>"><?= $pillText ?></span>
        </div>

        <?php if ($price !== ''): ?>
        <div class="print-card-field">
          <h2>Price</h2>
          <div class="value"><?= $price ?></div>
        </div>
        <?php endif; ?>

        <div class="print-card-field">
          <h2>Updated</h2>
          <div class="value"><?= $updatedAt ?></div>
        </div>
      </div>

      <?php if ($notes !== ''): ?>
      <div class="print-card-field">
        <h2>Notes</h2>
        <div class="value notes-box"><?= nl2br($notes) ?></div>
      </div>
      <?php endif; ?>

      <?php if ($photoCount > 0): ?>
      <div class="print-card-field">
        <h2>Photos (<?= $photoCount ?>)</h2>
        <div class="print-photo-grid">
          <?php foreach ($photos as $photo): ?>
            <img src="photo.php?id=<?= (int)$photo['id'] ?>" alt="" loading="eager">
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <div class="print-card-footer">Dispo.Tech — Card Print</div>
  </div>
</body>
</html>
