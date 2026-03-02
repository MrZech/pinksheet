<?php
// Simple intake sheet app backed by SQLite


const DB_DIR = __DIR__ . '/data';
const DB_PATH = __DIR__ . '/data/intake.sqlite';

if (!is_dir(DB_DIR)) {
    mkdir(DB_DIR, 0777, true);
}

$pdo = new PDO('sqlite:' . DB_PATH, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS intake_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    sku TEXT,
    status TEXT,
    what_is_it TEXT,
    date_received TEXT,
    source TEXT,
    functional TEXT,
    condition TEXT,
    is_square INTEGER,
    care_if_square INTEGER,
    cords_adapters TEXT,
    keep_items_together TEXT,
    picture_taken TEXT,
    power_on TEXT,
    brand_model TEXT,
    ram TEXT,
    ssd_gb TEXT,
    cpu TEXT,
    battery_health TEXT,
    graphics_card TEXT,
    screen_resolution TEXT,
    where_it_goes TEXT,
    ebay_status TEXT,
    ebay_price REAL,
    dispotech_price REAL,
    in_ebay_room TEXT,
    what_box TEXT,
    notes TEXT
);
SQL);

$columns = $pdo->query("PRAGMA table_info(intake_items)")->fetchAll(PDO::FETCH_ASSOC);
$columnNames = array_map(static fn(array $column): string => (string)$column['name'], $columns);
if (!in_array('sku_normalized', $columnNames, true)) {
    $pdo->exec('ALTER TABLE intake_items ADD COLUMN sku_normalized TEXT');
}
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_intake_items_sku_normalized ON intake_items (sku_normalized)");
$pdo->exec("UPDATE intake_items SET sku_normalized = UPPER(TRIM(COALESCE(sku, ''))) WHERE sku_normalized IS NULL OR sku_normalized = ''");

function normalizeSku(string $sku): string
{
    return strtoupper(trim($sku));
}

function statusOptions(): array
{
    return ['Intake', 'Description', 'Tested', 'Listed', 'SOLD'];
}

$saved = isset($_GET['saved']);
$saveMode = trim($_GET['save_mode'] ?? '');
$errors = [];
$statusOptions = statusOptions();
$lookupSku = trim($_GET['sku'] ?? '');
$lookupSkuNormalized = normalizeSku($lookupSku);
$lookupStatus = trim($_GET['status'] ?? '');
if ($lookupStatus !== '' && !in_array($lookupStatus, $statusOptions, true)) {
    $lookupStatus = '';
}
$currentItem = null;
$duplicateCount = 0;

