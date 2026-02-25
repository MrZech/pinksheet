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
      <p>Paste or type a SKU to open the intake sheet for review or editing.</p>
      <form class="form-grid" method="get" action="index.php" id="sku-lookup">
        <div class="row">
          <label>SKU
            <input type="text" name="sku" required autofocus>
          </label>
        </div>
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
    })();
  </script>
</body>
</html>
