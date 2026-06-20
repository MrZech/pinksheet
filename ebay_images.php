<?php
require_once __DIR__ . '/config.php';
checkMaintenance();
ensureStorageWritable();

const EBAI_DB = __DIR__ . '/data/intake.sqlite';
const IMG_DIR = __DIR__ . '/data/ebay_images';
const LAYOUT_DIR = __DIR__ . '/data/layouts';

$currentPage = 'ebay_images';
$currentSku = normalizeSku((string)($_GET['sku'] ?? ''));
$recentSkus = [];
$savedPositions = [];

// Ensure storage dirs exist
foreach ([IMG_DIR, LAYOUT_DIR] as $d) {
    if (!is_dir($d)) @mkdir($d, 0777, true);
}

try {
    $pdo = pdoConnect(EBAI_DB);
    $recentStmt = $pdo->query("SELECT sku FROM intake_items WHERE sku IS NOT NULL AND TRIM(sku) <> '' ORDER BY updated_at DESC, id DESC LIMIT 60");
    $recentSkus = array_values(array_filter(array_unique(array_map('trim', $recentStmt->fetchAll(PDO::FETCH_COLUMN))), static fn($s) => $s !== ''));
} catch (Throwable $e) {
    error_log('ebay_images DB: ' . $e->getMessage());
}

// Load saved layout
if ($currentSku !== '') {
    $layoutFile = LAYOUT_DIR . '/' . $currentSku . '.json';
    if (is_file($layoutFile)) {
        $raw = file_get_contents($layoutFile);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $savedPositions = $decoded;
        }
    }
}

// Also query sku_photos for thumbnail strip
$skuPhotos = [];
if ($currentSku !== '') {
    try {
        $ps = $pdo->prepare("SELECT id, original_name FROM sku_photos WHERE sku_normalized = :sku ORDER BY sort_order ASC, id ASC LIMIT 50");
        $ps->execute(['sku' => $currentSku]);
        $skuPhotos = $ps->fetchAll();
    } catch (Throwable $e) {
        error_log('ebay_images sku_photos: ' . $e->getMessage());
    }
}