if ($lookupSkuNormalized !== '') {
    $stmt = $pdo->prepare('SELECT * FROM intake_items WHERE sku_normalized = :sku_normalized ORDER BY id DESC LIMIT 1');
    $stmt->execute(['sku_normalized' => $lookupSkuNormalized]);
    $currentItem = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM intake_items WHERE sku_normalized = :sku_normalized');
    $countStmt->execute(['sku_normalized' => $lookupSkuNormalized]);
    $duplicateCount = (int)$countStmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
    $sku = trim($_POST['sku'] ?? '');
    $data = [
        'id' => $id,
        'sku' => $sku,
        'sku_normalized' => normalizeSku($sku),
        'status' => trim($_POST['status'] ?? ''),
        'what_is_it' => trim($_POST['what_is_it'] ?? ''),
        'date_received' => trim($_POST['date_received'] ?? ''),
        'source' => trim($_POST['source'] ?? ''),
        'functional' => trim($_POST['functional'] ?? ''),
        'condition' => trim($_POST['condition'] ?? ''),
        'is_square' => isset($_POST['is_square']) ? 1 : 0,
        'care_if_square' => isset($_POST['care_if_square']) ? 1 : 0,
        'cords_adapters' => trim($_POST['cords_adapters'] ?? ''),
        'keep_items_together' => trim($_POST['keep_items_together'] ?? ''),
        'picture_taken' => trim($_POST['picture_taken'] ?? ''),
        'power_on' => trim($_POST['power_on'] ?? ''),
        'brand_model' => trim($_POST['brand_model'] ?? ''),
        'ram' => trim($_POST['ram'] ?? ''),
        'ssd_gb' => trim($_POST['ssd_gb'] ?? ''),
        'cpu' => trim($_POST['cpu'] ?? ''),
        'battery_health' => trim($_POST['battery_health'] ?? ''),
        'graphics_card' => trim($_POST['graphics_card'] ?? ''),
        'screen_resolution' => trim($_POST['screen_resolution'] ?? ''),
        'where_it_goes' => trim($_POST['where_it_goes'] ?? ''),
        'ebay_status' => trim($_POST['ebay_status'] ?? ''),
        'ebay_price' => $_POST['ebay_price'] !== '' ? (float)$_POST['ebay_price'] : null,
        'dispotech_price' => $_POST['dispotech_price'] !== '' ? (float)$_POST['dispotech_price'] : null,
        'in_ebay_room' => trim($_POST['in_ebay_room'] ?? ''),
        'what_box' => trim($_POST['what_box'] ?? ''),
        'notes' => trim($_POST['notes'] ?? ''),
    ];

    if ($data['sku_normalized'] === '') {
        $errors[] = 'SKU is required to save this intake item.';
    }

    if (!$errors) {
        $updateStmt = $pdo->prepare(<<<'SQL'
UPDATE intake_items SET
    sku = :sku,
    sku_normalized = :sku_normalized,
    status = :status,
    what_is_it = :what_is_it,
    date_received = :date_received,
    source = :source,
    functional = :functional,
    condition = :condition,
    is_square = :is_square,
    care_if_square = :care_if_square,
    cords_adapters = :cords_adapters,
    keep_items_together = :keep_items_together,
    picture_taken = :picture_taken,
    power_on = :power_on,
    brand_model = :brand_model,
    ram = :ram,
    ssd_gb = :ssd_gb,
    cpu = :cpu,
    battery_health = :battery_health,
    graphics_card = :graphics_card,
    screen_resolution = :screen_resolution,
    where_it_goes = :where_it_goes,
    ebay_status = :ebay_status,
    ebay_price = :ebay_price,
    dispotech_price = :dispotech_price,
    in_ebay_room = :in_ebay_room,
    what_box = :what_box,
    notes = :notes,
    updated_at = datetime('now')
WHERE id = :id;
SQL);
        $saveMode = 'updated';
        if ($id) {
            $updateStmt->execute($data);
        } else {
            $existingStmt = $pdo->prepare('SELECT id FROM intake_items WHERE sku_normalized = :sku_normalized ORDER BY id DESC LIMIT 1');
            $existingStmt->execute(['sku_normalized' => $data['sku_normalized']]);
            $existingId = (int)($existingStmt->fetchColumn() ?: 0);
            if ($existingId > 0) {
                $data['id'] = $existingId;
                $updateStmt->execute($data);
            } else {
                $stmt = $pdo->prepare(<<<'SQL'
INSERT INTO intake_items (
    sku, sku_normalized, status, what_is_it, date_received, source,
    functional, condition, is_square, care_if_square,
    cords_adapters, keep_items_together, picture_taken,
    power_on, brand_model, ram, ssd_gb, cpu, battery_health,
    graphics_card, screen_resolution, where_it_goes,
    ebay_status, ebay_price, dispotech_price, in_ebay_room,
    what_box, notes, updated_at
) VALUES (
    :sku, :sku_normalized, :status, :what_is_it, :date_received, :source,
    :functional, :condition, :is_square, :care_if_square,
    :cords_adapters, :keep_items_together, :picture_taken,
    :power_on, :brand_model, :ram, :ssd_gb, :cpu, :battery_health,
    :graphics_card, :screen_resolution, :where_it_goes,
    :ebay_status, :ebay_price, :dispotech_price, :in_ebay_room,
    :what_box, :notes, datetime('now')
);
SQL);
                $insertData = $data;
                unset($insertData['id']);
                $stmt->execute($insertData);
                $saveMode = 'created';
            }
        }

        $redirect = $_SERVER['PHP_SELF'] . '?saved=1&save_mode=' . urlencode($saveMode);
        if ($data['sku'] !== '') {
            $redirect .= '&sku=' . urlencode($data['sku']);
        }
        header('Location: ' . $redirect);
        exit;
    }
}

