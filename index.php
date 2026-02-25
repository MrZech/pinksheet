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

$saved = isset($_GET['saved']);
$lookupSku = trim($_GET['sku'] ?? '');
$currentItem = null;

if ($lookupSku !== '') {
    $stmt = $pdo->prepare('SELECT * FROM intake_items WHERE sku = :sku ORDER BY id DESC LIMIT 1');
    $stmt->execute(['sku' => $lookupSku]);
    $currentItem = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
    $data = [
        'id' => $id,
        'sku' => trim($_POST['sku'] ?? ''),
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

    if ($id) {
        $stmt = $pdo->prepare(<<<'SQL'
UPDATE intake_items SET
    sku = :sku,
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
        $stmt->execute($data);
    } else {
        $stmt = $pdo->prepare(<<<'SQL'
INSERT INTO intake_items (
    sku, status, what_is_it, date_received, source,
    functional, condition, is_square, care_if_square,
    cords_adapters, keep_items_together, picture_taken,
    power_on, brand_model, ram, ssd_gb, cpu, battery_health,
    graphics_card, screen_resolution, where_it_goes,
    ebay_status, ebay_price, dispotech_price, in_ebay_room,
    what_box, notes, updated_at
) VALUES (
    :sku, :status, :what_is_it, :date_received, :source,
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
    }

    $redirect = $_SERVER['PHP_SELF'] . '?saved=1';
    if ($data['sku'] !== '') {
        $redirect .= '&sku=' . urlencode($data['sku']);
    }
    header('Location: ' . $redirect);
    exit;
}

$recent = $pdo->query('SELECT * FROM intake_items ORDER BY id DESC LIMIT 25')->fetchAll(PDO::FETCH_ASSOC);
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
    <section class="sheet intake">
      <header class="sheet-header">
        <div class="updated">Last updated: <span><?php echo date('Y-m-d'); ?></span></div>
        <a class="home-link" href="home.php">Home</a>
        <label class="print-toggle">
          <input type="checkbox" id="print-pink">
          <span>Print pink</span>
        </label>
        <div class="status">
          <label>
            <span>Status:</span>
            <select name="status" form="intake-form">
              <option value="">Select</option>
              <?php foreach (['Intake','Description','Tested','Listed','SOLD'] as $opt): ?>
                <option value="<?php echo $opt; ?>" <?php echo (($formData['status'] ?? '') === $opt) ? 'selected' : ''; ?>><?php echo $opt; ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
      </header>

      <h1>Dispo.Tech Tracker Intake Sheet</h1>

      <?php if ($saved): ?>
        <p class="success">Saved intake item.</p>
      <?php endif; ?>

      <form id="intake-form" method="post" class="form-grid">
        <input type="hidden" name="id" value="<?php echo h(isset($formData['id']) ? (string)$formData['id'] : ''); ?>">
        <div class="row">
          <label>SKU
            <input type="text" name="sku" value="<?php echo h($formData['sku'] ?? ''); ?>">
          </label>
          <label>What is it?
            <input type="text" name="what_is_it" value="<?php echo h($formData['what_is_it'] ?? ''); ?>">
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

        <div class="section notes">
          <h2>Notes</h2>
          <textarea name="notes" rows="3"><?php echo h($formData['notes'] ?? ''); ?></textarea>
        </div>

        <div class="actions">
          <button type="submit">Save Intake Item</button>
        </div>
      </form>
    </section>
  </main>
  <script>
    (function () {
      var checkbox = document.getElementById('print-pink');
      if (!checkbox) {
        return;
      }
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
    })();
  </script>
</body>
</html>
