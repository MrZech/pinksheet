<?php
require_once __DIR__ . '/config.php';
checkMaintenance();
$currentPage = 'home';
const HOME_DB_PATH = __DIR__ . '/data/intake.sqlite';
$statusOptions = ['Intake', 'Description', 'Tested', 'Listed', 'SOLD'];
$lookupSuggestions = [];
// Provision a short list of the most recently updated SKUs so the home lookup can show instant suggestions.
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
          <li><a class="menu-link <?php echo $currentPage === 'home' ? 'is-active' : ''; ?>" href="home.php">Home</a></li>
          <li><a class="menu-link <?php echo $currentPage === 'lookup' ? 'is-active' : ''; ?>" href="home.php#sku-lookup">SKU Lookup</a></li>
          <li><a class="menu-link new-intake-link <?php echo $currentPage === 'intake' ? 'is-active' : ''; ?>" href="index.php?clear_draft=1">New Intake</a></li>
        </ul>
      </nav>
    </div>
    <section class="sheet home-sheet">
      <header class="sheet-header">
        <div class="updated">Dispo.Tech Intake</div>
        <div class="sheet-header-right">
          <button type="button" class="print-button" id="print-button">Print</button>
          <button type="button" class="theme-toggle" id="theme-toggle">Dark mode</button>
        </div>
      </header>
      <h1>Dispo.Tech Intake Lookup</h1>
      <nav class="breadcrumbs" aria-label="Breadcrumb">
        <a href="home.php">Home</a>
        <span>Lookup</span>
      </nav>
      <p>Look up by SKU or by current status to find items quickly.</p>
      <form class="form-grid" method="get" action="index.php" id="sku-lookup">
        <div class="row">
          <label>SKU
            <input type="text" name="sku" list="suggested-skus" autofocus>
          </label>
          <!-- Datalist seeded from latest SKUs, replaced dynamically when the user types. -->
          <datalist id="suggested-skus">
            <?php foreach ($lookupSuggestions as $option): ?>
              <option value="<?php echo htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?>">
            <?php endforeach; ?>
          </datalist>
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
        <p class="hint" id="lookup-inline-hint">Type at least two characters for live matches; suggestions include SKU plus “What is it?” text.</p>
        <div class="actions">
          <button type="submit">Continue</button>
          <a class="button-link new-intake-link" href="index.php?clear_draft=1">New Intake</a>
        </div>
      </form>
    </section>
    <section class="section lookup-preview" aria-live="polite">
      <h2>Preview matches</h2>
      <p class="hint" id="lookup-preview-message">Type two characters or select a status to see recent entries.</p>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>SKU</th>
              <th>Status</th>
              <th>What is it?</th>
              <th>Updated</th>
            </tr>
          </thead>
          <tbody id="lookup-preview-body">
            <tr>
              <td colspan="4">No lookup terms yet.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </main>
  <script>
    (function () {
      var themeToggle = document.getElementById('theme-toggle');
      var applyThemeMode = function (mode) {
        var isDark = mode === 'dark';
        document.body.classList.toggle('dark-mode', isDark);
        if (themeToggle) {
          themeToggle.textContent = isDark ? 'Light mode' : 'Dark mode';
        }
      };
      var storedTheme = null;
      try {
        storedTheme = localStorage.getItem('themePreference');
      } catch (e) {}
      var initialTheme = storedTheme || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
      applyThemeMode(initialTheme);
      if (themeToggle) {
        themeToggle.addEventListener('click', function () {
          var nextMode = document.body.classList.contains('dark-mode') ? 'light' : 'dark';
          applyThemeMode(nextMode);
          try {
            localStorage.setItem('themePreference', nextMode);
          } catch (e) {}
        });
      }
      var printButton = document.getElementById('print-button');
      if (printButton) {
        printButton.addEventListener('click', function () {
          window.print();
        });
      }
      var newIntakeLinks = document.querySelectorAll('.new-intake-link');
      var navigateNewIntake = function (event) {
        if (!event) {
          return;
        }
        event.preventDefault();
        try {
          localStorage.removeItem('intakeDraftV1');
        } catch (e) {}
        var target = (event.currentTarget && event.currentTarget.getAttribute('href')) || 'index.php?clear_draft=1';
        window.location.href = target;
      };
      for (var ni = 0; ni < newIntakeLinks.length; ni++) {
        newIntakeLinks[ni].addEventListener('click', navigateNewIntake);
      }
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
        var skuInput = lookupForm.querySelector('[name="sku"]');
        var suggestionList = document.getElementById('suggested-skus');
        var previewBody = document.getElementById('lookup-preview-body');
        var previewMessage = document.getElementById('lookup-preview-message');
        var statusSelect = lookupForm.querySelector('[name="status"]');
        if (skuInput && suggestionList && window.fetch && typeof AbortController !== 'undefined') {
          var suggestionTimer = null;
          var suggestionController = null;
          // Debounced fetch keeps the datalist in sync with the backend while the user types.
          var fetchSuggestions = function () {
            // Live-search backend returns SKUs + descriptions so the dropdown shows context.
            var query = skuInput.value.trim();
            if (query.length < 2) {
              return;
            }
            if (suggestionController) {
              suggestionController.abort();
            }
            suggestionController = new AbortController();
            fetch('suggestions.php?q=' + encodeURIComponent(query), {
              signal: suggestionController.signal,
            })
              .then(function (response) {
                if (!response.ok) {
                  throw new Error('Network response was not ok');
                }
                return response.json();
              })
              .then(function (items) {
                suggestionList.innerHTML = '';
                items.forEach(function (entry) {
                  var option = document.createElement('option');
                  option.value = entry.value || '';
                  if (entry.label && entry.label !== entry.value) {
                    option.textContent = entry.label;
                  }
                    suggestionList.appendChild(option);
                  });
                })
                .catch(function () {});
          };
          skuInput.addEventListener('input', function () {
            clearTimeout(suggestionTimer);
            suggestionTimer = setTimeout(function () {
              if (skuInput.value.trim().length >= 2) {
                fetchSuggestions();
              }
            }, 220);
            schedulePreview();
          });
        }
        var previewTimer = null;
        var previewController = null;
        var resetPreview = function () {
          if (!previewBody || !previewMessage) {
            return;
          }
          previewBody.innerHTML = '<tr><td colspan="4">No lookup terms yet.</td></tr>';
          previewMessage.textContent = 'Type two characters or select a status to see recent entries.';
          previewMessage.classList.remove('hint-warning');
        };
        var createCell = function (value) {
          var td = document.createElement('td');
          td.textContent = value || ' - ';
          return td;
        };
        var renderPreviewRows = function (items) {
          if (!previewBody) {
            return;
          }
          previewBody.innerHTML = '';
          items.forEach(function (entry) {
            var row = document.createElement('tr');
            row.appendChild(createCell(entry.sku));
            row.appendChild(createCell(entry.status));
            row.appendChild(createCell(entry.what_is_it));
            row.appendChild(createCell(entry.updated_at));
            previewBody.appendChild(row);
          });
          previewMessage.textContent = 'Showing the most recent matches.';
          previewMessage.classList.remove('hint-warning');
        };
        var requestPreview = function () {
          if (!window.fetch || !previewBody || !previewMessage) {
            return;
          }
          var skuValue = (skuInput && skuInput.value.trim()) || '';
          var statusValue = (statusSelect && statusSelect.value.trim()) || '';
          if (skuValue === '' && statusValue === '') {
            resetPreview();
            return;
          }
          if (skuValue !== '' && skuValue.length < 2 && statusValue === '') {
            previewBody.innerHTML = '<tr><td colspan="4">Waiting for at least two characters...</td></tr>';
            previewMessage.textContent = 'Type two characters to preview SKU matches.';
            previewMessage.classList.add('hint-warning');
            return;
          }
          var params = new URLSearchParams();
          if (skuValue !== '') {
            params.set('sku', skuValue);
          }
          if (statusValue !== '') {
            params.set('status', statusValue);
          }
          if (!params.toString()) {
            resetPreview();
            return;
          }
          previewMessage.textContent = 'Loading preview...';
          previewMessage.classList.remove('hint-warning');
          if (previewController && typeof previewController.abort === 'function') {
            previewController.abort();
          }
          previewController = typeof AbortController !== 'undefined' ? new AbortController() : null;
          fetch('lookup_preview.php?' + params.toString(), {
            signal: previewController ? previewController.signal : undefined,
          })
            .then(function (response) {
              if (!response.ok) {
                throw new Error('Network response was not ok');
              }
              return response.json();
            })
            .then(function (items) {
              if (!Array.isArray(items) || items.length === 0) {
                previewBody.innerHTML = '<tr><td colspan="4">No matches found.</td></tr>';
                previewMessage.textContent = 'No recent records match those terms.';
                previewMessage.classList.add('hint-warning');
                return;
              }
              renderPreviewRows(items);
            })
            .catch(function () {
              previewMessage.textContent = 'Could not load preview right now.';
              previewMessage.classList.add('hint-warning');
            });
        };
        var schedulePreview = function () {
          if (!window.fetch) {
            return;
          }
          clearTimeout(previewTimer);
          previewTimer = setTimeout(requestPreview, 220);
        };
        if (skuInput) {
          skuInput.addEventListener('input', function () {
            schedulePreview();
          });
        }
        if (statusSelect) {
          statusSelect.addEventListener('change', function () {
            schedulePreview();
          });
        }
        resetPreview();
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
