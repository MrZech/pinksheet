<?php
const HOME_DB_PATH = __DIR__ . '/data/intake.sqlite';
$statusOptions = ['Intake', 'Description', 'Tested', 'Listed', 'SOLD'];
$lookupSuggestions = [];
if (is_readable(HOME_DB_PATH)) {
    try {
        $pdo = new PDO('sqlite:' . HOME_DB_PATH, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $stmt = $pdo->query("
            SELECT sku
            FROM intake_items
            WHERE sku IS NOT NULL
              AND TRIM(sku) <> ''
            ORDER BY updated_at DESC, id DESC
            LIMIT 60
        ");
        $values = array_unique(array_map('trim', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        $lookupSuggestions = array_values(array_filter($values, static fn ($value): bool => $value !== ''));
    } catch (Exception $e) {
        // suggestions optional
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dispo.Tech Intake Home</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body class="home">
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
    <section class="sheet home-sheet">
      <header class="sheet-header">
        <div class="updated">Dispo.Tech Intake</div>
      </header>
      <h1>Dispo.Tech Intake Lookup</h1>
      <p>Look up by SKU or by current status to find items quickly.</p>
      <form class="form-grid" method="get" action="index.php" id="sku-lookup">
        <div class="row">
          <label>SKU
            <input type="text" name="sku" list="suggested-skus" autofocus>
          </label>
          <?php if ($lookupSuggestions): ?>
            <datalist id="suggested-skus">
              <?php foreach ($lookupSuggestions as $option): ?>
                <option value="<?php echo htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?>">
              <?php endforeach; ?>
            </datalist>
          <?php endif; ?>
          <label>Current Status
            <select name="status">
              <option value="">Any status</option>
              <?php foreach ($statusOptions as $opt): ?>
                <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <p class="error client-error" id="lookup-error" hidden>Enter a SKU or pick a status to search.</p>
        <div class="actions">
          <button type="submit">Continue</button>
          <a class="button-link" href="index.php">New Intake</a>
        </div>
      </form>
    </section>
  </main>
  <script>
    (function () {
      var menuToggle = document.getElementById('menu-toggle');
      var menuPanel = document.getElementById('global-menu');
      if (!menuToggle || !menuPanel) {
        return;
      }

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

      var lookupForm = document.getElementById('sku-lookup');
      if (lookupForm) {
        var errorEl = document.getElementById('lookup-error');
        lookupForm.addEventListener('submit', function (event) {
          var sku = ((lookupForm.querySelector('[name="sku"]') || {}).value || '').trim();
          var status = ((lookupForm.querySelector('[name="status"]') || {}).value || '').trim();
          if (sku === '' && status === '') {
            event.preventDefault();
            if (errorEl) {
              errorEl.hidden = false;
            }
            return;
          }
          if (errorEl) {
            errorEl.hidden = true;
          }
        });
      }
    })();
  </script>
</body>
</html>
