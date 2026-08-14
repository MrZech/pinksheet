<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance();
ensureStorageWritable();

const DB_PATH = __DIR__ . '/data/intake.sqlite';
const PHOTO_UPLOAD_DIR = __DIR__ . '/data/sku_photos';

$sku = isset($_GET['sku']) ? normalizeSku((string)$_GET['sku']) : '';

if ($sku === '') {
    http_response_code(400);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Invalid Link</title><style>body{font-family:sans-serif;padding:20px;text-align:center;color:#333}h1{font-size:1.2rem}p{color:#666}</style></head><body><h1>Invalid link</h1><p>This QR code link is missing the SKU parameter.</p></body></html>';
    exit;
}

try {
    $pdo = pdoConnect(DB_PATH);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Database error');
}

// Look up item
$stmt = $pdo->prepare("SELECT id, sku, sku_normalized, status, what_is_it, notes, dispotech_price, reviewed, created_at, updated_at FROM intake_items WHERE sku_normalized = :sku OR sku = :sku2 LIMIT 1");
$stmt->execute(['sku' => $sku, 'sku2' => $sku]);
$item = $stmt->fetch();

if (!$item) {
    http_response_code(404);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Not Found</title><style>body{font-family:sans-serif;padding:20px;text-align:center;color:#333}h1{font-size:1.2rem}p{color:#666}</style></head><body><h1>Item not found</h1><p>SKU ' . htmlspecialchars($sku, ENT_QUOTES, 'UTF-8') . ' was not found in the database.</p></body></html>';
    exit;
}

// Look up photos
$photoStmt = $pdo->prepare("SELECT id, original_name, mime_type, file_size, is_thumb FROM sku_photos WHERE sku_normalized = :sku ORDER BY sort_order ASC, id ASC LIMIT 20");
$photoStmt->execute(['sku' => $sku]);
$photos = $photoStmt->fetchAll();

$displaySku = htmlspecialchars($item['sku'] ?? $sku, ENT_QUOTES, 'UTF-8');
$whatIsIt = htmlspecialchars($item['what_is_it'] ?? '', ENT_QUOTES, 'UTF-8');
$notes = htmlspecialchars($item['notes'] ?? '', ENT_QUOTES, 'UTF-8');
$status = htmlspecialchars($item['status'] ?? '', ENT_QUOTES, 'UTF-8');
$price = ($item['dispotech_price'] ?? '') !== '' ? '$' . number_format((float)$item['dispotech_price'], 2) : '';
$reviewedVal = (int)($item['reviewed'] ?? 0);
if ($reviewedVal === 2) {
    $reviewedLabel = 'SOLD';
} elseif ($reviewedVal === 1) {
    $reviewedLabel = 'ACTIVE';
} else {
    $reviewedLabel = 'INACTIVE';
}
$statusRawLower = strtolower(trim((string)($item['status'] ?? '')));
$statusOptions = ['intake', 'ebay draft', 'ebay listed', 'ebay review', 'dispo tech store', 'ready', 'sold'];
$uploadToken = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <meta name="theme-color" content="#121358" media="(prefers-color-scheme: light)">
  <meta name="theme-color" content="#0f1117" media="(prefers-color-scheme: dark)">
  <title>Photo Upload · <?= $displaySku ?></title>
  <script src="assets/compressor.min.js?v=<?php echo getAssetVersion(); ?>"></script>
  <style>
    :root {
      --bg: #f0f4f8;
      --card-bg: #fff;
      --text: #0f172a;
      --muted: #475569;
      --faint: #64748b;
      --accent: #121358;
      --accent-hover: #232f72;
      --accent-active: #2f578a;
      --border: #e2e8f0;
      --ok-bg: #e2f0d9;
      --ok-text: #385723;
      --err-bg: #fef2f2;
      --err-text: #991b1b;
      --badge-sold-bg: #e0f2fe;
      --badge-sold-text: #0369a1;
      --badge-active-bg: #e2f0d9;
      --badge-active-text: #385723;
      --badge-inactive-bg: #f1f5f9;
      --badge-inactive-text: #475569;
      --badge-status-bg: rgba(47,87,138,0.1);
      --badge-status-text: #232f72;
    }
    @media (prefers-color-scheme: dark) {
      :root {
        --bg: #0f1117;
        --card-bg: #1c2030;
        --text: #e8eaf0;
        --muted: #9ba3b5;
        --faint: #8a93a6;
        --accent: #2f578a;
        --accent-hover: #3a6aa0;
        --accent-active: #4a7cb8;
        --border: #2a3044;
        --ok-bg: rgba(56,87,35,0.3);
        --ok-text: #a7c98a;
        --err-bg: rgba(185,28,28,0.25);
        --err-text: #f0a0a0;
        --badge-sold-bg: rgba(3,105,161,0.25);
        --badge-sold-text: #7cc4e8;
        --badge-active-bg: rgba(56,87,35,0.3);
        --badge-active-text: #a7c98a;
        --badge-inactive-bg: rgba(148,163,184,0.15);
        --badge-inactive-text: #9ba3b5;
        --badge-status-bg: rgba(74,124,184,0.2);
        --badge-status-text: #9ec0e8;
      }
    }
    *, *::before, *::after { box-sizing: border-box; }
    html, body {
      margin: 0; padding: 0;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      font-size: 16px;
      line-height: 1.5;
      background: var(--bg);
      color: var(--text);
      -webkit-text-size-adjust: 100%;
    }
    .container {
      max-width: 480px;
      margin: 0 auto;
      padding: 16px;
    }
    .card {
      background: var(--card-bg);
      border-radius: 12px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
      padding: 16px;
      margin-bottom: 16px;
    }
    .card h1 {
      margin: 0 0 4px;
      font-size: 1.1rem;
      font-weight: 700;
    }
    .card .sku { font-size: 1.3rem; font-weight: 800; color: var(--accent); margin-bottom: 4px; }
    .card .what { font-size: 0.9rem; color: var(--muted); margin-bottom: 8px; }
    .card .meta { font-size: 0.82rem; color: var(--faint); display: flex; gap: 8px; flex-wrap: wrap; }
    .badge {
      display: inline-block;
      padding: 3px 8px;
      border-radius: 4px;
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    .badge-sold { background: var(--badge-sold-bg); color: var(--badge-sold-text); }
    .badge-active { background: var(--badge-active-bg); color: var(--badge-active-text); }
    .badge-inactive { background: var(--badge-inactive-bg); color: var(--badge-inactive-text); }
    .badge-status { background: var(--badge-status-bg); color: var(--badge-status-text); }
    .photo-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
      gap: 8px;
      margin-top: 10px;
    }
    .photo-grid a {
      display: block;
      border-radius: 8px;
      overflow: hidden;
      background: var(--bg);
    }
    .photo-grid img {
      width: 100%;
      height: 100px;
      object-fit: cover;
      display: block;
      border: 1px solid var(--border);
      border-radius: 8px;
    }
    .upload-section {
      margin-top: 16px;
    }
    .upload-section label {
      display: block;
      font-weight: 700;
      font-size: 0.85rem;
      margin-bottom: 12px;
      color: var(--text);
    }
    .btn-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
    }
    .camera-btn {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      width: 100%;
      padding: 16px;
      background: var(--accent);
      color: #fff;
      border: none;
      border-radius: 12px;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.15s ease;
    }
    .camera-btn:hover { background: var(--accent-hover); }
    .camera-btn:active { background: var(--accent-active); }
    .camera-btn svg { width: 24px; height: 24px; flex-shrink: 0; }
    .gallery-btn {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      width: 100%;
      padding: 16px;
      background: transparent;
      color: var(--accent);
      border: 2px solid var(--accent);
      border-radius: 12px;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.15s ease;
    }
    .gallery-btn:hover { background: var(--badge-status-bg); }
    .gallery-btn svg { width: 24px; height: 24px; flex-shrink: 0; }
    .upload-status {
      margin-top: 12px;
      padding: 10px 14px;
      border-radius: 8px;
      font-size: 0.88rem;
      font-weight: 600;
      display: none;
    }
    .upload-status.is-visible { display: block; }
    .upload-status.ok { background: var(--ok-bg); color: var(--ok-text); border: 1px solid var(--border); }
    .upload-status.err { background: var(--err-bg); color: var(--err-text); border: 1px solid var(--border); }
    .file-input-wrap { display: none; }
    .spinner {
      display: inline-block;
      width: 18px;
      height: 18px;
      border: 2px solid rgba(255,255,255,0.3);
      border-top-color: #fff;
      border-radius: 50%;
      animation: spin 0.6s linear infinite;
      vertical-align: middle;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .notes-section {
      margin-top: 8px;
      font-size: 0.85rem;
      color: var(--muted);
      white-space: pre-wrap;
      word-break: break-word;
    }
    .edit-section { margin-top: 16px; }
    .edit-label { display: block; font-weight: 700; font-size: 0.85rem; margin-bottom: 12px; color: var(--text); }
    .edit-field { margin-bottom: 12px; }
    .edit-field label { display: block; font-size: 0.78rem; font-weight: 600; color: var(--faint); margin-bottom: 4px; }
    .edit-field select, .edit-field input, .edit-field textarea {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid var(--border);
      border-radius: 8px;
      background: var(--bg);
      color: var(--text);
      font-size: 1rem;
      font-family: inherit;
    }
    .edit-field select:focus, .edit-field input:focus, .edit-field textarea:focus { outline: none; border-color: var(--accent); }
    .edit-field textarea { resize: vertical; min-height: 64px; }
    .mark-sold-btn {
      width: 100%;
      padding: 12px;
      background: var(--err-bg);
      color: var(--err-text);
      border: 1px solid var(--err-text);
      border-radius: 10px;
      font-size: 1rem;
      font-weight: 700;
      cursor: pointer;
      transition: opacity 0.15s ease;
    }
    .mark-sold-btn:active { opacity: 0.8; }
    .edit-status { margin-top: 10px; font-size: 0.85rem; font-weight: 600; display: none; }
    .edit-status.is-visible { display: block; }
    .edit-status.ok { color: var(--ok-text); }
    .edit-status.err { color: var(--err-text); }
    .success-msg {
      text-align: center;
      padding: 20px;
    }
    .success-msg .check { font-size: 3rem; margin-bottom: 8px; }
    .success-msg h2 { margin: 0 0 4px; font-size: 1.2rem; }
    .success-msg p { color: var(--muted); font-size: 0.9rem; margin: 0; }
  </style>
</head>
<body>
  <div class="container">
    <div class="card">
      <div class="sku"><?= $displaySku ?></div>
      <?php if ($whatIsIt): ?><div class="what"><?= $whatIsIt ?></div><?php endif; ?>
      <div class="meta">
        <span class="badge badge-<?= $reviewedVal === 2 ? 'sold' : ($reviewedVal === 1 ? 'active' : 'inactive') ?>"><?= $reviewedLabel ?></span>
        <?php if ($status): ?><span class="badge badge-status"><?= $status ?></span><?php endif; ?>
        <?php if ($price): ?><span><?= $price ?></span><?php endif; ?>
      </div>
      <?php if ($notes): ?><div class="notes-section"><?= nl2br($notes) ?></div><?php endif; ?>
    </div>

    <div class="card edit-section">
      <label class="edit-label">Edit item</label>
      <div class="edit-field">
        <label for="status-select">Status</label>
        <select id="status-select">
          <?php foreach ($statusOptions as $opt): ?>
            <option value="<?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?>"<?= $statusRawLower === $opt ? ' selected' : '' ?>><?= htmlspecialchars(ucwords($opt), ENT_QUOTES, 'UTF-8') ?></option>
          <?php endforeach; ?>
          <?php if ($statusRawLower !== '' && !in_array($statusRawLower, $statusOptions, true)): ?>
            <option value="<?= htmlspecialchars((string)($item['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" selected><?= htmlspecialchars((string)($item['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
          <?php endif; ?>
        </select>
      </div>
      <div class="edit-field">
        <label for="price-input">Price ($)</label>
        <input type="number" id="price-input" inputmode="decimal" step="0.01" min="0" value="<?= htmlspecialchars((string)($item['dispotech_price'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
      </div>
      <div class="edit-field">
        <label for="notes-input">Notes</label>
        <textarea id="notes-input" rows="3" placeholder="Add notes…"><?= $notes ?></textarea>
      </div>
      <button type="button" class="mark-sold-btn" id="mark-sold-btn">Mark Sold</button>
      <div class="edit-status" id="edit-status"></div>
    </div>

    <?php if ($photos): ?>
    <div class="card">
      <strong style="font-size:0.85rem;color:var(--faint);">Photos (<?= count($photos) ?>)</strong>
      <div class="photo-grid">
        <?php foreach ($photos as $photo): ?>
          <a href="photo.php?id=<?= (int)$photo['id'] ?>" target="_blank" rel="noopener" title="Open full photo">
            <img src="photo.php?id=<?= (int)$photo['id'] ?>&thumb=1" alt="" loading="lazy">
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card upload-section">
      <label>Add photos for this item</label>
      <div class="btn-row">
        <button type="button" class="camera-btn" id="capture-btn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
          Take Photo
        </button>
        <button type="button" class="gallery-btn" id="gallery-btn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
          From Gallery
        </button>
      </div>
      <div class="file-input-wrap">
        <input type="file" id="photo-input" accept="image/*" capture="environment">
        <input type="file" id="gallery-input" accept="image/*" multiple>
      </div>
      <div class="upload-status" id="upload-status"></div>
    </div>
  </div>

  <script>
    (function () {
      var captureBtn = document.getElementById('capture-btn');
      var galleryBtn = document.getElementById('gallery-btn');
      var photoInput = document.getElementById('photo-input');
      var galleryInput = document.getElementById('gallery-input');
      var statusEl = document.getElementById('upload-status');
      var sku = <?= json_encode($item['sku_normalized'] ?? $item['sku'] ?? '') ?>;
      var csrfToken = <?= json_encode($uploadToken) ?>;
      var queue = [];
      var uploading = false;

      var setStatus = function (msg, type) {
        if (!statusEl) return;
        statusEl.textContent = msg;
        statusEl.className = 'upload-status is-visible';
        if (type) statusEl.classList.add(type);
      };

      var queueFiles = function (files) {
        if (!files || files.length === 0) return;
        for (var i = 0; i < files.length; i++) queue.push(files[i]);
        if (!uploading) processQueue();
      };

      var processQueue = function () {
        if (queue.length === 0) {
          uploading = false;
          setStatus('All photos uploaded!', 'ok');
          setTimeout(function () { window.location.reload(); }, 1500);
          return;
        }
        uploading = true;
        var file = queue.shift();
        var total = queue.length + 1;
        setStatus('Compressing (' + (total - queue.length) + ' of ' + total + ')...');

        new Compressor(file, {
          quality: 0.7,
          maxWidth: 1920,
          maxHeight: 1920,
          convertSize: 0,
          success: function (compressed) {
            setStatus('Uploading (' + (total - queue.length) + ' of ' + total + ')... ' + Math.round(compressed.size / 1024) + ' KB');
            var fd = new FormData();
            fd.append('sku', sku);
            fd.append('photo', compressed, file.name || 'photo.jpg');
            fd.append('csrf_token', csrfToken);

            fetch('upload_photo.php', {
              method: 'POST',
              body: fd
            })
              .then(function (r) { return r.json(); })
              .then(function (data) {
                if (data.status === 'ok') {
                  processQueue();
                } else {
                  setStatus('Upload failed: ' + (data.message || 'unknown error'), 'err');
                  uploading = false;
                }
              })
              .catch(function () {
                setStatus('Upload failed: network error', 'err');
                uploading = false;
              });
          },
          error: function (err) {
            setStatus('Compression failed: ' + (err.message || 'unknown error'), 'err');
            uploading = false;
          }
        });
      };

      if (captureBtn && photoInput) {
        captureBtn.addEventListener('click', function () {
          photoInput.click();
        });
        photoInput.addEventListener('change', function () {
          if (photoInput.files && photoInput.files.length > 0) {
            queueFiles(photoInput.files);
            photoInput.value = '';
          }
        });
      }

      if (galleryBtn && galleryInput) {
        galleryBtn.addEventListener('click', function () {
          galleryInput.click();
        });
        galleryInput.addEventListener('change', function () {
          if (galleryInput.files && galleryInput.files.length > 0) {
            queueFiles(galleryInput.files);
            galleryInput.value = '';
          }
        });
      }

      /* ── Item edit (status / price / notes) ─────────────── */
      var statusSelect = document.getElementById('status-select');
      var priceInput = document.getElementById('price-input');
      var notesInput = document.getElementById('notes-input');
      var markSoldBtn = document.getElementById('mark-sold-btn');
      var editStatusEl = document.getElementById('edit-status');

      var setEditStatus = function (msg, tone) {
        if (!editStatusEl) return;
        editStatusEl.textContent = msg;
        editStatusEl.className = 'edit-status is-visible' + (tone ? ' ' + tone : '');
      };

      var saveField = function (field, value, cb) {
        setEditStatus('Saving…');
        fetch('update_item.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'sku=' + encodeURIComponent(sku) + '&field=' + encodeURIComponent(field) + '&value=' + encodeURIComponent(value) + '&csrf_token=' + encodeURIComponent(csrfToken)
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.ok) {
              setEditStatus('Saved', 'ok');
              if (cb) cb(true);
            } else {
              setEditStatus('Failed: ' + (data.error || 'error'), 'err');
              if (cb) cb(false);
            }
          })
          .catch(function () {
            setEditStatus('Failed: network error', 'err');
            if (cb) cb(false);
          });
      };

      if (statusSelect) {
        statusSelect.addEventListener('change', function () {
          var v = statusSelect.value;
          if (v === '') return;
          saveField('status', v, function (ok) {
            if (ok && v === 'sold') {
              saveField('reviewed', '2', function () {
                setEditStatus('Saved', 'ok');
                setTimeout(function () { window.location.reload(); }, 900);
              });
            }
          });
        });
      }

      if (priceInput) {
        priceInput.addEventListener('change', function () {
          saveField('dispotech_price', priceInput.value);
        });
      }

      if (notesInput) {
        notesInput.addEventListener('blur', function () {
          saveField('notes', notesInput.value);
        });
      }

      if (markSoldBtn) {
        markSoldBtn.addEventListener('click', function () {
          if (statusSelect) statusSelect.value = 'sold';
          saveField('status', 'sold', function (ok) {
            if (ok) {
              saveField('reviewed', '2', function () {
                setEditStatus('Marked sold', 'ok');
                setTimeout(function () { window.location.reload(); }, 900);
              });
            }
          });
        });
      }
    })();
  </script>
</body>
</html>
