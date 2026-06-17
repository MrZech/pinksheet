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
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
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
$photoStmt = $pdo->prepare("SELECT id, original_name, mime_type, file_size, is_thumb FROM sku_photos WHERE sku_normalized = :sku ORDER BY is_thumb DESC, id DESC LIMIT 20");
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
$uploadToken = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
  <meta name="theme-color" content="#121358">
  <title>Photo Upload · <?= $displaySku ?></title>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/compressorjs/1.2.1/compressor.min.js" integrity="sha512-kT2NQK+9YIaaZCs5YhRNnvs7y3D4A/VgBiJqnLgP1q4tVKvq2U2b7/YCxDDxEtfnX9clDW+F5LGIFOjz7ALjg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    html, body {
      margin: 0; padding: 0;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      font-size: 16px;
      line-height: 1.5;
      background: #f0f4f8;
      color: #0f172a;
      -webkit-text-size-adjust: 100%;
    }
    .container {
      max-width: 480px;
      margin: 0 auto;
      padding: 16px;
    }
    .card {
      background: #fff;
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
    .card .sku { font-size: 1.3rem; font-weight: 800; color: #121358; margin-bottom: 4px; }
    .card .what { font-size: 0.9rem; color: #475569; margin-bottom: 8px; }
    .card .meta { font-size: 0.82rem; color: #64748b; display: flex; gap: 8px; flex-wrap: wrap; }
    .badge {
      display: inline-block;
      padding: 3px 8px;
      border-radius: 4px;
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    .badge-sold { background: #e0f2fe; color: #0369a1; }
    .badge-active { background: #e2f0d9; color: #385723; }
    .badge-inactive { background: #f1f5f9; color: #475569; }
    .badge-status { background: rgba(47,87,138,0.1); color: #232f72; }
    .photo-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
      gap: 8px;
      margin-top: 10px;
    }
    .photo-grid img {
      width: 100%;
      height: 100px;
      object-fit: cover;
      border-radius: 8px;
      border: 1px solid #e2e8f0;
    }
    .upload-section {
      margin-top: 16px;
    }
    .upload-section label {
      display: block;
      font-weight: 700;
      font-size: 0.85rem;
      margin-bottom: 8px;
      color: #0f172a;
    }
    .camera-btn {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      width: 100%;
      padding: 16px;
      background: #121358;
      color: #fff;
      border: none;
      border-radius: 12px;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.15s ease;
    }
    .camera-btn:hover { background: #232f72; }
    .camera-btn:active { background: #2f578a; }
    .camera-btn svg { width: 24px; height: 24px; flex-shrink: 0; }
    .upload-status {
      margin-top: 12px;
      padding: 10px 14px;
      border-radius: 8px;
      font-size: 0.88rem;
      font-weight: 600;
      display: none;
    }
    .upload-status.is-visible { display: block; }
    .upload-status.ok { background: #e2f0d9; color: #385723; border: 1px solid rgba(56,87,35,0.2); }
    .upload-status.err { background: #fef2f2; color: #991b1b; border: 1px solid rgba(185,28,28,0.2); }
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
      color: #475569;
      white-space: pre-wrap;
      word-break: break-word;
    }
    .success-msg {
      text-align: center;
      padding: 20px;
    }
    .success-msg .check { font-size: 3rem; margin-bottom: 8px; }
    .success-msg h2 { margin: 0 0 4px; font-size: 1.2rem; }
    .success-msg p { color: #475569; font-size: 0.9rem; margin: 0; }
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

    <?php if ($photos): ?>
    <div class="card">
      <strong style="font-size:0.85rem;color:#475569;">Photos (<?= count($photos) ?>)</strong>
      <div class="photo-grid">
        <?php foreach ($photos as $photo): ?>
          <img src="photo.php?id=<?= (int)$photo['id'] ?>" alt="" loading="lazy">
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card upload-section">
      <label>Upload a new photo</label>
      <button type="button" class="camera-btn" id="capture-btn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
        Take Photo
      </button>
      <div class="file-input-wrap">
        <input type="file" id="photo-input" accept="image/*" capture="environment">
      </div>
      <div class="upload-status" id="upload-status"></div>
    </div>
  </div>

  <script>
    (function () {
      var captureBtn = document.getElementById('capture-btn');
      var photoInput = document.getElementById('photo-input');
      var statusEl = document.getElementById('upload-status');
      var sku = <?= json_encode($item['sku_normalized'] ?? $item['sku'] ?? '') ?>;
      var csrfToken = <?= json_encode($uploadToken) ?>;

      var setStatus = function (msg, type) {
        if (!statusEl) return;
        statusEl.textContent = msg;
        statusEl.className = 'upload-status is-visible';
        if (type) statusEl.classList.add(type);
      };

      var uploadPhoto = function (file) {
        if (!file) return;
        setStatus('Compressing...', '');

        new Compressor(file, {
          quality: 0.7,
          maxWidth: 1920,
          maxHeight: 1920,
          convertSize: 0,
          success: function (compressed) {
            setStatus('Uploading... (' + Math.round(compressed.size / 1024) + ' KB)', '');
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
                  setStatus('Photo uploaded successfully!', 'ok');
                  setTimeout(function () { window.location.reload(); }, 1500);
                } else {
                  setStatus('Upload failed: ' + (data.message || 'unknown error'), 'err');
                }
              })
              .catch(function () {
                setStatus('Upload failed: network error', 'err');
              });
          },
          error: function (err) {
            setStatus('Compression failed: ' + (err.message || 'unknown error'), 'err');
          }
        });
      };

      if (captureBtn && photoInput) {
        captureBtn.addEventListener('click', function () {
          photoInput.click();
        });

        photoInput.addEventListener('change', function () {
          if (photoInput.files && photoInput.files.length > 0) {
            uploadPhoto(photoInput.files[0]);
          }
        });
      }
    })();
  </script>
</body>
</html>