$recent = [];
if ($lookupStatus !== '') {
    $recentStmt = $pdo->prepare('SELECT * FROM intake_items WHERE status = :status ORDER BY updated_at DESC, id DESC LIMIT 100');
    $recentStmt->execute(['status' => $lookupStatus]);
    $recent = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $recent = $pdo->query('SELECT * FROM intake_items ORDER BY id DESC LIMIT 25')->fetchAll(PDO::FETCH_ASSOC);
}
$formData = $_POST;
if (!$formData && $currentItem) {
    $formData = $currentItem;
}
if (!$formData && $lookupSku !== '') {
    $formData = ['sku' => $lookupSku];
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function checked(string $name, string $value, array $formData): string
{
    return (($formData[$name] ?? '') === $value) ? 'checked' : '';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dispo.Tech Intake Sheet</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <main class="page">
    <div class="app-menu">
      <button type="button" class="menu-toggle" aria-expanded="false" aria-controls="global-menu" id="menu-toggle">
        <span class="hamburger" aria-hidden="true"></span>
        <span>Menu</span>
      </button>
      <nav class="menu-panel" id="global-menu" aria-hidden="true">
        <ul class="menu-links">
          <li><a href="home.php">Home</a></li>
          <li><a href="home.php#sku-lookup">SKU Lookup</a></li>
          <li><a href="index.php">New Intake</a></li>
        </ul>
      </nav>
    </div>
    <section class="sheet intake">
      <div class="sheet-scale" id="sheet-scale">
        <div class="sheet-content" id="sheet-content">
          <header class="sheet-header">
        <div class="updated">Last updated: <span><?php echo date('Y-m-d'); ?></span></div>
        <label class="print-toggle">
          <input type="checkbox" id="print-pink">
          <span>Print pink</span>
        </label>
        <div class="status">
          <label>
            <span>Status:</span>
            <select name="status" form="intake-form" required>
              <option value="">Select</option>
              <?php foreach ($statusOptions as $opt): ?>
                <option value="<?php echo $opt; ?>" <?php echo (($formData['status'] ?? '') === $opt) ? 'selected' : ''; ?>><?php echo $opt; ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
      </header>

      <h1>Dispo.Tech Tracker Intake Sheet</h1>

      <?php if ($saved): ?>
        <p class="success">
          <?php if ($saveMode === 'created'): ?>
            Saved as new SKU record.
          <?php else: ?>
            Saved and synced to this SKU.
          <?php endif; ?>
        </p>
      <?php endif; ?>

      <?php if ($errors): ?>
        <div class="error-box">
          <?php foreach ($errors as $error): ?>
            <p class="error"><?php echo h($error); ?></p>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($lookupSkuNormalized !== '' && $duplicateCount > 1): ?>
        <p class="warning">This SKU has <?php echo $duplicateCount; ?> records in history. Saving updates the newest one.</p>
      <?php endif; ?>

      <p class="error client-error" id="client-error" hidden>Please fill in SKU, Status, and What is it? before saving.</p>

      <form id="intake-form" method="post" class="form-grid">
        <input type="hidden" id="draft-dismiss" value="<?php echo $saved ? '1' : '0'; ?>">
        <input type="hidden" id="has-server-record" value="<?php echo $currentItem ? '1' : '0'; ?>">
        <input type="hidden" id="has-lookup-sku" value="<?php echo $lookupSkuNormalized !== '' ? '1' : '0'; ?>">
        <input type="hidden" name="id" value="<?php echo h(isset($formData['id']) ? (string)$formData['id'] : ''); ?>">
        <div class="form-columns">
          <div class="row">
            <label>SKU
              <input type="text" name="sku" value="<?php echo h($formData['sku'] ?? ''); ?>" required>
            </label>
            <label>What is it?
              <input type="text" name="what_is_it" value="<?php echo h($formData['what_is_it'] ?? ''); ?>" required>
            </label>
          </div>

          <div class="row">
            <label>Date Received
              <input type="date" name="date_received" value="<?php echo h($formData['date_received'] ?? ''); ?>">
            </label>
            <label>Where did it come from?
              <input type="text" name="source" value="<?php echo h($formData['source'] ?? ''); ?>">
            </label>
          </div>

          <div class="section">
            <h2>(D1) Intake Tasks</h2>
            <div class="row">
              <fieldset>
                <legend>Functional</legend>
                <label><input type="radio" name="functional" value="Yes" <?php echo checked('functional','Yes', $formData); ?>> Yes</label>
                <label><input type="radio" name="functional" value="No" <?php echo checked('functional','No', $formData); ?>> No</label>
                <label><input type="radio" name="functional" value="Unknown" <?php echo checked('functional','Unknown', $formData); ?>> Unknown</label>
              </fieldset>
              <label>Condition
                <select name="condition">
                  <option value="">Select</option>
                  <?php foreach (['Good','Great','Excellent','Unicorn'] as $opt): ?>
                    <option value="<?php echo $opt; ?>" <?php echo (($formData['condition'] ?? '') === $opt) ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>

            <div class="row">
              <div class="conjoined">
                <label class="segment">
                  <input type="checkbox" name="is_square" <?php echo !empty($formData['is_square']) ? 'checked' : ''; ?>>
                  <span>Is it square?</span>
                </label>
                <label class="segment">
                  <input type="checkbox" name="care_if_square" <?php echo !empty($formData['care_if_square']) ? 'checked' : ''; ?>>
                  <span>Do we care?</span>
                </label>
              </div>
            </div>

            <div class="row">
              <fieldset>
                <legend>Cords / adapters included?</legend>
                <label><input type="radio" name="cords_adapters" value="Yes" <?php echo checked('cords_adapters','Yes', $formData); ?>> Yes</label>
                <label><input type="radio" name="cords_adapters" value="No" <?php echo checked('cords_adapters','No', $formData); ?>> No</label>
              </fieldset>
              <fieldset>
                <legend>Keep items together?</legend>
                <label><input type="radio" name="keep_items_together" value="Yes" <?php echo checked('keep_items_together','Yes', $formData); ?>> Yes</label>
                <label><input type="radio" name="keep_items_together" value="No" <?php echo checked('keep_items_together','No', $formData); ?>> No</label>
              </fieldset>
              <fieldset>
                <legend>Picture</legend>
                <label><input type="radio" name="picture_taken" value="Yes" <?php echo checked('picture_taken','Yes', $formData); ?>> Yes</label>
                <label><input type="radio" name="picture_taken" value="No" <?php echo checked('picture_taken','No', $formData); ?>> No</label>
              </fieldset>
            </div>
          </div>

          <div class="section">
            <h2>(D2) Description Tasks</h2>
            <div class="row">
              <fieldset>
                <legend>Does it power on and stay on?</legend>
                <label><input type="radio" name="power_on" value="Yes" <?php echo checked('power_on','Yes', $formData); ?>> Yes</label>
                <label><input type="radio" name="power_on" value="No" <?php echo checked('power_on','No', $formData); ?>> No</label>
              </fieldset>
              <label>Brand & Model Number
                <input type="text" name="brand_model" value="<?php echo h($formData['brand_model'] ?? ''); ?>">
              </label>
            </div>

            <div class="row">
              <label>RAM
                <input type="text" name="ram" value="<?php echo h($formData['ram'] ?? ''); ?>">
              </label>
              <label>SSD GB
                <input type="text" name="ssd_gb" value="<?php echo h($formData['ssd_gb'] ?? ''); ?>">
              </label>
              <label>CPU
                <input type="text" name="cpu" value="<?php echo h($formData['cpu'] ?? ''); ?>">
              </label>
            </div>

            <div class="row">
              <label>Battery Health
                <input type="text" name="battery_health" value="<?php echo h($formData['battery_health'] ?? ''); ?>">
              </label>
              <label>Graphics Card
                <input type="text" name="graphics_card" value="<?php echo h($formData['graphics_card'] ?? ''); ?>">
              </label>
              <label>Screen Resolution
                <input type="text" name="screen_resolution" value="<?php echo h($formData['screen_resolution'] ?? ''); ?>">
              </label>
            </div>
          </div>

          <div class="section">
            <h2>Where did it go?</h2>
            <div class="row">
              <label>
                <select name="where_it_goes">
                  <option value="">Select</option>
                  <?php foreach (['D2 - Description','Scrap Room'] as $opt): ?>
                    <option value="<?php echo $opt; ?>" <?php echo (($formData['where_it_goes'] ?? '') === $opt) ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>
          </div>

          <div class="section">
            <h2>E-Bay Status</h2>
            <div class="row">
              <label>Ebay Status
                <input type="text" name="ebay_status" value="<?php echo h($formData['ebay_status'] ?? ''); ?>">
              </label>
              <label>Ebay Price
                <input type="number" step="0.01" name="ebay_price" value="<?php echo h(isset($formData['ebay_price']) ? (string)$formData['ebay_price'] : ''); ?>">
              </label>
              <label>DispoTech Price
                <input type="number" step="0.01" name="dispotech_price" value="<?php echo h(isset($formData['dispotech_price']) ? (string)$formData['dispotech_price'] : ''); ?>">
              </label>
            </div>
            <div class="row">
              <fieldset>
                <legend>Is it in the EBay Room?</legend>
                <label><input type="radio" name="in_ebay_room" value="Yes" <?php echo checked('in_ebay_room','Yes', $formData); ?>> Yes</label>
                <label><input type="radio" name="in_ebay_room" value="No" <?php echo checked('in_ebay_room','No', $formData); ?>> No</label>
              </fieldset>
              <label>What Box?
                <input type="text" name="what_box" value="<?php echo h($formData['what_box'] ?? ''); ?>">
              </label>
            </div>
          </div>
        </div>

        <div class="section notes">
          <h2>Notes</h2>
          <textarea name="notes" rows="3"><?php echo h($formData['notes'] ?? ''); ?></textarea>
        </div>

        <div class="actions">
          <button type="submit">Save Intake Item</button>
        </div>
      </form>

      <section class="section recent-items">
        <h2><?php echo $lookupStatus !== '' ? 'Status Results' : 'Recent SKUs'; ?></h2>
        <form class="form-grid" method="get" action="index.php">
          <div class="row">
            <label>SKU
              <input type="text" name="sku" value="<?php echo h($lookupSku); ?>">
            </label>
            <label>Status
              <select name="status">
                <option value="">Any status</option>
                <?php foreach ($statusOptions as $opt): ?>
                  <option value="<?php echo $opt; ?>" <?php echo $lookupStatus === $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <div class="actions">
            <button type="submit">Search</button>
            <a class="button-link" href="index.php">Clear</a>
          </div>
        </form>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>SKU</th>
                <th>Status</th>
                <th>What is it?</th>
                <th>Updated</th>
                <th>Open</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$recent): ?>
                <tr>
                  <td colspan="5">No items found for this lookup.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($recent as $item): ?>
                  <tr>
                    <td><?php echo h($item['sku'] ?? ''); ?></td>
                    <td><?php echo h($item['status'] ?? ''); ?></td>
                    <td><?php echo h($item['what_is_it'] ?? ''); ?></td>
                    <td><?php echo h($item['updated_at'] ?? ''); ?></td>
                    <td><a class="open-link" href="index.php?sku=<?php echo urlencode((string)($item['sku'] ?? '')); ?>">Open</a></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
        </div>
      </div>
    </section>
  </main>
  <script>
    (function () {
      var menuToggle = document.getElementById('menu-toggle');
      var menuPanel = document.getElementById('global-menu');
      if (menuToggle && menuPanel) {
        var closeMenu = function () {
          menuPanel.classList.remove('is-open');
          menuPanel.setAttribute('aria-hidden', 'true');
          menuToggle.setAttribute('aria-expanded', 'false');
        };
        menuToggle.addEventListener('click', function () {
          var opening = !menuPanel.classList.contains('is-open');
          menuPanel.classList.toggle('is-open', opening);
          menuPanel.setAttribute('aria-hidden', opening ? 'false' : 'true');
          menuToggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
        });
        document.addEventListener('click', function (event) {
          if (!menuPanel.classList.contains('is-open')) {
            return;
          }
          if (!menuPanel.contains(event.target) && !menuToggle.contains(event.target)) {
            closeMenu();
          }
        });
        document.addEventListener('keydown', function (event) {
          if (event.key === 'Escape') {
            closeMenu();
          }
        });
      }

      var form = document.getElementById('intake-form');
      if (form) {
        var draftKey = 'intakeDraftV1';
        var errorEl = document.getElementById('client-error');
        var dismissDraft = document.getElementById('draft-dismiss');
        var hasRecord = document.getElementById('has-server-record');
        var hasLookup = document.getElementById('has-lookup-sku');
        var shouldRestore = dismissDraft && dismissDraft.value !== '1'
          && hasRecord && hasRecord.value !== '1'
          && hasLookup && hasLookup.value !== '1';

        var applyRequiredState = function (name, missing) {
          var el = form.querySelector('[name="' + name + '"]');
          if (el) {
            el.classList.toggle('required-missing', missing);
          }
        };

        if (shouldRestore) {
          try {
            var raw = localStorage.getItem(draftKey);
            if (raw) {
              var draft = JSON.parse(raw);
              Object.keys(draft).forEach(function (name) {
                var value = draft[name];
                var fields = form.querySelectorAll('[name="' + name + '"]');
                fields.forEach(function (field) {
                  if (field.type === 'radio') {
                    field.checked = (field.value === value);
                    return;
                  }
                  if (field.type === 'checkbox') {
                    field.checked = !!value;
                    return;
                  }
                  field.value = value;
                });
              });
            }
          } catch (e) {}
        }

        var saveTimer = null;
        var saveDraft = function () {
          var payload = {};
          var fields = form.querySelectorAll('input[name], select[name], textarea[name]');
          fields.forEach(function (field) {
            if (field.type === 'radio') {
              if (field.checked) {
                payload[field.name] = field.value;
              }
              return;
            }
            if (field.type === 'checkbox') {
              payload[field.name] = field.checked;
              return;
            }
            payload[field.name] = field.value;
          });
          localStorage.setItem(draftKey, JSON.stringify(payload));
        };
        var queueDraftSave = function () {
          clearTimeout(saveTimer);
          saveTimer = setTimeout(saveDraft, 250);
        };

        form.addEventListener('input', function (event) {
          queueDraftSave();
          if (!event.target || !event.target.name) {
            return;
          }
          if (event.target.name === 'sku' || event.target.name === 'status' || event.target.name === 'what_is_it') {
            applyRequiredState(event.target.name, false);
            if (errorEl) {
              errorEl.hidden = true;
            }
          }
        });
        form.addEventListener('change', queueDraftSave);
        form.addEventListener('submit', function (event) {
          var sku = ((form.querySelector('[name="sku"]') || {}).value || '').trim();
          var status = ((form.querySelector('[name="status"]') || {}).value || '').trim();
          var whatIsIt = ((form.querySelector('[name="what_is_it"]') || {}).value || '').trim();
          var missingSku = sku === '';
          var missingStatus = status === '';
          var missingWhat = whatIsIt === '';
          applyRequiredState('sku', missingSku);
          applyRequiredState('status', missingStatus);
          applyRequiredState('what_is_it', missingWhat);
          if (missingSku || missingStatus || missingWhat) {
            event.preventDefault();
            if (errorEl) {
              errorEl.hidden = false;
            }
            return;
          }
          localStorage.removeItem(draftKey);
        });
      }

      var checkbox = document.getElementById('print-pink');
      if (checkbox) {
        var storageKey = 'printPink';
        var apply = function (enabled) {
          document.body.classList.toggle('print-pink', enabled);
        };
        if (localStorage.getItem(storageKey) === '1') {
          checkbox.checked = true;
          apply(true);
        }
        checkbox.addEventListener('change', function () {
          apply(checkbox.checked);
          localStorage.setItem(storageKey, checkbox.checked ? '1' : '0');
        });
      }

      // Keep screen view at full readable size; print layout is handled by CSS.
    })();
  </script>
</body>
</html>