$csrfToken = csrf_token();
session_write_close();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>eBay Images - Dispo.Tech</title>
  <link rel="stylesheet" href="assets/style.css?v=<?= getAssetVersion() ?>">
  <script src="assets/menu.js?v=<?= getAssetVersion() ?>" defer></script>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <script>window.CSRF_TOKEN = <?= json_encode($csrfToken) ?>;</script>
  <script src="assets/theme.js?v=<?= getAssetVersion() ?>" defer></script>
  <style>
    .ebay-canvas-wrap {
      position: relative;
      width: 100%;
      min-height: 520px;
      border: 2px dashed var(--line);
      border-radius: var(--radius-sm);
      background: var(--surface-secondary);
      overflow: hidden;
      margin: 10px 0;
    }
    .ebay-canvas-wrap.drag-over {
      border-color: var(--accent-strong);
      background: var(--accent-primary-soft);
    }
    .ebay-canvas-empty {
      position: absolute;
      inset: 0;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      color: var(--muted);
      pointer-events: none;
      gap: 4px;
    }
    .ebay-canvas-image {
      position: absolute;
      cursor: grab;
      border: 2px solid transparent;
      border-radius: 6px;
      overflow: hidden;
      background: #fff;
      box-shadow: 0 2px 8px rgba(0,0,0,0.13);
      user-select: none;
      touch-action: none;
      z-index: 1;
    }
    .ebay-canvas-image:hover {
      border-color: var(--accent-strong);
      z-index: 2;
    }
    .ebay-canvas-image.dragging {
      cursor: grabbing;
      z-index: 10;
      box-shadow: 0 8px 28px rgba(0,0,0,0.3);
      opacity: 0.92;
    }
    .ebay-canvas-image img {
      display: block;
      width: 180px;
      height: 135px;
      object-fit: cover;
      pointer-events: none;
    }
    .ebay-canvas-image .remove-btn {
      position: absolute;
      top: 4px; right: 4px;
      width: 24px; height: 24px;
      border: none; border-radius: 50%;
      background: rgba(0,0,0,0.55);
      color: #fff;
      font-size: 16px;
      line-height: 24px;
      text-align: center;
      cursor: pointer;
      opacity: 0;
      transition: opacity 120ms;
      padding: 0;
    }
    .ebay-canvas-image:hover .remove-btn { opacity: 1; }
    .ebay-canvas-image .remove-btn:hover { background: #d32f2f; }
    .strip-wrap {
      display: flex; gap: 6px; flex-wrap: wrap;
      padding: 8px;
      border: 1px solid var(--line);
      border-radius: var(--radius-sm);
      background: var(--surface-primary);
      min-height: 64px;
    }
    .strip-wrap:empty::after {
      content: 'No photos yet. Upload below.';
      display: block; width: 100%; text-align: center;
      color: var(--muted); font-size: 0.85rem; padding: 10px 0;
    }
    .strip-item {
      width: 72px; height: 54px;
      border-radius: 4px; overflow: hidden;
      cursor: grab; border: 1px solid var(--line);
      background: var(--surface-secondary);
      flex-shrink: 0;
    }
    .strip-item:hover { border-color: var(--accent-strong); }
    .strip-item img { width: 100%; height: 100%; object-fit: cover; pointer-events: none; }
    .upload-drop {
      border: 2px dashed var(--line);
      border-radius: var(--radius-sm);
      padding: 20px 12px; text-align: center;
      color: var(--muted); cursor: pointer;
      background: var(--surface-secondary);
      margin-top: 8px;
    }
    .upload-drop.hover { border-color: var(--accent-strong); background: var(--accent-primary-soft); }
    .url-row { display: flex; gap: 6px; margin-top: 8px; }
    .url-row input { flex: 1; }
    .ebay-grid { display: grid; grid-template-columns: 1fr 260px; gap: 18px; align-items: start; }
    @media (max-width: 800px) { .ebay-grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body class="home">
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
        <li><a class="menu-link" href="kanban.php">Status Board</a></li>
        <li><a class="menu-link" href="lookup.php">SKU Lookup</a></li>
        <li><a class="menu-link" href="archive.php">Archive</a></li>
        <li><a class="menu-link" href="prompt_builder.php">Script Builder</a></li>
        <li><a class="menu-link is-active" href="ebay_images.php">eBay Images</a></li>
      </ul>
    </nav>
  </div>
  <main class="page">
    <section class="sheet home-sheet">
      <header class="sheet-header">
        <div class="updated">eBay Listing Image Composer</div>
        <div class="sheet-header-right">
          <span class="badge subtle" id="status-chip">Ready</span>
          <button type="button" class="theme-toggle" id="theme-toggle">Dark mode</button>
        </div>
      </header>
      <h1>eBay Listing Image Composer</h1>
      <nav class="breadcrumbs"><a href="home.php">Dashboard</a><a href="prompt_builder.php">Script Builder</a><span>eBay Images</span></nav>
      <p class="lead">Upload or paste images and arrange them freely on the canvas. Positions auto-save when you release an image.</p>

      <section class="section">
        <div class="row">
          <label>SKU <input type="text" id="sku-input" list="sku-suggestions" value="<?= h($currentSku) ?>"></label>
          <datalist id="sku-suggestions"><?php foreach ($recentSkus as $s): ?><option value="<?= h($s) ?>"><?php endforeach; ?></datalist>
          <div style="display:flex;align-items:flex-end;gap:8px">
            <button type="button" id="load-btn">Load</button>
            <a class="button-link subtle" href="prompt_builder.php<?= $currentSku ? '?sku='.urlencode($currentSku) : '' ?>">Open Script Builder</a>
          </div>
        </div>
      </section>

      <div class="ebay-grid">
        <div class="section">
          <h2>Canvas</h2>
          <p class="hint">Drag images around to compose your listing layout.</p>
          <div class="ebay-canvas-wrap" id="canvas">
            <div class="ebay-canvas-empty" id="canvas-empty">
              <strong>Canvas is empty</strong>
              <span>Drag photos from the sidebar, upload new ones, or paste an image URL</span>
            </div>
          </div>
          <div style="display:flex;gap:8px;margin-top:8px">
            <button type="button" class="ghost" id="save-btn">Save Positions</button>
            <button type="button" class="ghost danger" id="clear-btn">Clear All</button>
          </div>
        </div>

        <div class="ebay-sidebar">
          <section class="section">
            <h2>SKU Photos</h2>
            <p class="hint">Drag onto canvas</p>
            <div class="strip-wrap" id="photo-strip">
              <?php foreach ($skuPhotos as $p): ?>
              <div class="strip-item" draggable="true" data-src="photo.php?id=<?= (int)$p['id'] ?>">
                <img src="photo.php?id=<?= (int)$p['id'] ?>" alt="<?= h($p['original_name'] ?? '') ?>">
              </div>
              <?php endforeach; ?>
            </div>
          </section>

          <section class="section">
            <h2>Upload Image</h2>
            <p class="hint">JPG, PNG, WebP, GIF &mdash; max 16 MB</p>
            <div class="upload-drop" id="upload-zone">
              <strong>Drop files here</strong><br><span style="font-size:0.85rem">or click to select</span>
              <input type="file" id="file-input" accept="image/jpeg,image/png,image/webp,image/gif" multiple style="display:none">
            </div>
            <div id="upload-progress" style="display:none;margin-top:6px">
              <div style="height:6px;background:var(--palette-teal-soft);border-radius:4px;overflow:hidden">
                <div id="upload-bar" style="height:100%;width:0%;background:var(--palette-teal);transition:width 200ms linear"></div>
              </div>
            </div>
            <div id="upload-msg" class="error client-error" style="margin-top:6px" hidden></div>
          </section>

          <section class="section">
            <h2>Paste Image URL</h2>
            <p class="hint">Paste an eBay image URL or any direct image link</p>
            <div class="url-row">
              <input type="url" id="url-input" placeholder="https://i.ebayimg.com/...">
              <button type="button" id="url-add-btn">Add</button>
            </div>
          </section>
        </div>
      </div>
    </section>
  </main>
</div>

<script>
(function () {
  'use strict';

  /* ── State ──────────────────────────────────────────────────── */
  var sku = <?= json_encode($currentSku) ?>;
  var images = <?= json_encode($savedPositions) ?>;  // [{id, src, name, x, y}]
  var idCounter = -1;
  var canvas = document.getElementById('canvas');
  var emptyHint = document.getElementById('canvas-empty');
  var statusChip = document.getElementById('status-chip');
  var strip = document.getElementById('photo-strip');

  /* ── Render all positioned images on the canvas ─────────────── */
  function render() {
    var el = canvas.querySelectorAll('.ebay-canvas-image');
    Array.prototype.forEach.call(el, function (e) { e.remove(); });
    if (!images.length) { emptyHint.hidden = false; return; }
    emptyHint.hidden = true;

    images.forEach(function (img, idx) {
      var div = document.createElement('div');
      div.className = 'ebay-canvas-image';
      div.style.left = (img.x || 20) + 'px';
      div.style.top  = (img.y || 20) + 'px';
      div.dataset.idx = idx;

      var rm = document.createElement('button');
      rm.className = 'remove-btn'; rm.type = 'button';
      rm.innerHTML = '&times;'; rm.title = 'Remove';
      rm.addEventListener('click', function (e) { e.stopPropagation(); removeImage(idx); });

      var im = document.createElement('img');
      im.src = img.src; im.alt = img.name || ''; im.draggable = false;

      div.appendChild(rm);
      div.appendChild(im);
      canvas.appendChild(div);
      makeDraggable(div, idx);
    });
  }

  /* ── Add an image to the state + canvas ─────────────────────── */
  function addImage(src, name, x, y) {
    images.push({ id: idCounter--, src: src, name: name || 'Image', x: x || 30, y: y || 30 });
    render();
  }

  /* ── Remove image by index ──────────────────────────────────── */
  function removeImage(idx) {
    images.splice(idx, 1);
    render();
  }

  /* ── Mousedown/Mousemove/Mouseup reposition drag ────────────── */
  function makeDraggable(div, idx) {
    var startX, startY, origX, origY, rect;

    function onDown(e) {
      if (e.target.closest('.remove-btn')) return;
      e.preventDefault();
      var cx = e.clientX, cy = e.clientY;
      rect = canvas.getBoundingClientRect();
      startX = cx; startY = cy;
      origX = images[idx].x; origY = images[idx].y;
      div.classList.add('dragging');
      document.addEventListener('mousemove', onMove);
      document.addEventListener('mouseup', onUp);
    }

    function onMove(e) {
      e.preventDefault();
      var dx = e.clientX - startX, dy = e.clientY - startY;
      var mx = rect.width - div.offsetWidth, my = rect.height - div.offsetHeight;
      var nx = Math.max(0, Math.min(mx, origX + dx));
      var ny = Math.max(0, Math.min(my, origY + dy));
      div.style.left = nx + 'px'; div.style.top = ny + 'px';
      images[idx].x = nx; images[idx].y = ny;
    }

    function onUp() {
      div.classList.remove('dragging');
      document.removeEventListener('mousemove', onMove);
      document.removeEventListener('mouseup', onUp);
      // Persist: save to server
      saveLayout();
    }

    div.addEventListener('mousedown', onDown);
  }

  /* ── Save layout via fetch POST to save_layout.php ──────────── */
  function saveLayout() {
    if (!sku || !images.length) return;
    var payload = images.map(function (img) {
      return { id: img.id, x: img.x, y: img.y, src: img.src, name: img.name };
    });
    var fd = new FormData();
    fd.append('sku', sku);
    fd.append('csrf_token', window.CSRF_TOKEN);
    fd.append('positions', JSON.stringify(payload));

    fetch('save_layout.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.ok) { setStatus('Saved', 'ok'); } else { setStatus('Save failed', 'warn'); }
      })
      .catch(function () { setStatus('Save error', 'warn'); });
  }

  /* ── Upload via fetch + FormData ─────────────────────────────── */
  function uploadFile(file, dropX, dropY) {
    if (!sku) { showMsg('Select a SKU first.'); return; }
    var fd = new FormData();
    fd.append('photo', file);
    fd.append('sku', sku);
    fd.append('csrf_token', window.CSRF_TOKEN);

    document.getElementById('upload-progress').style.display = 'block';
    document.getElementById('upload-bar').style.width = '0%';
    hideMsg();

    fetch('upload.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (resp) {
        document.getElementById('upload-progress').style.display = 'none';
        if (resp.ok) {
          var x = typeof dropX === 'number' ? dropX : 40 + Math.random() * 80;
          var y = typeof dropY === 'number' ? dropY : 40 + Math.random() * 80;
          addImage(resp.url, resp.name || file.name, x, y);
          setStatus('Uploaded: ' + (file.name || 'image'), 'ok');
        } else {
          showMsg(resp.message || 'Upload failed');
        }
      })
      .catch(function () {
        document.getElementById('upload-progress').style.display = 'none';
        showMsg('Network error');
      });
  }

  /* ── Upload via fetch with progress tracking (XHR for progress) ─ */
  function uploadFileXHR(file, dropX, dropY) {
    if (!sku) { showMsg('Select a SKU first.'); return; }
    var fd = new FormData();
    fd.append('photo', file);
    fd.append('sku', sku);
    fd.append('csrf_token', window.CSRF_TOKEN);

    document.getElementById('upload-progress').style.display = 'block';
    document.getElementById('upload-bar').style.width = '0%';
    hideMsg();

    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'upload.php');
    xhr.upload.onprogress = function (e) {
      if (e.lengthComputable) {
        document.getElementById('upload-bar').style.width = Math.round((e.loaded / e.total) * 100) + '%';
      }
    };
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      document.getElementById('upload-progress').style.display = 'none';
      if (xhr.status >= 200 && xhr.status < 300) {
        var resp;
        try { resp = JSON.parse(xhr.responseText); } catch (_) { resp = { ok: false, message: 'Bad response' }; }
        if (resp.ok) {
          var x = typeof dropX === 'number' ? dropX : 40 + Math.random() * 80;
          var y = typeof dropY === 'number' ? dropY : 40 + Math.random() * 80;
          addImage(resp.url, resp.name || file.name, x, y);
          setStatus('Uploaded: ' + (file.name || 'image'), 'ok');
        } else {
          showMsg(resp.message || 'Upload failed');
        }
      } else {
        showMsg('Upload error (HTTP ' + xhr.status + ')');
      }
    };
    xhr.onerror = function () { document.getElementById('upload-progress').style.display = 'none'; showMsg('Network error'); };
    xhr.send(fd);
  }

  /* ── Upload dropzone ─────────────────────────────────────────── */
  var uploadZone = document.getElementById('upload-zone');
  var fileInput = document.getElementById('file-input');

  uploadZone.addEventListener('dragover', function (e) { e.preventDefault(); uploadZone.classList.add('hover'); });
  uploadZone.addEventListener('dragleave', function () { uploadZone.classList.remove('hover'); });
  uploadZone.addEventListener('drop', function (e) {
    e.preventDefault(); uploadZone.classList.remove('hover');
    var files = e.dataTransfer.files;
    if (files && files.length) {
      Array.prototype.forEach.call(files, function (f) { if (f.type.startsWith('image/')) uploadFileXHR(f); });
    }
  });
  uploadZone.addEventListener('click', function () { fileInput.click(); });
  fileInput.addEventListener('change', function () {
    if (this.files && this.files.length) {
      Array.prototype.forEach.call(this.files, function (f) { uploadFileXHR(f); });
      this.value = '';
    }
  });

  /* ── Canvas accepts drops (files AND thumbnail items) ──────── */
  canvas.addEventListener('dragover', function (e) { e.preventDefault(); canvas.classList.add('drag-over'); });
  canvas.addEventListener('dragleave', function (e) { if (!canvas.contains(e.relatedTarget)) canvas.classList.remove('drag-over'); });
  canvas.addEventListener('drop', function (e) {
    e.preventDefault(); canvas.classList.remove('drag-over');
    var rect = canvas.getBoundingClientRect();
    var x = e.clientX - rect.left - 90, y = e.clientY - rect.top - 67;
    x = Math.max(0, x); y = Math.max(0, y);

    var files = e.dataTransfer.files;
    if (files && files.length) {
      Array.prototype.forEach.call(files, function (f) { if (f.type.startsWith('image/')) uploadFileXHR(f, x, y); });
      return;
    }
    var src = e.dataTransfer.getData('text/plain');
    if (src) { addImage(src, 'Image', x, y); saveLayout(); setStatus('Added to canvas', 'ok'); }
  });

  /* ── Thumbnail strip drag ────────────────────────────────────── */
  if (strip) {
    strip.addEventListener('dragstart', function (e) {
      var item = e.target.closest('.strip-item');
      if (!item) return;
      e.dataTransfer.setData('text/plain', item.getAttribute('data-src') || '');
      e.dataTransfer.effectAllowed = 'copy';
    });
  }

  /* ── URL paste ───────────────────────────────────────────────── */
  document.getElementById('url-add-btn').addEventListener('click', function () {
    var input = document.getElementById('url-input');
    var url = input.value.trim();
    if (!url) return;
    // CORS proxy approach: use a simple image load to verify
    var rect = canvas.getBoundingClientRect();
    var x = 30 + Math.random() * 60, y = 30 + Math.random() * 60;
    addImage(url, 'eBay image', x, y);
    saveLayout();
    setStatus('Added from URL', 'ok');
    input.value = '';
  });
  document.getElementById('url-input').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') document.getElementById('url-add-btn').click();
  });

  /* ── SKU loading ─────────────────────────────────────────────── */
  document.getElementById('load-btn').addEventListener('click', function () {
    var v = document.getElementById('sku-input').value.trim().toUpperCase();
    if (v) window.location.search = 'sku=' + encodeURIComponent(v);
  });
  document.getElementById('sku-input').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') document.getElementById('load-btn').click();
  });

  /* ── Save / Clear buttons ────────────────────────────────────── */
  document.getElementById('save-btn').addEventListener('click', saveLayout);
  document.getElementById('clear-btn').addEventListener('click', function () {
    if (!images.length) return;
    if (!confirm('Remove all images from canvas?')) return;
    images = []; render();
  });

  /* ── UI helpers ──────────────────────────────────────────────── */
  function setStatus(msg, tone) {
    if (!statusChip) return;
    statusChip.textContent = msg;
    statusChip.className = 'badge ' + (tone || 'subtle');
  }
  function showMsg(msg) {
    var el = document.getElementById('upload-msg');
    el.textContent = msg; el.hidden = false;
    setTimeout(function () { el.hidden = true; }, 5000);
  }
  function hideMsg() { document.getElementById('upload-msg').hidden = true; }

  /* ── Init ────────────────────────────────────────────────────── */
  render();
})();
</script>
</body>
</html>
