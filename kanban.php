<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance();
ensureStorageWritable();
$currentPage = 'kanban';

try {
    $pdo = new PDO('sqlite:' . __DIR__ . '/data/intake.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Database connection failed: ' . $e->getMessage();
    exit;
}

// Ensure thumbnail column exists.
try {
    $pdo->exec("ALTER TABLE sku_photos ADD COLUMN is_thumb INTEGER NOT NULL DEFAULT 0");
} catch (Throwable $e) {
    // ignore
}

// Ensure reviewed column exists.
try {
    $pdo->exec("ALTER TABLE intake_items ADD COLUMN reviewed INTEGER NOT NULL DEFAULT 0");
} catch (Throwable $e) {
    // ignore
}

$lanes = ['Intake', 'Tested', 'Ready for eBay Listing', 'Dispo Tech Store', 'eBay Listed', 'SOLD'];
$cards = [];
$thumbs = [];
$items = $pdo->query("
    SELECT id, sku, sku_normalized, status, what_is_it, notes, updated_at, dispotech_price, reviewed
    FROM intake_items
    WHERE sku IS NOT NULL AND TRIM(sku) <> ''
    ORDER BY updated_at DESC, id DESC
    LIMIT 5000
")->fetchAll();

$rowMentionsRefurb = static function (array $row): bool {
    $combined = strtolower(trim((string)($row['what_is_it'] ?? '') . ' ' . (string)($row['notes'] ?? '')));
    return str_contains($combined, 'refurb');
};

$dedupedItems = [];
foreach ($items as $item) {
    $norm = strtoupper(trim((string)($item['sku_normalized'] ?? $item['sku'] ?? '')));
    if ($norm === '') {
        continue;
    }
    if (!isset($dedupedItems[$norm])) {
        $dedupedItems[$norm] = $item;
        continue;
    }

    $current = $dedupedItems[$norm];
    $currentRefurb = $rowMentionsRefurb($current);
    $incomingRefurb = $rowMentionsRefurb($item);
    if ($incomingRefurb && !$currentRefurb) {
        $dedupedItems[$norm] = $item;
        continue;
    }
    if ($incomingRefurb === $currentRefurb) {
        $incomingStamp = (string)($item['updated_at'] ?? '');
        $currentStamp = (string)($current['updated_at'] ?? '');
        if ($incomingStamp > $currentStamp || ($incomingStamp === $currentStamp && (int)$item['id'] > (int)$current['id'])) {
            $dedupedItems[$norm] = $item;
        }
    }
}

$items = array_values($dedupedItems);

$skus = array_values(array_unique(array_filter(array_map(static fn($r) => strtoupper(trim((string)($r['sku'] ?? ''))), $items))));
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
  <link rel="stylesheet" href="assets/style.css?v=<?= filemtime('assets/style.css') ?>">
  <script src="assets/menu.js?v=<?= filemtime('assets/menu.js') ?>" defer></script>
  <script src="assets/qz-tray.js?v=<?= filemtime('assets/qz-tray.js') ?>"></script>
  <script>window.CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;</script>
  <script src="assets/theme.js?v=<?= filemtime('assets/theme.js') ?>"></script>
  <script src="assets/app.js?v=<?= filemtime('assets/app.js') ?>"></script>
</head>
<body class="home status-board">
  <div class="layout-wrapper">
  <div class="app-menu">
      <button type="button" class="menu-toggle" aria-expanded="false" aria-controls="global-menu" id="menu-toggle">
        <span class="hamburger" aria-hidden="true"></span>
        <span class="menu-label">Menu</span>
      </button>
      <nav class="menu-panel" id="global-menu" aria-hidden="true">
        <ul class="menu-links">
          <li><a class="menu-link" href="home.php">Dashboard</a></li>
          <li><a class="menu-link" href="intake.php?clear_draft=1" data-new-intake>Intake</a></li>
          <li><a class="menu-link is-active" href="kanban.php">Status Board</a></li>
          <li><a class="menu-link" href="lookup.php">SKU Lookup</a></li>
          <li><a class="menu-link" href="archive.php">Archive</a></li>
          <li><a class="menu-link" href="prompt_builder.php">Script Builder</a></li>
        </ul>
      </nav>
    </div>
  <main class="page">
    <section class="sheet kanban-shell">
      <header class="sheet-header">
        <div class="updated">Dispo.Tech Status Board</div>
        <div class="sheet-header-right">
          <span class="autosave-status" id="autosave-status" hidden>Autosave ready</span>
          <button type="button" class="ghost" id="kanban-undo-header-btn" hidden title="Restore the last deleted item">↩ Undo last delete</button>
          <a class="button-link" href="home.php">Dashboard</a>
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
              <div class="kanban-card<?php echo $lane === 'SOLD' ? ' is-sold' : ''; ?>" draggable="true"
                   data-id="<?php echo (int)($card['id'] ?? 0); ?>"
                   data-sku="<?php echo htmlspecialchars($sku, ENT_QUOTES, 'UTF-8'); ?>"
                   data-sku-normalized="<?php echo htmlspecialchars($norm, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="card-inner">
                  <?php if ($thumb): ?>
                    <img class="card-thumb" src="photo.php?id=<?php echo $thumb; ?>" alt="" draggable="false">
                  <?php else: ?>
                    <div class="card-thumb card-thumb-empty"></div>
                  <?php endif; ?>
                  <div class="card-body">
                    <div class="sku">
                      <a href="intake.php?sku=<?php echo urlencode($sku); ?>" title="Open <?php echo htmlspecialchars($sku, ENT_QUOTES, 'UTF-8'); ?> in intake">
                        <?php echo htmlspecialchars($sku, ENT_QUOTES, 'UTF-8'); ?>
                      </a>
                    </div>
                    <div class="what"><?php echo htmlspecialchars($card['what_is_it'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="meta">
                      <span><?php echo htmlspecialchars($card['updated_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                      <?php if (isset($card['dispotech_price']) && $card['dispotech_price'] !== ''): ?>
                        <span>$<?php echo number_format((float)$card['dispotech_price'], 2); ?></span>
                      <?php endif; ?>
                    </div>
                    <div class="reviewed-checkbox<?php echo !empty($card['reviewed']) ? ' checked' : ''; ?>"
                         data-sku="<?php echo htmlspecialchars($sku, ENT_QUOTES, 'UTF-8'); ?>">
                      <span><svg class="ck-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><?php echo $lane === 'SOLD' ? 'Sold' : 'Active'; ?></span>
                    </div>
                  </div>
                  <div class="card-print-actions">
                  <button type="button" class="card-print-btn"
                          data-sku="<?php echo htmlspecialchars($sku, ENT_QUOTES, 'UTF-8'); ?>"
                          title="Print label for <?php echo htmlspecialchars($sku, ENT_QUOTES, 'UTF-8'); ?>"
                          aria-label="Print label for <?php echo htmlspecialchars($sku, ENT_QUOTES, 'UTF-8'); ?>">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                  </button>
                  </div>
                  <button type="button" class="card-delete-btn"
                          data-id="<?php echo (int)($card['id'] ?? 0); ?>"
                          data-sku="<?php echo htmlspecialchars($norm, ENT_QUOTES, 'UTF-8'); ?>"
                          title="Delete <?php echo htmlspecialchars($sku, ENT_QUOTES, 'UTF-8'); ?>"
                          aria-label="Delete <?php echo htmlspecialchars($sku, ENT_QUOTES, 'UTF-8'); ?>">
                    🗑
                  </button>
                </div>
              </div>
            <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      </div>
    </section>
  </main>
  <!-- Undo toast -->
  <div class="kanban-undo-toast" id="kanban-undo-toast" role="status" aria-live="polite">
    <span id="kanban-undo-msg">Item deleted</span>
    <button type="button" id="kanban-undo-btn">Undo</button>
    <div class="kanban-undo-progress" id="kanban-undo-progress"></div>
  </div>

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
          body: 'sku=' + encodeURIComponent(sku) + '&field=status&value=' + encodeURIComponent(status) + '&csrf_token=' + encodeURIComponent(window.CSRF_TOKEN)
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
            var dropBody = lane.querySelector('.kanban-lane-body');
            if (dropBody) dropBody.appendChild(card);
            card.style.opacity = '1';

            // Update is-sold class and label when card moves into or out of SOLD lane
            var isSold = status === 'SOLD';
            card.classList.toggle('is-sold', isSold);
            var lbl = card.querySelector('.reviewed-checkbox span');
            if (lbl) {
              lbl.textContent = isSold ? 'Sold' : 'Active';
            }
          })
          .catch(function () {
            alert('Update failed');
          });
      });

      // Handle reviewed pill toggle
      board.addEventListener('click', function (e) {
        var container = e.target.closest('.reviewed-checkbox');
        if (!container) return;
        var sku = container.getAttribute('data-sku');
        if (!sku) return;

        var isChecked = container.classList.toggle('checked');
        var reviewed = isChecked ? '1' : '0';

        fetch('update_item.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'sku=' + encodeURIComponent(sku) + '&field=reviewed&value=' + encodeURIComponent(reviewed) + '&csrf_token=' + encodeURIComponent(window.CSRF_TOKEN)
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (!data.ok) {
              alert('Failed to update reviewed status: ' + (data.error || 'error'));
              container.classList.toggle('checked');
            }
          })
          .catch(function () {
            alert('Failed to update reviewed status');
            container.classList.toggle('checked');
          });
      });

      // ── Delete with undo toast ───────────────────────────────────────
      var undoToast = document.getElementById('kanban-undo-toast');
      var undoMsg = document.getElementById('kanban-undo-msg');
      var undoBtn = document.getElementById('kanban-undo-btn');
      var undoHeaderBtn = document.getElementById('kanban-undo-header-btn');
      var undoProgress = document.getElementById('kanban-undo-progress');
      var undoTimer = null;
      var undoProgressTimer = null;
      var UNDO_DURATION = 6000; // ms
      var lastDeletedCard = null;
      var lastDeletedLaneStatus = '';
      var lastDeletedCardNextSibling = null;

      var hideUndoToast = function () {
        if (undoToast) undoToast.classList.remove('is-visible');
        clearTimeout(undoTimer);
        clearInterval(undoProgressTimer);
      };

      var showUndoToast = function (skuLabel) {
        if (!undoToast) return;
        if (undoMsg) undoMsg.textContent = 'Deleted ' + skuLabel;
        undoToast.classList.add('is-visible');

        // Animate progress bar draining left-to-right
        if (undoProgress) {
          undoProgress.style.transition = 'none';
          undoProgress.style.transform = 'scaleX(1)';
          // Force reflow then start animation
          undoProgress.getBoundingClientRect();
          undoProgress.style.transition = 'transform ' + UNDO_DURATION + 'ms linear';
          undoProgress.style.transform = 'scaleX(0)';
        }

        clearTimeout(undoTimer);
        undoTimer = setTimeout(function () {
          hideUndoToast();
        }, UNDO_DURATION);
      };

      var restoreDeletedCard = function (newId) {
        if (!lastDeletedCard) return;
        var targetLane = board.querySelector('.kanban-lane[data-status="' + lastDeletedLaneStatus + '"]');
        if (!targetLane) return;
        var body = targetLane.querySelector('.kanban-lane-body');
        if (!body) return;
        var card = lastDeletedCard;
        var nextSib = lastDeletedCardNextSibling;
        if (newId) {
          card.setAttribute('data-id', String(newId));
          var delBtn = card.querySelector('.card-delete-btn');
          if (delBtn) delBtn.setAttribute('data-id', String(newId));
        }
        card.style.transition = 'opacity 180ms ease';
        card.style.opacity = '0';
        card.style.transform = '';
        if (nextSib && body.contains(nextSib)) {
          body.insertBefore(card, nextSib);
        } else {
          body.appendChild(card);
        }
        requestAnimationFrame(function () {
          card.style.opacity = '1';
        });
        var count = targetLane.querySelector('.kanban-count');
        if (count) count.textContent = String(parseInt(count.textContent || '0', 10) + 1);
        lastDeletedCard = null;
        lastDeletedLaneStatus = '';
        lastDeletedCardNextSibling = null;
      };

      var doUndo = function () {
        hideUndoToast();
        fetch('undo_delete.php', { method: 'POST', body: 'csrf_token=' + encodeURIComponent(window.CSRF_TOKEN) })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.status === 'ok') {
              restoreDeletedCard(data.new_id);
            } else {
              alert('Nothing to undo.');
            }
          })
          .catch(function () {
            alert('Undo failed — please reload the page.');
          });
      };

      if (undoBtn) undoBtn.addEventListener('click', doUndo);
      if (undoHeaderBtn) {
        undoHeaderBtn.addEventListener('click', doUndo);
      }

      board.addEventListener('click', function (e) {
        var btn = e.target.closest('.card-delete-btn');
        if (!btn) return;

        // Stop the click from triggering a drag or link
        e.stopPropagation();

        var card = btn.closest('.kanban-card');
        if (!card) return;

        var id = btn.getAttribute('data-id') || card.getAttribute('data-id') || '';
        var sku = btn.getAttribute('data-sku') || card.getAttribute('data-sku-normalized') || '';
        var displaySku = card.getAttribute('data-sku') || sku;

        if (!id || id === '0') {
          alert('Could not find item ID — please reload and try again.');
          return;
        }

        // Optimistically remove the card from the UI
        var lane = card.closest('.kanban-lane');
        var laneCount = lane ? lane.querySelector('.kanban-count') : null;
        lastDeletedCard = card;
        lastDeletedLaneStatus = lane ? lane.getAttribute('data-status') : '';
        lastDeletedCardNextSibling = card.nextElementSibling;
        card.style.transition = 'opacity 180ms ease, transform 180ms ease';
        card.style.opacity = '0';
        card.style.transform = 'scale(0.95)';

        setTimeout(function () {
          if (card.parentNode) card.parentNode.removeChild(card);
          if (laneCount) {
            laneCount.textContent = String(Math.max(0, parseInt(laneCount.textContent || '0', 10) - 1));
          }
        }, 190);

        // Send delete to server (confirm=DELETE is the expected value)
        fetch('delete_item.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: 'id=' + encodeURIComponent(id) + '&sku=' + encodeURIComponent(sku) + '&confirm=DELETE&csrf_token=' + encodeURIComponent(window.CSRF_TOKEN)
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.status === 'ok') {
              // Show undo toast and make header undo button visible
              showUndoToast(displaySku);
            if (undoHeaderBtn) undoHeaderBtn.hidden = false;
            } else {
              alert('Delete failed: ' + (data.message || 'unknown error'));
              restoreDeletedCard();
            }
          })
          .catch(function () {
            alert('Delete failed — please reload.');
            restoreDeletedCard();
          });
      });

    })();
  </script>
  </div>
</body>
</html>
