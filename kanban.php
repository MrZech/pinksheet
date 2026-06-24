<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance();
ensureStorageWritable();
$currentPage = 'kanban';

try {
    $pdo = pdoConnect(__DIR__ . '/data/intake.sqlite');
    $pdo->exec('PRAGMA cache_size = -8000'); // 8MB query cache
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Database connection failed: ' . $e->getMessage();
    exit;
}

$lanes = ['Intake', 'Tested', 'Ready for eBay Listing', 'Dispo Tech Store', 'eBay Listed', 'SOLD'];
$cols = $pdo->query('PRAGMA table_info(intake_items)')->fetchAll(PDO::FETCH_ASSOC);
$colNames = array_map(static fn($row) => (string)($row['name'] ?? ''), $cols);
if (!in_array('ready', $colNames, true)) {
    $pdo->exec("ALTER TABLE intake_items ADD COLUMN ready INTEGER NOT NULL DEFAULT 0");
}

$cards = [];
$thumbs = [];
$items = $pdo->query("
    SELECT id, sku, sku_normalized, status, what_is_it, notes, updated_at, dispotech_price, reviewed, ready
    FROM intake_items
    WHERE sku IS NOT NULL AND sku != ''
    ORDER BY updated_at DESC, id DESC
    LIMIT 500
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
    $maxVars = 900;
    $thumbs = [];
    foreach (array_chunk($skus, $maxVars) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare("
            SELECT sku_normalized, id
            FROM sku_photos
            WHERE sku_normalized IN ($placeholders)
            ORDER BY is_thumb DESC, id DESC
        ");
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll() as $row) {
            $norm = trim((string)$row['sku_normalized']);
            if ($norm && !isset($thumbs[$norm])) {
                $thumbs[$norm] = (int)$row['id'];
            }
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

$csrfToken = csrf_token();
session_write_close();

$isPartial = ($_GET['partial'] ?? '') === '1';
if (!$isPartial):
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Status Board · Pinksheet</title>
  <link rel="stylesheet" href="assets/style.css?v=<?= getAssetVersion() ?>">
  <style>.ready-indicator{display:inline-flex;align-items:center;gap:3px;margin-left:6px;font-size:0.75rem;opacity:0.8}.ready-indicator .visual-ready{width:13px;height:13px;margin:0;cursor:pointer}.ready-indicator .ready-label{color:var(--text-secondary,inherit)}</style>
  <script src="assets/menu.js?v=<?= getAssetVersion() ?>" defer></script>
  <script>window.CSRF_TOKEN = <?= json_encode($csrfToken) ?>;</script>
  <script src="assets/theme.js?v=<?= getAssetVersion() ?>" defer></script>
  <script src="assets/app.js?v=<?= getAssetVersion() ?>" defer></script>
  <script src="assets/qz-tray.js?v=<?= getAssetVersion() ?>" defer></script>
  <script src="assets/nav.js?v=<?= getAssetVersion() ?>" defer></script>
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
  <div id="content-area">
<?php endif; /* end outer shell */ ?>
  <main class="page">
    <section class="sheet kanban-shell">
      <header class="sheet-header">
        <div class="updated">Dispo.Tech Status Board</div>
        <div class="sheet-header-right">
          <span class="autosave-status" id="autosave-status" hidden>Autosave ready</span>
          <button type="button" class="ghost" id="kanban-undo-header-btn" hidden title="Restore the last deleted item">↩ Undo last delete</button>
          <a class="button-link" href="home.php">Dashboard</a>
          <button type="button" class="theme-toggle" id="theme-toggle">Dark mode</button>
          <button type="button" class="ghost scanner-scan-btn" id="scanner-scan-btn" title="Scan QR code to upload photo">📷 Scan QR</button>
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
                <div class="card-top-row">
                  <?php if ($thumb): ?>
                    <picture>
                      <source srcset="photo.php?id=<?php echo $thumb; ?>&format=webp" type="image/webp">
                      <img class="card-thumb" src="photo.php?id=<?php echo $thumb; ?>" alt="" width="72" height="72" draggable="false" loading="lazy">
                    </picture>
                  <?php else: ?>
                    <div class="card-thumb card-thumb-empty"></div>
                  <?php endif; ?>
                  <div class="card-action-buttons">
                    <button type="button" class="card-qr-toggle"
                            data-url="<?php
                                $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                                    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                                    || (isset($_SERVER['HTTP_CF_VISITOR']) && str_contains($_SERVER['HTTP_CF_VISITOR'], '"scheme":"https"'));
                                $protocol = $isHttps ? 'https' : 'http';
                                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                                $qrUrl = $protocol . '://' . $host . '/intake.php?sku=' . urlencode($norm);
                            ?><?php echo htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8'); ?>"
                            title="Show QR for <?php echo htmlspecialchars($sku, ENT_QUOTES, 'UTF-8'); ?>"
                            aria-label="Show QR for <?php echo htmlspecialchars($sku, ENT_QUOTES, 'UTF-8'); ?>">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM18 14h3v3h-3zM14 19h3v2h-3zM19 17v2h2"/></svg>
                    </button>
                    <button type="button" class="card-print-btn"
                            data-sku="<?php echo htmlspecialchars($sku, ENT_QUOTES, 'UTF-8'); ?>"
                            title="Print card for <?php echo htmlspecialchars($sku, ENT_QUOTES, 'UTF-8'); ?>"
                            aria-label="Print card for <?php echo htmlspecialchars($sku, ENT_QUOTES, 'UTF-8'); ?>">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    </button>
                    <button type="button" class="card-delete-btn"
                            data-id="<?php echo (int)($card['id'] ?? 0); ?>"
                            data-sku="<?php echo htmlspecialchars($norm, ENT_QUOTES, 'UTF-8'); ?>"
                            title="Delete <?php echo htmlspecialchars($sku, ENT_QUOTES, 'UTF-8'); ?>"
                            aria-label="Delete <?php echo htmlspecialchars($sku, ENT_QUOTES, 'UTF-8'); ?>">
                      🗑
                    </button>
                  </div>
                </div>
                <div class="card-body">
                  <div class="sku">
                    <a href="intake.php?sku=<?php echo urlencode($sku); ?>" title="Open <?php echo htmlspecialchars($sku, ENT_QUOTES, 'UTF-8'); ?> in intake">
                      <?php echo htmlspecialchars($sku, ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                    <span class="ready-indicator">
                      <input type="checkbox" class="visual-ready"<?php echo (int)($card['ready'] ?? 0) === 1 ? ' checked' : ''; ?>>
                      <span class="ready-label">Ready</span>
                    </span>
                  </div>
                  <div class="what"><?php echo htmlspecialchars($card['what_is_it'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                  <div class="meta">
                    <span><?php echo htmlspecialchars($card['updated_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php if (isset($card['dispotech_price']) && $card['dispotech_price'] !== ''): ?>
                      <span>$<?php echo number_format((float)$card['dispotech_price'], 2); ?></span>
                    <?php endif; ?>
                  </div>
                   <?php
                        $reviewedVal = (int)($card['reviewed'] ?? 0);
                        if ($reviewedVal === 2) {
                            $cardStatus = 'sold';
                            $cardLabel = 'SOLD';
                        } elseif ($reviewedVal === 1) {
                            $cardStatus = 'active';
                            $cardLabel = 'ACTIVE';
                        } else {
                            $cardStatus = 'inactive';
                            $cardLabel = 'INACTIVE';
                        }
                    ?>
                   <div class="status-badge-container status-<?= $cardStatus ?>"
                        data-status="<?= $cardStatus ?>"
                        data-sku="<?= htmlspecialchars($sku, ENT_QUOTES, 'UTF-8'); ?>">
                     <?php if ($cardStatus === 'active'): ?>
                        <svg class="ck-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                     <?php endif; ?>
                     <span class="label-text"><?= $cardLabel ?></span>
                    </div>
                 </div>
                 <div class="qr-drawer">
                   <div class="qr-drawer-inner"></div>
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

            // When dropped on SOLD lane, also set reviewed=2 (sold) and update badge
            if (status === 'SOLD') {
              fetch('update_item.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'sku=' + encodeURIComponent(sku) + '&field=reviewed&value=2&csrf_token=' + encodeURIComponent(window.CSRF_TOKEN)
              }).then(function (r) { return r.json(); }).then(function (d) {
                if (d.ok) {
                  var badge = card.querySelector('.status-badge-container');
                  if (badge) updateStatusBadge(badge, 'sold');
                }
              });
            }
            var isSold = status === 'SOLD';
            card.classList.toggle('is-sold', isSold);
          })
          .catch(function () {
            alert('Update failed');
          });
      });

      // Handle status badge click — only toggles inactive ↔ active (sold is read-only)
      var updateStatusBadge = function (container, newStatus) {
        var span = container.querySelector('.label-text');
        if (!span) return;
        var label = newStatus === 'sold' ? 'SOLD' : (newStatus === 'active' ? 'ACTIVE' : 'INACTIVE');
        span.textContent = label;
        container.setAttribute('data-status', newStatus);
        container.className = 'status-badge-container status-' + newStatus;
        var ck = container.querySelector('.ck-icon');
        if (newStatus === 'active') {
          if (!ck) {
            var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            svg.setAttribute('class', 'ck-icon');
            svg.setAttribute('width', '16');
            svg.setAttribute('height', '16');
            svg.setAttribute('viewBox', '0 0 24 24');
            svg.setAttribute('fill', 'none');
            svg.setAttribute('stroke', 'currentColor');
            svg.setAttribute('stroke-width', '3');
            svg.setAttribute('stroke-linecap', 'round');
            svg.setAttribute('stroke-linejoin', 'round');
            var polyline = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
            polyline.setAttribute('points', '20 6 9 17 4 12');
            svg.appendChild(polyline);
            container.insertBefore(svg, span);
          }
        } else {
          if (ck) ck.remove();
        }
      };

      board.addEventListener('click', function (e) {
        var container = e.target.closest('.status-badge-container');
        if (!container) return;
        var current = container.getAttribute('data-status') || 'inactive';
        // Sold is read-only — cannot toggle via click
        if (current === 'sold') return;
        var sku = container.getAttribute('data-sku');
        if (!sku) return;

        var reviewed = current === 'active' ? '0' : '1';

        fetch('update_item.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'sku=' + encodeURIComponent(sku) + '&field=reviewed&value=' + reviewed + '&csrf_token=' + encodeURIComponent(window.CSRF_TOKEN)
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.ok) {
              updateStatusBadge(container, reviewed === '1' ? 'active' : 'inactive');
            } else {
              alert('Failed to update status: ' + (data.error || 'error'));
            }
          })
          .catch(function () {
            alert('Failed to update status');
          });
      });

      // ── Ready checkbox toggle ──────────────────────────────────
      board.addEventListener('change', function (e) {
        var cb = e.target;
        if (!cb.classList.contains('visual-ready')) return;
        var card = cb.closest('.kanban-card');
        if (!card) return;
        var sku = card.getAttribute('data-sku-normalized') || card.getAttribute('data-sku') || '';
        if (!sku) return;
        var checked = cb.checked;
        var readyVal = checked ? '1' : '0';
        fetch('update_item.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'sku=' + encodeURIComponent(sku) + '&field=ready&value=' + readyVal + '&csrf_token=' + encodeURIComponent(window.CSRF_TOKEN)
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (!data.ok) {
              cb.checked = !checked;
            }
          })
          .catch(function () {
            cb.checked = !checked;
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

      var undoing = false;
      var doUndo = function () {
        if (undoing) return;
        undoing = true;
        if (undoBtn) undoBtn.disabled = true;
        if (undoHeaderBtn) undoHeaderBtn.disabled = true;
        hideUndoToast();
        fetch('undo_delete.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'csrf_token=' + encodeURIComponent(window.CSRF_TOKEN)
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            undoing = false;
            if (undoBtn) undoBtn.disabled = false;
            if (undoHeaderBtn) undoHeaderBtn.disabled = false;
            if (data.status === 'ok') {
              restoreDeletedCard(data.new_id);
            } else {
              alert(data && data.message ? data.message : 'Nothing to undo.');
            }
          })
          .catch(function () {
            undoing = false;
            if (undoBtn) undoBtn.disabled = false;
            if (undoHeaderBtn) undoHeaderBtn.disabled = false;
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

  <!-- ── QR Scanner Overlay ──────────────────────────────── -->
  <div class="scanner-overlay" id="scanner-overlay">
    <div class="scanner-header">
      <h2>Scan QR Code</h2>
      <button type="button" class="scanner-close-btn" id="scanner-close-btn">Close</button>
    </div>
    <div class="scanner-body">
      <div id="scanner-container"></div>
    </div>
  </div>

  <script>
  (function () {
    // ── State cache keys ─────────────────────────────────────────
    var CACHE_TTL = 30000; // 30 seconds

    var saveState = function () {
      try {
        sessionStorage.setItem('kanban_scroll', document.querySelector('.kanban-scroll')?.scrollTop || 0);
        sessionStorage.setItem('kanban_ts', Date.now());
      } catch (e) {}
    };

    var restoreState = function () {
      try {
        var ts = parseInt(sessionStorage.getItem('kanban_ts'), 10);
        if (ts && (Date.now() - ts) < CACHE_TTL) {
          var scrollTop = parseInt(sessionStorage.getItem('kanban_scroll'), 10);
          if (scrollTop > 0) {
            var scroller = document.querySelector('.kanban-scroll');
            if (scroller) scroller.scrollTop = scrollTop;
          }
        }
      } catch (e) {}
    };

    restoreState();

    // Save state on page nav
    window.addEventListener('beforeunload', saveState);

    // ── Track dynamic listeners for cleanup ──────────────────────
    var cleanupFns = [];

    var addListener = function (el, event, fn) {
      el.addEventListener(event, fn);
      cleanupFns.push(function () { el.removeEventListener(event, fn); });
    };

    // ── Collapsible QR drawer toggle ──────────────────────────
    // Each card has a hidden .qr-drawer. Clicking .card-qr-toggle
    // slides it open (with a 150x150 QR generated on first open)
    // or closed. The QR code is generated once and cached in the DOM.
    (function () {
      var qrBoard = document.getElementById('kanban-board');
      if (!qrBoard) return;
      qrBoard.addEventListener('click', function (e) {
        var btn = e.target.closest('.card-qr-toggle');
        if (!btn) return;
        var card = btn.closest('.kanban-card');
        if (!card) return;
        var drawer = card.querySelector('.qr-drawer');
        var inner = card.querySelector('.qr-drawer-inner');
        if (!drawer || !inner) return;

        // Toggle closed if already open
        if (drawer.classList.contains('is-open')) {
          drawer.classList.remove('is-open');
          btn.classList.remove('is-active');
          return;
        }

        // Generate QR on first open
        if (!inner.hasChildNodes() && typeof QRCode !== 'undefined') {
          var url = btn.getAttribute('data-url');
          if (!url) return;
          try {
            new QRCode(inner, {
              text: url,
              width: 150,
              height: 150,
              colorDark: '#0f172a',
              colorLight: '#ffffff',
              correctLevel: QRCode.CorrectLevel.H
            });
          } catch (e) {
            inner.textContent = 'QR error';
          }
        }

        drawer.classList.add('is-open');
        btn.classList.add('is-active');
      });
    })();

    // ── Desktop QR Scanner (Html5QrcodeScanner) ────────────────
    var scannerOverlay = document.getElementById('scanner-overlay');
    var scanBtn = document.getElementById('scanner-scan-btn');
    var closeBtn = document.getElementById('scanner-close-btn');
    var scannerContainer = document.getElementById('scanner-container');
    var html5QrcodeScanner = null;

    var stopScanner = function () {
      if (html5QrcodeScanner) {
        try {
          html5QrcodeScanner.clear();
        } catch (e) {}
        html5QrcodeScanner = null;
      }
      if (scannerContainer) scannerContainer.innerHTML = '';
    };

    var startScanner = function () {
      if (!scannerContainer || typeof Html5QrcodeScanner === 'undefined') return;
      stopScanner();

      html5QrcodeScanner = new Html5QrcodeScanner('scanner-container', {
        fps: 10,
        qrbox: { width: 250, height: 250 }
      }, /* verbose */ false);

      html5QrcodeScanner.render(function (decodedText) {
        var match = decodedText.match(/[?&]sku=([A-Z0-9_-]+)/i);
        if (match && match[1]) {
          var sku = match[1];
          stopScanner();
          scannerOverlay.classList.remove('is-open');
          window.location.href = 'kanban.php?highlight=' + encodeURIComponent(sku);
        }
      }, function () {});
    };

    if (scanBtn) {
      addListener(scanBtn, 'click', function () {
        scannerOverlay.classList.add('is-open');
        setTimeout(startScanner, 300);
      });
    }

    if (closeBtn) {
      addListener(closeBtn, 'click', function () {
        stopScanner();
        scannerOverlay.classList.remove('is-open');
      });
    }

    addListener(scannerOverlay, 'click', function (e) {
      if (e.target === scannerOverlay) {
        stopScanner();
        scannerOverlay.classList.remove('is-open');
      }
    });

    // Clean up all tracked listeners on unload
    window.addEventListener('beforeunload', function () {
      for (var i = 0; i < cleanupFns.length; i++) {
        try { cleanupFns[i](); } catch (e) {}
      }
      saveState();
    });
  })();
  </script>
  <script>
  setTimeout(function () {
    var s1 = document.createElement('script');
    s1.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js';
    s1.integrity = 'sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==';
    s1.crossOrigin = 'anonymous';
    s1.referrerPolicy = 'no-referrer';
    document.body.appendChild(s1);

    var s2 = document.createElement('script');
    s2.src = 'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js';
    s2.integrity = 'sha512-r6rDA7W6ZeQhvl8S7yRVQUKVHdexq+GAlNkNNqVC7YyIV+NwqCTJe2hDWCiffTyRNOeGEzRRJ9ifvRm/HCzGYg==';
    s2.crossOrigin = 'anonymous';
    s2.referrerPolicy = 'no-referrer';
    document.body.appendChild(s2);
  }, 1);
  </script>
<?php if (!$isPartial): ?>
  </div> <!-- /content-area -->
  </div> <!-- /layout-wrapper -->
</body>
</html>
<?php endif; ?>
