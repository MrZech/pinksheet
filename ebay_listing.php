<?php
require_once __DIR__ . '/config.php';
checkMaintenance();
ensureStorageWritable();

const EBAY_LISTING_DB = __DIR__ . '/data/intake.sqlite';
const EBAY_PHOTO_DIR = __DIR__ . '/data/ebay_listing_photos';
const MAX_EBAY_IMAGE_BYTES = 16 * 1024 * 1024;
const ALLOWED_EBAY_MIME_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$currentPage = 'ebay_listing';
$statusMessage = '';
$currentSku = trim((string)($_GET['sku'] ?? ''));
$currentSkuNormalized = normalizeSku($currentSku);
$existingPhotos = [];
$listingImages = [];
$recentSkus = [];

if (is_readable(EBAY_LISTING_DB)) {
    try {
        $pdo = new PDO('sqlite:' . EBAY_LISTING_DB, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Ensure ebay_listing_images table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS ebay_listing_images (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sku_normalized TEXT NOT NULL,
            original_name TEXT NOT NULL,
            stored_name TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            file_size INTEGER NOT NULL DEFAULT 0,
            pos_x REAL NOT NULL DEFAULT 0,
            pos_y REAL NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ebay_listing_sku ON ebay_listing_images (sku_normalized)");

        // Recent SKUs for dropdown
        $recentStmt = $pdo->query("
            SELECT sku FROM intake_items
            WHERE sku IS NOT NULL AND TRIM(sku) <> ''
            ORDER BY updated_at DESC, id DESC LIMIT 60
        ");
        $recentSkus = array_values(array_filter(array_unique(
            array_map('trim', $recentStmt->fetchAll(PDO::FETCH_COLUMN))
        ), static fn($s) => $s !== ''));

        if ($currentSkuNormalized !== '') {
            // Load sku_photos for the thumbnail strip
            $photoStmt = $pdo->prepare("
                SELECT id, original_name, mime_type, file_size
                FROM sku_photos
                WHERE sku_normalized = :sku
                ORDER BY sort_order ASC, id ASC
            ");
            $photoStmt->execute(['sku' => $currentSkuNormalized]);
            $existingPhotos = $photoStmt->fetchAll();

            // Load saved eBay listing images with positions
            $listingStmt = $pdo->prepare("
                SELECT * FROM ebay_listing_images
                WHERE sku_normalized = :sku
                ORDER BY id ASC
            ");
            $listingStmt->execute(['sku' => $currentSkuNormalized]);
            $listingImages = $listingStmt->fetchAll();
        }
    } catch (Throwable $e) {
        $statusMessage = 'Database error: ' . $e->getMessage();
    }
}

// Build JSON for initial canvas state
$initialLayoutJson = json_encode(array_map(function ($img) {
    return [
        'id'      => (int)$img['id'],
        'src'     => 'ebay_photo_serve.php?id=' . (int)$img['id'],
        'name'    => $img['original_name'],
        'x'       => (float)$img['pos_x'],
        'y'       => (float)$img['pos_y'],
    ];
}, $listingImages));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>eBay Listing Image Composer - Dispo.Tech</title>
  <link rel="stylesheet" href="assets/style.css?v=<?= filemtime('assets/style.css') ?>">
  <script src="assets/menu.js?v=<?= filemtime('assets/menu.js') ?>" defer></script>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <script>window.CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;</script>
  <script src="assets/theme.js?v=<?= filemtime('assets/theme.js') ?>" defer></script>
  <style>
    /* ── eBay Listing Image Composer Styles ─────────────────── */
    .ebay-listing-shell .section h2 { margin-top: 0; }

    /* Canvas workspace */
    .ebay-canvas-wrap {
      position: relative;
      border: 2px dashed var(--line);
      border-radius: var(--radius-sm);
      background: var(--surface-secondary);
      min-height: 420px;
      margin: 12px 0;
      overflow: hidden;
      transition: border-color 160ms ease, background 160ms ease;
    }
    .ebay-canvas-wrap.is-drag-over {
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
      font-size: 0.95rem;
      pointer-events: none;
      gap: 6px;
    }
    .ebay-canvas-empty strong { font-size: 1.1rem; }

    /* Positioned images on the canvas */
    .ebay-canvas-image {
      position: absolute;
      cursor: grab;
      z-index: 1;
      border: 2px solid transparent;
      border-radius: 6px;
      overflow: hidden;
      background: var(--surface-primary);
      box-shadow: 0 2px 8px rgba(0,0,0,0.12);
      transition: border-color 120ms ease, box-shadow 120ms ease;
      user-select: none;
      touch-action: none;
    }
    .ebay-canvas-image:hover {
      border-color: var(--line-strong);
      z-index: 2;
    }
    .ebay-canvas-image.is-dragging {
      cursor: grabbing;
      z-index: 10;
      box-shadow: 0 6px 20px rgba(0,0,0,0.25);
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
      top: 4px;
      right: 4px;
      width: 24px;
      height: 24px;
      border: none;
      border-radius: 50%;
      background: rgba(0,0,0,0.55);
      color: #fff;
      font-size: 16px;
      line-height: 24px;
      text-align: center;
      cursor: pointer;
      opacity: 0;
      transition: opacity 120ms ease;
      padding: 0;
    }
    .ebay-canvas-image:hover .remove-btn { opacity: 1; }
    .ebay-canvas-image .remove-btn:hover { background: #d32f2f; }

    /* Thumbnail strip */
    .ebay-photo-strip {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      padding: 10px;
      border: 1px solid var(--line);
      border-radius: var(--radius-sm);
      background: var(--surface-primary);
      min-height: 80px;
      margin-top: 8px;
    }
    .ebay-photo-strip:empty::after {
      content: 'No existing photos for this SKU. Upload new ones below.';
      display: block;
      color: var(--muted);
      font-size: 0.85rem;
      width: 100%;
      text-align: center;
      padding: 12px 0;
    }
    .ebay-strip-item {
      width: 80px;
      height: 60px;
      border-radius: 4px;
      overflow: hidden;
      cursor: grab;
      border: 1px solid var(--line);
      background: var(--surface-secondary);
      flex-shrink: 0;
      transition: border-color 120ms ease;
    }
    .ebay-strip-item:hover { border-color: var(--accent-strong); }
    .ebay-strip-item.is-dragging { opacity: 0.4; }
    .ebay-strip-item img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      pointer-events: none;
    }

    /* Upload dropzone */
    .ebay-upload-zone {
      border: 2px dashed var(--line);
      border-radius: var(--radius-sm);
      padding: 24px 16px;
      text-align: center;
      color: var(--muted);
      background: var(--surface-secondary);
      cursor: pointer;
      transition: border-color 160ms ease, background 160ms ease;
      margin-top: 8px;
    }
    .ebay-upload-zone.is-hover {
      border-color: var(--accent-strong);
      background: var(--accent-primary-soft);
    }
    .ebay-upload-zone input[type="file"] { display: none; }

    /* Layout actions */
    .ebay-layout-actions {
      display: flex;
      gap: 8px;
      margin-top: 10px;
      flex-wrap: wrap;
    }

    /* Responsive two-column layout */
    .ebay-composer-grid {
      display: grid;
      grid-template-columns: 1fr 280px;
      gap: 20px;
      align-items: start;
    }
    .ebay-composer-grid .ebay-sidebar > .section {
      margin-bottom: 16px;
    }
    @media (max-width: 800px) {
      .ebay-composer-grid { grid-template-columns: 1fr; }
    }
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
          <li><a class="menu-link is-active" href="ebay_listing.php">Listing Images</a></li>
        </ul>
      </nav>
    </div>
    <main class="page">
      <section class="sheet home-sheet ebay-listing-shell">
        <header class="sheet-header">
          <div class="updated">eBay Listing Image Composer</div>
          <div class="sheet-header-right">
            <span class="autosave-status" id="autosave-indicator" hidden>Saved</span>
            <span class="badge subtle" id="status-chip">Ready</span>
            <button type="button" class="theme-toggle" id="theme-toggle">Dark mode</button>
          </div>
        </header>

        <h1>eBay Listing Image Composer</h1>
        <nav class="breadcrumbs" aria-label="Breadcrumb">
          <a href="home.php">Dashboard</a>
          <a href="prompt_builder.php">Script Builder</a>
          <span>Listing Images</span>
        </nav>
        <p class="lead">Arrange images for your eBay listing on the canvas. Drag from the thumbnail strip or upload new ones. Reposition freely; positions are saved automatically.</p>

        <?php if ($statusMessage !== ''): ?>
          <div class="alert-block" role="status">
            <div class="alert-item"><?php echo h($statusMessage); ?></div>
          </div>
        <?php endif; ?>

        <!-- SKU Selector -->
        <section class="section">
          <div class="row">
            <label>SKU
              <input type="text" id="ebay-sku-input" list="ebay-sku-suggestions" value="<?php echo h($currentSku); ?>" placeholder="Type or select a SKU">
            </label>
            <datalist id="ebay-sku-suggestions">
              <?php foreach ($recentSkus as $sku): ?>
                <option value="<?php echo h($sku); ?>">
              <?php endforeach; ?>
            </datalist>
            <div style="display: flex; align-items: flex-end; gap: 8px;">
              <button type="button" id="load-sku-btn">Load SKU</button>
              <a class="button-link subtle" href="prompt_builder.php<?php echo $currentSkuNormalized !== '' ? '?sku=' . urlencode($currentSkuNormalized) : ''; ?>">Open Script Builder</a>
            </div>
          </div>
        </section>

        <!-- Composer Grid: Canvas + Sidebar -->
        <div class="ebay-composer-grid">
          <!-- Left: Canvas -->
          <div class="ebay-canvas-area">
            <section class="section">
              <h2>Image Canvas</h2>
              <p class="hint">Drag images from the sidebar onto the canvas. Reposition by dragging within the canvas.</p>
              <div class="ebay-canvas-wrap" id="ebay-canvas">
                <div class="ebay-canvas-empty" id="ebay-canvas-empty">
                  <strong>Canvas is empty</strong>
                  <span>Drag photos from the sidebar or upload new ones</span>
                </div>
              </div>
              <div class="ebay-layout-actions">
                <button type="button" class="ghost" id="save-layout-btn">Save Positions</button>
                <button type="button" class="ghost" id="clear-canvas-btn">Clear Canvas</button>
                <span class="hint" id="layout-save-status"></span>
              </div>
            </section>
          </div>

          <!-- Right: Sidebar -->
          <div class="ebay-sidebar">
            <!-- Existing Photos Strip -->
            <section class="section">
              <h2>SKU Photos</h2>
              <p class="hint">Drag these onto the canvas</p>
              <div class="ebay-photo-strip" id="ebay-photo-strip">
                <?php foreach ($existingPhotos as $photo): ?>
                  <div class="ebay-strip-item" draggable="true" data-photo-id="<?php echo (int)$photo['id']; ?>">
                    <img src="photo.php?id=<?php echo (int)$photo['id']; ?>" alt="<?php echo h($photo['original_name'] ?? ''); ?>">
                  </div>
                <?php endforeach; ?>
              </div>
            </section>

            <!-- Upload -->
            <section class="section">
              <h2>Upload New Image</h2>
              <p class="hint">JPG, PNG, WebP, GIF &mdash; max 16 MB</p>
              <div class="ebay-upload-zone" id="ebay-upload-zone">
                <p><strong>Drop image here</strong></p>
                <p style="font-size:0.85rem;">or click to select a file</p>
                <input type="file" id="ebay-file-input" accept="image/jpeg,image/png,image/webp,image/gif">
              </div>
              <div id="ebay-upload-progress" style="display:none; margin-top:6px;">
                <div style="height:6px; background:var(--palette-teal-soft); border-radius:4px; overflow:hidden;">
                  <div id="ebay-upload-bar" style="height:100%; width:0%; background:var(--palette-teal); transition:width 200ms linear;"></div>
                </div>
                <span style="font-size:0.78rem; color:var(--muted);" id="ebay-upload-status"></span>
              </div>
              <div id="ebay-upload-error" class="error client-error" style="margin-top:6px;" hidden></div>
            </section>
          </div>
        </div>
      </section>
    </main>
  </div>

  <script>
  (function () {
    'use strict';

    /* ── State ─────────────────────────────────────────────────── */
    var currentSku = <?php echo json_encode($currentSkuNormalized); ?>;
    var layoutData = <?php echo $initialLayoutJson; ?>;  // loaded from DB
    var nextId = -1; // negative IDs for unsaved placeholder images
    var canvasEl = document.getElementById('ebay-canvas');
    var emptyHint = document.getElementById('ebay-canvas-empty');
    var statusChip = document.getElementById('status-chip');
    var saveStatus = document.getElementById('layout-save-status');
    var autosaveIndicator = document.getElementById('autosave-indicator');
    var stripEl = document.getElementById('ebay-photo-strip');
    var uploadZone = document.getElementById('ebay-upload-zone');
    var fileInput = document.getElementById('ebay-file-input');
    var uploadProgress = document.getElementById('ebay-upload-progress');
    var uploadBar = document.getElementById('ebay-upload-bar');
    var uploadStatus = document.getElementById('ebay-upload-status');
    var uploadError = document.getElementById('ebay-upload-error');

    /* ── Canvas: Render positioned images ──────────────────────── */
    function renderCanvas() {
      // Remove existing image divs (keep empty hint)
      var existing = canvasEl.querySelectorAll('.ebay-canvas-image');
      Array.prototype.forEach.call(existing, function (el) { el.remove(); });

      if (!layoutData.length) {
        emptyHint.hidden = false;
        return;
      }
      emptyHint.hidden = true;

      layoutData.forEach(function (img, idx) {
        var div = document.createElement('div');
        div.className = 'ebay-canvas-image';
        div.style.left = img.x + 'px';
        div.style.top  = img.y + 'px';
        div.dataset.index = idx;

        var removeBtn = document.createElement('button');
        removeBtn.className = 'remove-btn';
        removeBtn.type = 'button';
        removeBtn.innerHTML = '&times;';
        removeBtn.title = 'Remove from canvas (does not delete file)';
        removeBtn.addEventListener('click', function (e) {
          e.stopPropagation();
          removeImage(idx);
        });

        var imgEl = document.createElement('img');
        imgEl.src = img.src;
        imgEl.alt = img.name || 'Listing image';
        imgEl.draggable = false;

        div.appendChild(removeBtn);
        div.appendChild(imgEl);
        canvasEl.appendChild(div);

        // Attach reposition drag handlers
        makeDraggable(div, idx);
      });
    }

    /* ── Remove image from layout ───────────────────────────────── */
    function removeImage(idx) {
      layoutData.splice(idx, 1);
      renderCanvas();
      saveToLocalStorage();
    }

    /* ── Add image to layout ────────────────────────────────────── */
    function addImage(src, name, x, y) {
      var id = nextId--;
      layoutData.push({ id: id, src: src, name: name || 'Image', x: x || 40, y: y || 40 });
      renderCanvas();
      saveToLocalStorage();
    }

    /* ── Reposition Drag (mousedown/mousemove/mouseup) ─────────── */
    function makeDraggable(div, idx) {
      var startX, startY, origX, origY, rect;

      function onStart(e) {
        // Ignore if the target is the remove button
        if (e.target.closest('.remove-btn')) return;
        e.preventDefault();

        var clientX, clientY;
        if (e.type === 'touchstart') {
          clientX = e.touches[0].clientX;
          clientY = e.touches[0].clientY;
        } else {
          clientX = e.clientX;
          clientY = e.clientY;
        }

        rect = canvasEl.getBoundingClientRect();
        startX = clientX;
        startY = clientY;
        origX = layoutData[idx].x;
        origY = layoutData[idx].y;
        div.classList.add('is-dragging');

        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onEnd);
        document.addEventListener('touchmove', onMove, { passive: false });
        document.addEventListener('touchend', onEnd);
      }

      function onMove(e) {
        e.preventDefault();
        var clientX, clientY;
        if (e.type === 'touchmove') {
          clientX = e.touches[0].clientX;
          clientY = e.touches[0].clientY;
        } else {
          clientX = e.clientX;
          clientY = e.clientY;
        }

        var dx = clientX - startX;
        var dy = clientY - startY;

        // Clamp within canvas bounds (accounting for image width/height)
        var maxX = rect.width  - div.offsetWidth;
        var maxY = rect.height - div.offsetHeight;
        var newX = Math.max(0, Math.min(maxX, origX + dx));
        var newY = Math.max(0, Math.min(maxY, origY + dy));

        div.style.left = newX + 'px';
        div.style.top  = newY + 'px';
        layoutData[idx].x = newX;
        layoutData[idx].y = newY;
      }

      function onEnd(e) {
        div.classList.remove('is-dragging');
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onEnd);
        document.removeEventListener('touchmove', onMove);
        document.removeEventListener('touchend', onEnd);
        saveToLocalStorage();
        updateStatus('Position saved', 'ok');
      }

      div.addEventListener('mousedown', onStart);
      div.addEventListener('touchstart', onStart, { passive: false });
    }

    /* ── Add image from thumbnail strip (HTML5 DnD onto canvas) ── */
    canvasEl.addEventListener('dragover', function (e) {
      e.preventDefault();
      canvasEl.classList.add('is-drag-over');
    });
    canvasEl.addEventListener('dragleave', function (e) {
      if (!canvasEl.contains(e.relatedTarget)) {
        canvasEl.classList.remove('is-drag-over');
      }
    });
    canvasEl.addEventListener('drop', function (e) {
      e.preventDefault();
      canvasEl.classList.remove('is-drag-over');

      // File drop from desktop
      var files = e.dataTransfer.files;
      if (files && files.length > 0) {
        Array.prototype.forEach.call(files, function (file) {
          if (file.type.startsWith('image/')) {
            uploadFile(file, e.offsetX || 40, e.offsetY || 40);
          }
        });
        return;
      }

      // Thumbnail strip item drop
      var photoId = e.dataTransfer.getData('text/plain');
      if (photoId) {
        var rect = canvasEl.getBoundingClientRect();
        var x = e.clientX - rect.left;
        var y = e.clientY - rect.top;
        x = Math.max(0, Math.min(rect.width - 180, x));
        y = Math.max(0, Math.min(rect.height - 135, y));
        addImage('photo.php?id=' + encodeURIComponent(photoId), 'Photo #' + photoId, x, y);
        updateStatus('Image added to canvas', 'ok');
      }
    });

    /* ── Thumbnail strip drag start ────────────────────────────── */
    if (stripEl) {
      stripEl.addEventListener('dragstart', function (e) {
        var item = e.target.closest('.ebay-strip-item');
        if (!item) return;
        item.classList.add('is-dragging');
        var pid = item.getAttribute('data-photo-id');
        e.dataTransfer.setData('text/plain', pid || '');
        e.dataTransfer.effectAllowed = 'copy';
      });
      stripEl.addEventListener('dragend', function (e) {
        var item = e.target.closest('.ebay-strip-item');
        if (item) item.classList.remove('is-dragging');
      });
    }

    /* ── AJAX Image Upload (Feature 2) ─────────────────────────── */
    var isUploading = false;

    function uploadFile(file, offsetX, offsetY) {
      if (!currentSku) {
        setUploadError('Select a SKU first.');
        return;
      }
      if (isUploading) return;
      isUploading = true;

      var fd = new FormData();
      fd.append('photo', file);
      fd.append('sku', currentSku);
      fd.append('csrf_token', window.CSRF_TOKEN);

      uploadProgress.style.display = 'block';
      uploadBar.style.width = '0%';
      uploadStatus.textContent = 'Uploading ' + (file.name || 'image') + '...';
      uploadError.hidden = true;

      var xhr = new XMLHttpRequest();
      xhr.open('POST', 'upload_ebay_image.php');

      xhr.upload.onprogress = function (e) {
        if (e.lengthComputable) {
          var pct = Math.round((e.loaded / e.total) * 100);
          uploadBar.style.width = pct + '%';
        }
      };

      xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4) return;
        isUploading = false;
        uploadProgress.style.display = 'none';

        if (xhr.status >= 200 && xhr.status < 300) {
          var resp;
          try { resp = JSON.parse(xhr.responseText); } catch (err) { resp = { status: 'error' }; }

          if (resp.status === 'ok') {
            var x = (typeof offsetX === 'number') ? offsetX : 40;
            var y = (typeof offsetY === 'number') ? offsetY : 40;
            addImage(resp.url || ('ebay_photo_serve.php?id=' + resp.id), resp.name || file.name, x, y);
            updateStatus('Uploaded: ' + (file.name || 'image'), 'ok');
          } else {
            setUploadError(resp.message || 'Upload failed');
          }
        } else {
          setUploadError('Server error (HTTP ' + xhr.status + ')');
        }
      };

      xhr.onerror = function () {
        isUploading = false;
        uploadProgress.style.display = 'none';
        setUploadError('Network error');
      };

      xhr.send(fd);
    }

    function setUploadError(msg) {
      uploadError.textContent = msg;
      uploadError.hidden = false;
      setTimeout(function () { uploadError.hidden = true; }, 5000);
    }

    // Upload zone: drag-and-drop files
    uploadZone.addEventListener('dragover', function (e) { e.preventDefault(); uploadZone.classList.add('is-hover'); });
    uploadZone.addEventListener('dragleave', function (e) { uploadZone.classList.remove('is-hover'); });
    uploadZone.addEventListener('drop', function (e) {
      e.preventDefault();
      uploadZone.classList.remove('is-hover');
      var files = e.dataTransfer.files;
      if (files && files.length > 0) {
        Array.prototype.forEach.call(files, function (f) {
          if (f.type.startsWith('image/')) uploadFile(f);
        });
      }
    });
    // Click to upload
    uploadZone.addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () {
      if (this.files && this.files.length > 0) {
        Array.prototype.forEach.call(this.files, function (f) { uploadFile(f); });
        this.value = '';
      }
    });

    /* ── Save layout to PHP backend ────────────────────────────── */
    function saveLayoutToServer(callback) {
      if (!currentSku || !layoutData.length) {
        if (callback) callback();
        return;
      }

      // Only send images that came from the server (positive IDs) or new ones
      var positions = layoutData.map(function (img) {
        return { id: img.id, x: img.x, y: img.y };
      });

      var fd = new FormData();
      fd.append('sku', currentSku);
      fd.append('csrf_token', window.CSRF_TOKEN);
      fd.append('positions', JSON.stringify(positions));

      var xhr = new XMLHttpRequest();
      xhr.open('POST', 'save_ebay_layout.php');
      xhr.onreadystatechange = function () {
        if (xhr.readyState === 4) {
          if (xhr.status >= 200 && xhr.status < 300) {
            if (callback) callback();
          } else {
            updateStatus('Save failed', 'warn');
          }
        }
      };
      xhr.send(fd);
    }

    /* ── localStorage persistence ───────────────────────────────── */
    function saveToLocalStorage() {
      try {
        var key = 'ebay_layout_' + currentSku;
        localStorage.setItem(key, JSON.stringify(layoutData));
      } catch (_) {}
    }

    function loadFromLocalStorage() {
      if (!currentSku) return null;
      try {
        var key = 'ebay_layout_' + currentSku;
        var data = localStorage.getItem(key);
        return data ? JSON.parse(data) : null;
      } catch (_) { return null; }
    }

    /* ── UI helpers ─────────────────────────────────────────────── */
    function updateStatus(msg, tone) {
      if (!statusChip) return;
      statusChip.textContent = msg;
      statusChip.className = 'badge ' + (tone || 'subtle');
    }

    function setAutosaveIndicator(visible) {
      if (autosaveIndicator) {
        autosaveIndicator.hidden = !visible;
        if (visible) {
          autosaveIndicator.textContent = 'Auto-saved ' + new Date().toLocaleTimeString();
        }
      }
    }

    /* ── SKU loading ────────────────────────────────────────────── */
    function loadSku(sku) {
      if (!sku) return;
      currentSku = sku.toUpperCase().trim();
      window.location.search = 'sku=' + encodeURIComponent(currentSku);
    }

    document.getElementById('load-sku-btn').addEventListener('click', function () {
      var input = document.getElementById('ebay-sku-input');
      loadSku(input.value);
    });
    document.getElementById('ebay-sku-input').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        loadSku(this.value);
      }
    });

    /* ── Save Layout button ─────────────────────────────────────── */
    document.getElementById('save-layout-btn').addEventListener('click', function () {
      saveLayoutToServer(function () {
        updateStatus('Layout saved', 'ok');
        saveToLocalStorage();
      });
    });

    /* ── Clear Canvas button ────────────────────────────────────── */
    document.getElementById('clear-canvas-btn').addEventListener('click', function () {
      if (!layoutData.length) return;
      if (!confirm('Remove all images from the canvas?')) return;
      layoutData = [];
      renderCanvas();
      saveToLocalStorage();
      updateStatus('Canvas cleared', 'warn');
    });

    /* ── Init ───────────────────────────────────────────────────── */
    // Restore from localStorage first (faster), fall back to server data
    var localLayout = loadFromLocalStorage();
    if (localLayout && localLayout.length > 0) {
      // Merge: use server IDs but localStorage positions for unsaved changes
      // Simple approach: if localStorage has data, trust it over initial JSON
      // (since localStorage is updated on every move)
      var serverIds = {};
      layoutData.forEach(function (img) { serverIds[img.id] = true; });

      var merged = [];
      localLayout.forEach(function (local) {
        if (serverIds[local.id] || local.id < 0) {
          merged.push(local);
        }
      });
      // Add any server images not in localStorage
      layoutData.forEach(function (srv) {
        if (!merged.some(function (m) { return m.id === srv.id; })) {
          merged.push(srv);
        }
      });
      layoutData = merged;
    }

    renderCanvas();

    // Periodic auto-save to server every 30 seconds when layout changes
    var layoutDirty = false;
    var origAddImage = addImage;
    addImage = function () {
      origAddImage.apply(null, arguments);
      layoutDirty = true;
      setAutosaveIndicator(false);
    };
    var origRemoveImage = removeImage;
    removeImage = function () {
      origRemoveImage.apply(null, arguments);
      layoutDirty = true;
      setAutosaveIndicator(false);
    };

    setInterval(function () {
      if (layoutDirty && currentSku) {
        saveLayoutToServer(function () {
          layoutDirty = false;
          setAutosaveIndicator(true);
        });
      }
    }, 30000);
  })();
  </script>
</body>
</html>
