<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance();
ensureStorageWritable();

$pdo = new PDO('sqlite:' . __DIR__ . '/data/intake.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Ensure thumbnail column exists.
try {
    $pdo->exec("ALTER TABLE sku_photos ADD COLUMN is_thumb INTEGER NOT NULL DEFAULT 0");
} catch (Throwable $e) {
    // ignore
}

$lanes = ['Intake', 'Description', 'Tested', 'Listed', 'SOLD'];
$cards = [];
$thumbs = [];
$items = $pdo->query("
    SELECT id, sku, status, what_is_it, updated_at, dispotech_price
    FROM intake_items
    WHERE sku IS NOT NULL AND TRIM(sku) <> ''
    ORDER BY updated_at DESC, id DESC
")->fetchAll();

$skus = array_unique(array_filter(array_map(static fn($r) => strtoupper(trim((string)($r['sku'] ?? ''))), $items)));
if ($skus) {
    $placeholders = implode(',', array_fill(0, count($skus), '?'));
    $stmt = $pdo->prepare("
        SELECT sku_normalized, id
        FROM sku_photos
        WHERE sku_normalized IN ($placeholders)
        ORDER BY is_thumb DESC, id DESC
    ");
    $stmt->execute($skus);
    foreach ($stmt->fetchAll() as $row) {
        $norm = trim((string)$row['sku_normalized']);
        if ($norm && !isset($thumbs[$norm])) {
            $thumbs[$norm] = (int)$row['id'];
        }
    }
}

foreach ($items as $item) {
    $status = $item['status'] ?? '';
    if (!in_array($status, $lanes, true)) {
        $status = 'Intake';
    }
    $cards[$status][] = $item;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kanban · Pinksheet</title>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    .kanban-board { display:flex; gap:12px; overflow-x:auto; padding:12px; }
    .kanban-lane { background: rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.1); border-radius:8px; min-width:240px; max-width:280px; padding:10px; }
    .kanban-lane h3 { margin:0 0 6px 0; display:flex; justify-content:space-between; align-items:center; }
    .kanban-count { font-size:12px; opacity:0.7; }
    .kanban-card { background:#1f2a36; border-radius:8px; padding:8px; margin-bottom:8px; cursor:grab; border:1px solid rgba(255,255,255,0.08); }
    body:not(.dark-mode) .kanban-card { background:#f7f8fa; }
    .kanban-card .sku { font-weight:700; }
    .kanban-card .what { font-size:13px; opacity:0.8; }
    .kanban-card .meta { font-size:12px; opacity:0.7; display:flex; gap:8px; }
    .kanban-card img { width:100%; max-height:120px; object-fit:cover; border-radius:6px; margin-top:6px; }
    .lane-drop { border:2px dashed #6ca0ff; border-radius:8px; padding:6px; margin-bottom:6px; opacity:0.6; display:none; }
  </style>
</head>
<body class="home">
  <main class="page">
    <header class="sheet-header">
      <div class="updated">Pinksheet Kanban</div>
      <div class="sheet-header-right">
        <a class="button-link" href="home.php">Home</a>
      </div>
    </header>
    <h1>Status Board</h1>
    <p class="lead">Drag cards to update status; inline updates save immediately.</p>
    <div class="kanban-board" id="kanban-board">
      <?php foreach ($lanes as $lane): $list = $cards[$lane] ?? []; ?>
        <div class="kanban-lane" data-status="<?php echo htmlspecialchars($lane, ENT_QUOTES, 'UTF-8'); ?>">
          <h3><?php echo htmlspecialchars($lane, ENT_QUOTES, 'UTF-8'); ?> <span class="kanban-count"><?php echo count($list); ?></span></h3>
          <div class="lane-drop"></div>
          <?php foreach ($list as $card):
              $sku = trim((string)($card['sku'] ?? ''));
              $norm = strtoupper($sku);
              $thumb = $thumbs[$norm] ?? null;
          ?>
            <div class="kanban-card" draggable="true" data-sku="<?php echo htmlspecialchars($sku, ENT_QUOTES, 'UTF-8'); ?>">
              <div class="sku"><?php echo htmlspecialchars($sku, ENT_QUOTES, 'UTF-8'); ?></div>
              <div class="what"><?php echo htmlspecialchars($card['what_is_it'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
              <div class="meta">
                <span><?php echo htmlspecialchars($card['updated_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if (isset($card['dispotech_price']) && $card['dispotech_price'] !== ''): ?>
                  <span>$<?php echo number_format((float)$card['dispotech_price'], 2); ?></span>
                <?php endif; ?>
              </div>
              <?php if ($thumb): ?>
                <img src="photo.php?id=<?php echo $thumb; ?>" alt="Thumb">
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </main>
  <script>
    (function () {
      var dragged = null;
      var board = document.getElementById('kanban-board');
      if (!board) return;
      board.addEventListener('dragstart', function (e) {
        var card = e.target.closest('.kanban-card');
        if (!card) return;
        dragged = card;
        e.dataTransfer.effectAllowed = 'move';
        setTimeout(function () { card.style.opacity = '0.5'; }, 0);
      });
      board.addEventListener('dragend', function (e) {
        if (dragged) dragged.style.opacity = '1';
        dragged = null;
        board.querySelectorAll('.lane-drop').forEach(function (d) { d.style.display = 'none'; });
      });
      board.addEventListener('dragover', function (e) {
        var lane = e.target.closest('.kanban-lane');
        if (!lane) return;
        e.preventDefault();
        var drop = lane.querySelector('.lane-drop');
        if (drop) drop.style.display = 'block';
      });
      board.addEventListener('dragleave', function (e) {
        var lane = e.target.closest('.kanban-lane');
        if (!lane) return;
        var drop = lane.querySelector('.lane-drop');
        if (drop) drop.style.display = 'none';
      });
      board.addEventListener('drop', function (e) {
        e.preventDefault();
        var lane = e.target.closest('.kanban-lane');
        if (!lane || !dragged) return;
        var status = lane.getAttribute('data-status') || '';
        var sku = dragged.getAttribute('data-sku') || '';
        var drop = lane.querySelector('.lane-drop');
        if (drop) drop.style.display = 'none';
        fetch('update_item.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'sku=' + encodeURIComponent(sku) + '&field=status&value=' + encodeURIComponent(status)
        }).then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.ok) {
              lane.appendChild(dragged);
            } else {
              alert('Update failed: ' + (data.error || 'error'));
            }
          })
          .catch(function () { alert('Update failed'); });
      });
    })();
  </script>
</body>
</html>
