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
    /* Wider canvas than default home (1120px) so five lanes fit; page is centered via .home .page */
    .home.status-board .page {
      max-width: min(1520px, calc(100vw - 32px));
    }

    .kanban-shell {
      width: 100%;
      max-width: none;
      margin-inline: auto;
    }

    .kanban-scroll {
      width: 100%;
      overflow-x: auto;
      overflow-y: visible;
      overscroll-behavior-x: contain;
      padding: 12px 0 18px;
    }

    .kanban-board {
      display: flex;
      gap: 12px;
      flex-wrap: nowrap;
      justify-content: center;
      align-items: flex-start;
      width: max-content;
      min-width: 100%;
      margin-inline: auto;
    }
    .kanban-lane {
      display: flex;
      flex-direction: column;
      background: var(--surface-glass);
      border: 1px solid var(--line);
      border-radius: 14px;
      min-width: 240px;
      max-width: 280px;
      padding: 10px;
      flex: 0 0 260px;
      box-shadow: var(--shadow-soft);
      backdrop-filter: blur(12px);
      transition: border-color 0.12s ease, box-shadow 0.12s ease;
    }
    .kanban-lane.is-drop-target {
      border-color: var(--accent-strong);
      box-shadow: 0 0 0 2px var(--accent-strong), var(--shadow-soft);
    }
    .kanban-lane-body {
      min-height: 48px;
      flex: 1;
    }
    body.kanban-dragging .kanban-card {
      cursor: grabbing;
    }
    body.kanban-dragging .kanban-card:not(.is-dragging) {
      pointer-events: none;
    }
    body.kanban-dragging .kanban-lane {
      pointer-events: auto;
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
      background: linear-gradient(180deg, var(--surface-primary), var(--surface-secondary));
      border-radius: 12px;
      padding: 8px;
      margin-bottom: 8px;
      cursor: grab;
      border: 1px solid var(--line);
      box-shadow: var(--shadow-soft);
      touch-action: none;
    }
    .kanban-card.is-dragging {
      opacity: 0.45;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
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
      pointer-events: none;
      user-select: none;
      -webkit-user-drag: none;
    }
  </style>
</head>
<body class="home status-board">
  <main class="page">
    <section class="sheet kanban-shell">
      <header class="sheet-header">
        <div class="updated">Pinksheet Status Board</div>
        <div class="sheet-header-right">
          <a class="button-link" href="home.php">Home</a>
          <button type="button" class="theme-toggle" id="theme-toggle">Dark mode</button>
        </div>
      </header>
      <h1>Status Board</h1>
      <p class="lead">Drag cards to update status; inline updates save immediately.</p>
      <div class="kanban-scroll">
      <div class="kanban-board" id="kanban-board">
        <?php foreach ($lanes as $lane): $list = $cards[$lane] ?? []; ?>
          <div class="kanban-lane" data-status="<?php echo htmlspecialchars($lane, ENT_QUOTES, 'UTF-8'); ?>">
            <h3><?php echo htmlspecialchars($lane, ENT_QUOTES, 'UTF-8'); ?> <span class="kanban-count"><?php echo count($list); ?></span></h3>
            <div class="kanban-lane-body">
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
                  <img src="photo.php?id=<?php echo $thumb; ?>" alt="" draggable="false">
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      </div>
    </section>
  </main>
  <script>
    (function () {
      var dragged = null;
      var draggedFromLane = null;
      var highlightedLane = null;
      var board = document.getElementById('kanban-board');
      if (!board) return;
      var dragHost = board.closest('.kanban-scroll') || board;

      function clearLaneHighlight() {
        if (highlightedLane) {
          highlightedLane.classList.remove('is-drop-target');
          highlightedLane = null;
        }
      }

      function setLaneHighlight(lane) {
        if (lane === highlightedLane) return;
        clearLaneHighlight();
        if (lane) {
          lane.classList.add('is-drop-target');
          highlightedLane = lane;
        }
      }

      var themeToggle = document.getElementById('theme-toggle');
      function setTheme(mode) {
        var isDark = mode === 'dark';
        document.body.dataset.theme = isDark ? 'dark' : 'light';
        document.body.classList.toggle('dark-mode', isDark);
        if (themeToggle) {
          themeToggle.textContent = isDark ? 'Light mode' : 'Dark mode';
        }
      }
      var storedTheme = null;
      try {
        storedTheme = localStorage.getItem('themePreference');
      } catch (e) {}
      setTheme(storedTheme || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
      if (themeToggle) {
        themeToggle.addEventListener('click', function () {
          var nextMode = document.body.dataset.theme === 'dark' ? 'light' : 'dark';
          setTheme(nextMode);
          try { localStorage.setItem('themePreference', nextMode); } catch (e) {}
        });
      }

      board.addEventListener('dragstart', function (e) {
        var card = e.target.closest('.kanban-card');
        if (!card) return;
        dragged = card;
        draggedFromLane = card.closest('.kanban-lane');
        card.classList.add('is-dragging');
        document.body.classList.add('kanban-dragging');
        e.dataTransfer.setData('text/plain', card.getAttribute('data-sku-normalized') || card.getAttribute('data-sku') || '');
        e.dataTransfer.effectAllowed = 'move';
      });

      board.addEventListener('dragend', function () {
        clearLaneHighlight();
        document.body.classList.remove('kanban-dragging');
        if (dragged) {
          dragged.classList.remove('is-dragging');
          dragged.style.opacity = '';
        }
        dragged = null;
        draggedFromLane = null;
      });

      board.addEventListener('dragover', function (e) {
        if (!dragged) return;
        var lane = e.target.closest('.kanban-lane');
        if (!lane) {
          clearLaneHighlight();
          return;
        }
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        setLaneHighlight(lane);
      });

      board.addEventListener('dragleave', function (e) {
        if (!dragged) return;
        var rel = e.relatedTarget;
        if (rel && dragHost.contains(rel)) return;
        clearLaneHighlight();
      });

      board.addEventListener('drop', function (e) {
        e.preventDefault();
        var lane = e.target.closest('.kanban-lane');
        if (!lane || !dragged) return;
        var status = lane.getAttribute('data-status') || '';
        // Capture before dragend runs (dragend clears globals before fetch completes).
        var card = dragged;
        var fromLane = draggedFromLane;
        var sku = card.getAttribute('data-sku-normalized') || card.getAttribute('data-sku') || '';
        clearLaneHighlight();

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

            if (fromLane && fromLane !== lane) {
              var fromCount = fromLane.querySelector('.kanban-count');
              var toCount = lane.querySelector('.kanban-count');
              if (fromCount && toCount) {
                fromCount.textContent = String(Math.max(0, parseInt(fromCount.textContent || '0', 10) - 1));
                toCount.textContent = String(parseInt(toCount.textContent || '0', 10) + 1);
              }
            }
            lane.appendChild(card);
            card.style.opacity = '1';
          })
          .catch(function () {
            alert('Update failed');
          });
      });
    })();
  </script>
</body>
</html>
