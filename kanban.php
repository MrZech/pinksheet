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
  <title>Status Board · Pinksheet</title>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    .kanban-shell {
      width: min(1680px, 100%);
      margin: 0 auto;
    }
    .kanban-board {
      display: flex;
      gap: 12px;
      overflow-x: auto;
      padding: 12px 6px 18px;
      justify-content: center;
      align-items: flex-start;
      width: 100%;
    }
    .kanban-lane {
      background: rgba(255,255,255,0.08);
      border: 1px solid rgba(255,255,255,0.1);
      border-radius: 12px;
      min-width: 240px;
      max-width: 280px;
      padding: 10px;
      flex: 0 0 260px;
    }
    .kanban-lane.is-drop-target {
      outline: 2px solid rgba(108, 160, 255, 0.7);
      outline-offset: 2px;
    }
    .kanban-lane h3 {
      margin: 0 0 6px 0;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .kanban-count {
      font-size: 12px;
      opacity: 0.7;
    }
    .kanban-card {
      background: #1f2a36;
      border-radius: 8px;
      padding: 8px;
      margin-bottom: 8px;
      cursor: grab;
      border: 1px solid rgba(255,255,255,0.08);
    }
    body:not(.dark-mode) .kanban-card {
      background: #f7f8fa;
    }
    .kanban-card .sku {
      font-weight: 700;
    }
    .kanban-card .what {
      font-size: 13px;
      opacity: 0.8;
    }
    .kanban-card .meta {
      font-size: 12px;
      opacity: 0.7;
      display: flex;
      gap: 8px;
    }
    .kanban-card img {
      width: 100%;
      max-height: 120px;
      object-fit: cover;
      border-radius: 6px;
      margin-top: 6px;
    }
    .lane-drop {
      border: 2px dashed #6ca0ff;
      border-radius: 8px;
      padding: 6px;
      margin-bottom: 6px;
      opacity: 0.6;
      display: none;
    }
  </style>
</head>
<body class="home">
  <main class="page">
    <section class="sheet kanban-shell">
      <header class="sheet-header">
        <div class="updated">Pinksheet Status Board</div>
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
              <div class="kanban-card" draggable="true" data-sku="<?php echo htmlspecialchars($sku, ENT_QUOTES, 'UTF-8'); ?>" data-sku-normalized="<?php echo htmlspecialchars($norm, ENT_QUOTES, 'UTF-8'); ?>">
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
    </section>
  </main>
  <script>
    (function () {
      var dragged = null;
      var draggedFromLane = null;
      var board = document.getElementById('kanban-board');
      if (!board) return;

      board.addEventListener('dragstart', function (e) {
        var card = e.target.closest('.kanban-card');
        if (!card) return;
        dragged = card;
        draggedFromLane = card.closest('.kanban-lane');
        e.dataTransfer.setData('text/plain', card.getAttribute('data-sku-normalized') || card.getAttribute('data-sku') || '');
        e.dataTransfer.effectAllowed = 'move';
        setTimeout(function () { card.style.opacity = '0.5'; }, 0);
      });

      board.addEventListener('dragend', function () {
        if (dragged) dragged.style.opacity = '1';
        dragged = null;
        draggedFromLane = null;
        board.querySelectorAll('.lane-drop').forEach(function (d) {
          d.style.display = 'none';
        });
      });

      board.addEventListener('dragover', function (e) {
        var lane = e.target.closest('.kanban-lane');
        if (!lane) return;
        e.preventDefault();
        var drop = lane.querySelector('.lane-drop');
        if (drop) drop.style.display = 'block';
        lane.classList.add('is-drop-target');
      });

      board.addEventListener('dragleave', function (e) {
        var lane = e.target.closest('.kanban-lane');
        if (!lane) return;
        var drop = lane.querySelector('.lane-drop');
        if (drop) drop.style.display = 'none';
        lane.classList.remove('is-drop-target');
      });

      board.addEventListener('drop', function (e) {
        e.preventDefault();
        var lane = e.target.closest('.kanban-lane');
        if (!lane || !dragged) return;
        var status = lane.getAttribute('data-status') || '';
        var sku = dragged.getAttribute('data-sku-normalized') || dragged.getAttribute('data-sku') || '';
        var drop = lane.querySelector('.lane-drop');
        if (drop) drop.style.display = 'none';
        lane.classList.remove('is-drop-target');

        fetch('update_item.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'sku=' + encodeURIComponent(sku) + '&field=status&value=' + encodeURIComponent(status)
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (!data.ok) {
              alert('Update failed: ' + (data.error || 'error'));
              return;
            }

            if (draggedFromLane && draggedFromLane !== lane) {
              var fromCount = draggedFromLane.querySelector('.kanban-count');
              var toCount = lane.querySelector('.kanban-count');
              if (fromCount && toCount) {
                fromCount.textContent = String(Math.max(0, parseInt(fromCount.textContent || '0', 10) - 1));
                toCount.textContent = String(parseInt(toCount.textContent || '0', 10) + 1);
              }
            }
            lane.appendChild(dragged);
            dragged.style.opacity = '1';
          })
          .catch(function () {
            alert('Update failed');
          });
      });
    })();
  </script>
</body>
</html>
