<?php
require_once __DIR__ . '/config.php';
checkMaintenance();
ensureStorageWritable();
$scriptName = basename($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
$isLookupPage = $scriptName === 'lookup.php';
$currentPage = $isLookupPage ? 'lookup' : 'home';
const HOME_DB_PATH = __DIR__ . '/data/intake.sqlite';
$statusOptions = ['Intake', 'Description', 'Tested', 'Listed', 'SOLD'];
$lookupSuggestions = [];
$counts = [
    'total' => null,
    'today' => null,
    'in_progress' => null,
    'sold' => null,
];
$recentActivity = [];
$recentThumbs = [];
$alerts = [];
$latestBackup = null;
$latestBackupAgeHours = null;
$latestBackupSize = null;
$backupBadge = null;
$backupFreePct = null;
$backupSummary = 'No backup yet';
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

        // Dashboard metrics
        $counts['total'] = (int) $pdo->query("SELECT COUNT(*) FROM intake_items")->fetchColumn();
        $today = (new DateTime('now'))->format('Y-m-d');
        $stmtToday = $pdo->prepare("SELECT COUNT(*) FROM intake_items WHERE date(created_at) = :today");
        $stmtToday->execute([':today' => $today]);
        $counts['today'] = (int) $stmtToday->fetchColumn();
        $counts['sold'] = (int) $pdo->query("SELECT COUNT(*) FROM intake_items WHERE status = 'SOLD'")->fetchColumn();
        $counts['in_progress'] = (int) $pdo->query("SELECT COUNT(*) FROM intake_items WHERE status != 'SOLD'")->fetchColumn();

        // Recent activity list
        $stmtRecent = $pdo->query("
            SELECT sku, status, what_is_it, updated_at
            FROM intake_items
            WHERE sku IS NOT NULL AND TRIM(sku) <> ''
            ORDER BY updated_at DESC, id DESC
            LIMIT 10
        ");
        $recentActivity = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);
        // Attach latest photo id per SKU for quick thumbnails.
        $recentSkus = array_values(array_filter(array_map(static fn($r) => trim((string)($r['sku'] ?? '')), $recentActivity)));
        $recentThumbs = [];
        if ($recentSkus) {
            $norms = array_map(static fn($s) => strtoupper($s), $recentSkus);
            $placeholders = implode(',', array_fill(0, count($norms), '?'));
            $photoStmt = $pdo->prepare("
                SELECT sku_normalized, id
                FROM sku_photos
                WHERE sku_normalized IN ($placeholders)
                ORDER BY id DESC
            ");
            $photoStmt->execute($norms);
            foreach ($photoStmt->fetchAll(PDO::FETCH_ASSOC) as $photoRow) {
                $norm = trim((string)$photoRow['sku_normalized']);
                if ($norm !== '' && !isset($recentThumbs[$norm])) {
                    $recentThumbs[$norm] = (int)$photoRow['id'];
                }
            }
        }
    } catch (Exception $e) {
        // suggestions optional
        $alerts[] = 'Database is unreadable right now; metrics unavailable.';
    }
} else {
    $alerts[] = 'Database file is missing or not readable.';
}

// Latest backup metadata
$backupDir = __DIR__ . '/data/backups';
if (is_dir($backupDir)) {
    $latestFile = null;
    foreach (new DirectoryIterator($backupDir) as $fileInfo) {
        if ($fileInfo->isFile()) {
            if ($latestFile === null || $fileInfo->getMTime() > $latestFile->getMTime()) {
                $latestFile = $fileInfo;
            }
        }
    }
    if ($latestFile) {
        $latestBackup = $latestFile->getFilename();
        $latestBackupAgeHours = (time() - $latestFile->getMTime()) / 3600;
        $latestBackupSize = $latestFile->getSize();
        $backupBadge = 'Backup ' . number_format($latestBackupAgeHours, 1) . 'h ago';
        $backupSummary = 'Last: ~' . number_format($latestBackupAgeHours ?? 0, 1) . 'h · ' . number_format(($latestBackupSize ?? 0) / 1024, 1) . ' KB';
        if ($latestBackupAgeHours !== null && $latestBackupAgeHours > 36) {
            $alerts[] = 'Latest backup is older than 36 hours.';
        }
    } else {
        $alerts[] = 'No backups found in data/backups.';
    }
    $freeBytes = @disk_free_space($backupDir);
    $totalBytes = @disk_total_space($backupDir);
    if ($freeBytes !== false && $totalBytes > 0) {
        $backupFreePct = ($freeBytes / $totalBytes) * 100;
        if ($backupFreePct < 10) {
            $alerts[] = 'Backup drive is low on space (<10% free).';
        }
    }
} else {
    $alerts[] = 'Backup directory missing (data/backups).';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo $isLookupPage ? 'Dispo.Tech SKU Lookup' : 'Dispo.Tech Intake Home'; ?></title>
  <link rel="stylesheet" href="assets/style.css">
  <link rel="stylesheet" media="print" href="assets/print.css">
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
</head>
<body class="home<?php echo $isLookupPage ? ' lookup-page' : ''; ?>">
  <main class="page">
    <div class="app-menu">
      <button type="button" class="menu-toggle" aria-expanded="false" aria-controls="global-menu" id="menu-toggle">
        <span class="hamburger" aria-hidden="true"></span>
        <span>Menu</span>
      </button>
      <nav class="menu-panel" id="global-menu" aria-hidden="true">
        <ul class="menu-links">
          <li><a class="menu-link <?php echo $currentPage === 'home' ? 'is-active' : ''; ?>" href="home.php">Home</a></li>
          <li><a class="menu-link <?php echo $currentPage === 'lookup' ? 'is-active' : ''; ?>" href="lookup.php">SKU Lookup</a></li>
          <li><a class="menu-link <?php echo $currentPage === 'intake' ? 'is-active' : ''; ?>" href="intake.php?clear_draft=1" data-new-intake>New Intake</a></li>
          <li><a class="menu-link" href="prompt_builder.php">eBay Script Builder</a></li>
        </ul>
      </nav>
    </div>
    <section class="sheet home-sheet">
      <header class="sheet-header">
        <div class="updated"><?php echo $isLookupPage ? 'Dispo.Tech SKU Lookup' : 'Dispo.Tech Intake'; ?></div>
        <div class="sheet-header-right">
          <?php if ($backupBadge): ?>
            <span class="badge" title="Latest backup"><?php echo htmlspecialchars($backupBadge, ENT_QUOTES, 'UTF-8'); ?></span>
          <?php endif; ?>
          <span class="badge subtle" id="health-chip" title="System health">Health: ...</span>
          <button type="button" class="print-button" id="print-button">Print</button>
          <button type="button" class="theme-toggle" id="theme-toggle">Dark mode</button>
          <a class="button-link new-intake-cta" href="intake.php?clear_draft=1" data-new-intake>New Intake</a>
      </div>
      </header>
      <h1><?php echo $isLookupPage ? 'SKU Lookup' : 'Ops Home'; ?></h1>
      <nav class="breadcrumbs" aria-label="Breadcrumb">
        <a href="home.php">Home</a>
        <span><?php echo $isLookupPage ? 'SKU Lookup' : 'Dashboard'; ?></span>
      </nav>
      <p class="lead"><?php echo $isLookupPage ? 'Search, filter, and update SKU records in one place.' : 'Snapshot of intake health plus a recent activity dashboard.'; ?></p>

      <?php if (!$isLookupPage): ?>
      <section class="section quick-actions">
        <h2>Quick actions</h2>
        <div class="quick-links">
          <a class="button-link" href="intake.php?clear_draft=1" data-new-intake>New Intake</a>
          <a class="button-link" href="lookup.php">Search SKUs</a>
          <a class="button-link" href="prompt_builder.php">eBay Script Builder</a>
          <a class="button-link" href="docs/maintenance.md">Maintenance docs</a>
          <a class="button-link" href="kanban.php">Status Board</a>
          <button type="button" class="button-link ghost" id="run-backup-now" data-run-backup>
            Run backup now<?php if ($latestBackup): ?> (<?php echo htmlspecialchars($backupSummary, ENT_QUOTES, 'UTF-8'); ?>)<?php endif; ?>
          </button>
          <button type="button" class="button-link subtle" data-verify-backup>Verify latest backup</button>
        </div>
      </section>

      <?php if (!empty($alerts)): ?>
        <div class="alert-block" role="status">
          <?php foreach ($alerts as $alert): ?>
            <div class="alert-item"><?php echo htmlspecialchars($alert, ENT_QUOTES, 'UTF-8'); ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <section class="section dashboard">
        <h2>Ops Snapshot</h2>
        <div class="dashboard-grid">
          <div class="dash-card">
            <p class="dash-label">Total items</p>
            <p class="dash-value"><?php echo $counts['total'] ?? '—'; ?></p>
            <p class="dash-sub">All records in intake_items</p>
          </div>
          <div class="dash-card">
            <p class="dash-label">Updated today</p>
            <p class="dash-value"><?php echo $counts['today'] ?? '—'; ?></p>
            <p class="dash-sub">Created today</p>
          </div>
          <div class="dash-card">
            <p class="dash-label">In progress</p>
            <p class="dash-value"><?php echo $counts['in_progress'] ?? '—'; ?></p>
            <p class="dash-sub">Not yet SOLD</p>
          </div>
          <div class="dash-card">
            <p class="dash-label">Sold</p>
            <p class="dash-value"><?php echo $counts['sold'] ?? '—'; ?></p>
            <p class="dash-sub">Marked SOLD</p>
          </div>
          <div class="dash-card dash-wide">
            <p class="dash-label">Latest backup</p>
            <p class="dash-value">
              <?php echo $latestBackup ? htmlspecialchars($latestBackup, ENT_QUOTES, 'UTF-8') : 'None'; ?>
            </p>
            <p class="dash-sub">
              <?php if ($latestBackup): ?>
                <?php echo 'Age: ~' . number_format($latestBackupAgeHours ?? 0, 1) . 'h · Size: ' . number_format(($latestBackupSize ?? 0) / 1024, 1) . ' KB'; ?>
              <?php else: ?>
                Backups will display here once created.
              <?php endif; ?>
            </p>
            <p class="dash-sub">
              <button type="button" class="button-link ghost" data-run-backup>
                Run backup now<?php if ($latestBackup): ?> (<?php echo htmlspecialchars($backupSummary, ENT_QUOTES, 'UTF-8'); ?>)<?php endif; ?>
              </button>
              <span class="hint">Local only; saves to data/backups/</span>
              <?php if ($backupFreePct !== null): ?>
                <span class="hint"><?php echo 'Free space: ' . number_format($backupFreePct, 1) . '%'; ?></span>
              <?php endif; ?>
              <button type="button" class="button-link subtle" data-verify-backup>Verify latest backup</button>
              <span class="hint">Runs checksum + integrity check</span>
            </p>
          </div>
        </div>
      </section>

      <section class="section activity">
        <h2>Recent activity</h2>
        <div class="hint" id="recent-activity-skus" aria-label="Recently viewed SKUs"></div>
        <?php if (!empty($recentActivity)): ?>
          <ul class="activity-list">
            <?php foreach ($recentActivity as $row): ?>
              <li>
                <div class="activity-main">
                  <?php $skuVal = trim((string)($row['sku'] ?? '')); $thumbId = $recentThumbs[strtoupper($skuVal)] ?? null; ?>
                  <?php if ($thumbId): ?>
                    <a class="thumb" href="photo.php?id=<?php echo $thumbId; ?>" target="_blank" rel="noopener">
                      <img src="photo.php?id=<?php echo $thumbId; ?>" alt="Photo for <?php echo htmlspecialchars($skuVal ?: 'SKU', ENT_QUOTES, 'UTF-8'); ?>">
                    </a>
                  <?php else: ?>
                    <span class="thumb placeholder" title="No photo added">No photo</span>
                  <?php endif; ?>
                  <span class="sku"><?php echo htmlspecialchars($skuVal ?: 'Unknown', ENT_QUOTES, 'UTF-8'); ?></span>
                  <span class="status-chip"><?php echo htmlspecialchars($row['status'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></span>
                  <span class="what"><?php echo htmlspecialchars($row['what_is_it'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="activity-meta">
                  <span><?php echo htmlspecialchars($row['updated_at'] ?: '', ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="hint">No recent activity to show.</p>
        <?php endif; ?>
      </section>
      <?php endif; ?>
    </section>

      <?php if ($isLookupPage): ?>
      <section class="section lookup-shell" aria-live="polite" id="sku-lookup-shell">
        <div class="lookup-grid">
        <div class="lookup-card">
          <h2>SKU Lookup</h2>
          <p class="hint">Search by SKU or filter by status. Results preview live as you type.</p>
          <div class="hint" id="recent-skus" aria-label="Recently viewed SKUs"></div>
          <p class="hint"><button type="button" class="ghost" id="clear-recent-skus">Clear recent SKUs</button></p>
            <form class="form-grid" method="get" action="intake.php" id="sku-lookup">
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
            <div class="filter-chips" id="lookup-chips">
              <button type="button" data-lookup-status="">Any</button>
              <button type="button" data-lookup-status="Intake">Intake</button>
              <button type="button" data-lookup-status="Description">Description</button>
              <button type="button" data-lookup-status="Tested">Tested</button>
              <button type="button" data-lookup-status="Listed">Listed</button>
              <button type="button" data-lookup-status="SOLD">Sold</button>
              <button type="button" data-lookup-stale="7">Stale >7d</button>
              <button type="button" data-lookup-stale="30">Stale >30d</button>
            </div>
            <div class="actions lookup-actions">
              <button type="submit">Open in intake</button>
              <button type="button" id="lookup-preview-refresh" class="ghost">Refresh preview</button>
              <button type="button" id="lookup-clear-filters" class="ghost">Clear filters</button>
            </div>
          </form>
        </div>
        <div class="lookup-card lookup-results">
          <div class="lookup-results-header">
            <div>
              <h2>Preview matches</h2>
              <p class="hint" id="lookup-preview-message">Type two characters or select a status to see recent entries.</p>
            </div>
            <div class="lookup-results-actions">
              <button type="button" class="ghost" id="lookup-load-more">Load more</button>
              <button type="button" class="ghost" id="lookup-export-csv">Export CSV</button>
              <button type="button" class="ghost" id="lookup-copy-link">Copy link</button>
              <a class="button-link subtle" href="lookup_preview.php">Preview API</a>
            </div>
            <div class="gap-chips" id="gap-chips">
              <button type="button" data-gap="">Any</button>
              <button type="button" data-gap="no-photos">No photos</button>
              <button type="button" data-gap="no-price">No price</button>
            </div>
          </div>
          <div class="table-wrap">
            <table class="lookup-table">
              <thead>
                <tr>
                  <th>SKU</th>
                  <th>Status</th>
                  <th>What is it?</th>
                  <th>Updated</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="lookup-preview-body">
                <tr>
                  <td colspan="4">No lookup terms yet.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </section>
      <?php endif; ?>
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

      var intakeLinks = document.querySelectorAll('[data-new-intake]');
      if (intakeLinks.length) {
        var clearIntakeDraft = function () {
          try {
            localStorage.removeItem('intakeDraftV1');
          } catch (e) {}
        };
        intakeLinks.forEach(function (link) {
          link.addEventListener('click', clearIntakeDraft);
        });
      }
      var menuToggle = document.getElementById('menu-toggle');
      var menuPanel = document.getElementById('global-menu');
      if (!menuToggle || !menuPanel) {
        return;
      }
      var bodyElement = document.body;

      var setMenuState = function (open) {
        menuPanel.classList.toggle('is-open', open);
        menuPanel.setAttribute('aria-hidden', open ? 'false' : 'true');
        menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        bodyElement.classList.toggle('has-open-menu', open);
      };

      var closeMenu = function () {
        setMenuState(false);
      };

      menuToggle.addEventListener('click', function () {
        var opening = !menuPanel.classList.contains('is-open');
        setMenuState(opening);

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
        if (event.ctrlKey && event.key.toLowerCase() === 'l') {
          if (event.target && (event.target.tagName === 'INPUT' || event.target.tagName === 'TEXTAREA')) return;
          event.preventDefault();
          var skuInput = document.querySelector('#sku-lookup [name=\"sku\"]');
          if (skuInput) {
            skuInput.focus();
            skuInput.select();
          }
        }
        if (event.ctrlKey && event.key.toLowerCase() === 'n') {
          event.preventDefault();
          var newLink = document.querySelector('[data-new-intake]');
          if (newLink) newLink.click();
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
        var refreshBtn = document.getElementById('lookup-preview-refresh');
        var clearBtn = document.getElementById('lookup-clear-filters');
        var chipRow = document.getElementById('lookup-chips');
        var filterState = { staleDays: 0 };
        var backupButtons = Array.prototype.slice.call(document.querySelectorAll('[data-run-backup]'));
        var verifyButtons = Array.prototype.slice.call(document.querySelectorAll('[data-verify-backup]'));
        var loadMoreBtn = document.getElementById('lookup-load-more');
        var exportBtn = document.getElementById('lookup-export-csv');
        var previewLimit = 20;
        var recentSkuKey = 'pinksheetRecentSkus';
        var filterKey = 'pinksheetLookupFilter';
        var gapChips = document.getElementById('gap-chips');
        var gapState = { noPhotos: false, missingPrice: false };

        var saveFilter = function () {
          try {
            localStorage.setItem(filterKey, JSON.stringify({
              sku: (skuInput && skuInput.value) || '',
              status: (statusSelect && statusSelect.value) || '',
              staleDays: filterState.staleDays || 0,
            }));
          } catch (e) {}
        };
        var loadFilter = function () {
          try {
            var saved = localStorage.getItem(filterKey);
            if (!saved) return;
            var data = JSON.parse(saved);
            if (skuInput && typeof data.sku === 'string') skuInput.value = data.sku;
            if (statusSelect && typeof data.status === 'string') statusSelect.value = data.status;
            if (chipRow && data.staleDays) {
              filterState.staleDays = data.staleDays;
              var btn = chipRow.querySelector('[data-lookup-stale="' + data.staleDays + '"]');
              if (btn) btn.click();
            }
          } catch (e) {}
        };
        var addRecentSku = function (value) {
          if (!value) return;
          try {
            var saved = JSON.parse(localStorage.getItem(recentSkuKey) || '[]');
            saved = [value].concat(saved.filter(function (v) { return v !== value; })).slice(0, 6);
            localStorage.setItem(recentSkuKey, JSON.stringify(saved));
            renderRecentSkus(saved);
          } catch (e) {}
        };
        var renderRecentSkus = function (list) {
          var host = document.getElementById('recent-skus');
          if (!host) return;
          host.innerHTML = '';
          (list || []).forEach(function (sku) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = sku;
            btn.addEventListener('click', function () {
              if (skuInput) skuInput.value = sku;
              schedulePreview();
            });
            host.appendChild(btn);
          });
        };
        var renderActivityRecent = function (list) {
          var host = document.getElementById('recent-activity-skus');
          if (!host) return;
          host.innerHTML = '';
          (list || []).forEach(function (sku) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ghost';
            btn.textContent = sku;
            btn.addEventListener('click', function () {
              if (skuInput) skuInput.value = sku;
              schedulePreview();
            });
            host.appendChild(btn);
          });
        };
        var clearRecentSkus = function () {
          try {
            localStorage.removeItem(recentSkuKey);
            renderRecentSkus([]);
            renderActivityRecent([]);
          } catch (e) {}
        };
        var clearRecentBtn = document.getElementById('clear-recent-skus');
        if (clearRecentBtn) {
          clearRecentBtn.addEventListener('click', function () {
            clearRecentSkus();
          });
        }
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
        var thumbImg = function (entry) {
          var src = entry.photo_url || (entry.photo_id ? ('photo.php?id=' + entry.photo_id) : null);
          if (!src) return null;
          var img = document.createElement('img');
          img.src = src;
          img.alt = 'thumb';
          img.className = 'preview-thumb';
          var wrap = document.createElement('div');
          wrap.className = 'thumb-wrap';
          wrap.appendChild(img);
          return wrap;
        };
        var relativeTime = function (dateString) {
          var t = Date.parse((dateString || '').replace(' ', 'T'));
          if (isNaN(t)) return dateString || '—';
          var diff = Date.now() - t;
          var mins = Math.round(diff / 60000);
          if (mins < 1) return 'just now';
          if (mins < 60) return mins + 'm ago';
          var hrs = Math.round(mins / 60);
          if (hrs < 24) return hrs + 'h ago';
          var days = Math.round(hrs / 24);
          return days + 'd ago';
        };
        var renderPreviewRows = function (items) {
          if (!previewBody) {
            return;
          }
          var filtered = items;
          if (filterState.staleDays > 0) {
            var cutoff = Date.now() - (filterState.staleDays * 86400000);
            filtered = items.filter(function (entry) {
              var t = Date.parse((entry.updated_at || '').replace(' ', 'T'));
              return !isNaN(t) && t < cutoff;
            });
          }
          if (gapState.noPhotos) {
            filtered = filtered.filter(function (entry) {
              return (entry.photo_count || 0) === 0;
            });
          }
          if (gapState.missingPrice) {
            filtered = filtered.filter(function (entry) {
              return !!entry.missing_price;
            });
          }
          previewBody.innerHTML = '';
          if (!filtered.length) {
            previewBody.innerHTML = '<tr><td colspan="4">No matches found.</td></tr>';
            return;
          }
          filtered.forEach(function (entry) {
            var row = document.createElement('tr');
            var skuTd = createCell(entry.sku);
            var thumb = thumbImg(entry);
            if (thumb) {
              skuTd.appendChild(thumb);
            }
            row.appendChild(skuTd);
            var statusTd = document.createElement('td');
            var statusSpan = document.createElement('span');
            statusSpan.className = 'status-chip';
            statusSpan.textContent = entry.status || '—';
            statusTd.appendChild(statusSpan);
          row.appendChild(statusTd);
          row.appendChild(createCell(entry.what_is_it));
          row.appendChild(createCell(relativeTime(entry.updated_at)));
          var actionsTd = document.createElement('td');
          if ((entry.photo_count || 0) === 0) {
            var badge = document.createElement('span');
            badge.className = 'badge warning';
            badge.textContent = 'No photos';
            actionsTd.appendChild(badge);
          }
          if (entry.missing_price) {
            var priceBadge = document.createElement('span');
            priceBadge.className = 'badge warning';
            priceBadge.textContent = 'No price';
            actionsTd.appendChild(priceBadge);
          }
          var inlineStatus = document.createElement('select');
          ['','Intake','Description','Tested','Listed','SOLD'].forEach(function (opt) {
            var o = document.createElement('option');
            o.value = opt;
            o.textContent = opt || 'Set status';
            if (opt === entry.status) o.selected = true;
            inlineStatus.appendChild(o);
          });
          inlineStatus.addEventListener('change', function () {
            updateField(entry.sku, 'status', inlineStatus.value);
          });
          actionsTd.appendChild(inlineStatus);
          var priceInput = document.createElement('input');
          priceInput.type = 'number';
          priceInput.step = '0.01';
          priceInput.placeholder = 'Price';
          priceInput.value = entry.dispotech_price || '';
          priceInput.addEventListener('change', function () {
            updateField(entry.sku, 'dispotech_price', priceInput.value);
          });
          actionsTd.appendChild(priceInput);
          var dupBtn = document.createElement('button');
          dupBtn.type = 'button';
          dupBtn.className = 'ghost';
          dupBtn.textContent = 'Duplicate';
          dupBtn.addEventListener('click', function () {
              if (!entry.sku) return;
              window.location.href = 'intake.php?copy_sku=' + encodeURIComponent(entry.sku);
            });
            actionsTd.appendChild(dupBtn);
          var promptBtn = document.createElement('button');
          promptBtn.type = 'button';
          promptBtn.className = 'ghost subtle';
          promptBtn.textContent = 'eBay Script';
          promptBtn.addEventListener('click', function () {
            if (!entry.sku) return;
            window.location.href = 'prompt_builder.php?sku=' + encodeURIComponent(entry.sku);
          });
          actionsTd.appendChild(promptBtn);
            row.appendChild(actionsTd);
          previewBody.appendChild(row);
        });
          previewMessage.textContent = 'Showing the most recent matches' + (filterState.staleDays > 0 ? ' (stale filter applied)' : '') + '.';
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
          params.set('limit', previewLimit);
          params.set('with_photos', '1');
          if (!params.toString()) {
            resetPreview();
            return;
          }
          saveFilter();
          previewMessage.textContent = 'Loading preview...';
          previewMessage.classList.remove('hint-warning');
          var tableEl = previewBody ? previewBody.closest('table') : null;
          if (tableEl) tableEl.classList.add('loading');
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
              addRecentSku(skuValue || (items[0] && items[0].sku));
              renderPreviewRows(items);
            })
            .catch(function () {
              previewMessage.textContent = 'Could not load preview right now.';
              previewMessage.classList.add('hint-warning');
            })
            .finally(function () {
              if (tableEl) tableEl.classList.remove('loading');
            });
        };
        var schedulePreview = function () {
          if (!window.fetch) {
            return;
          }
          clearTimeout(previewTimer);
          previewTimer = setTimeout(requestPreview, 220);
        };
        if (refreshBtn) {
          refreshBtn.addEventListener('click', function () {
            requestPreview();
          });
        }
        if (clearBtn) {
          clearBtn.addEventListener('click', function () {
            if (skuInput) skuInput.value = '';
            if (statusSelect) statusSelect.value = '';
            filterState.staleDays = 0;
            if (chipRow) {
              Array.prototype.forEach.call(chipRow.querySelectorAll('button'), function (b) {
                b.classList.toggle('is-active', b.getAttribute('data-lookup-status') === '');
              });
            }
            saveFilter();
            resetPreview();
          });
        }
        var toastBox = null;
        var ensureToast = function () {
          if (toastBox) return toastBox;
          toastBox = document.createElement('div');
          toastBox.id = 'toast-box';
          toastBox.style.position = 'fixed';
          toastBox.style.bottom = '16px';
          toastBox.style.right = '16px';
          toastBox.style.zIndex = '9999';
          toastBox.style.display = 'flex';
          toastBox.style.flexDirection = 'column';
          toastBox.style.gap = '8px';
          document.body.appendChild(toastBox);
          return toastBox;
        };
        var showToast = function (message, ok) {
          var box = ensureToast();
          var el = document.createElement('div');
          el.textContent = message;
          el.style.padding = '10px 14px';
          el.style.borderRadius = '6px';
          el.style.color = '#0b1721';
          el.style.background = ok ? '#c5f7d7' : '#ffd7d7';
          el.style.boxShadow = '0 4px 10px rgba(0,0,0,0.1)';
          el.style.fontWeight = '600';
          box.appendChild(el);
          setTimeout(function () {
            if (el.parentNode) el.parentNode.removeChild(el);
          }, 4200);
        };
        var updateField = function (sku, field, value) {
          fetch('update_item.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'sku=' + encodeURIComponent(sku) + '&field=' + encodeURIComponent(field) + '&value=' + encodeURIComponent(value)
          })
            .then(function (r) { return r.json(); })
            .then(function (data) {
              if (data.ok) {
                showToast('Updated ' + field, true);
              } else {
                showToast('Update failed: ' + (data.error || 'error'), false);
              }
            })
            .catch(function () { showToast('Update failed.', false); });
        };
        if (backupButtons.length) {
          var setBackupState = function (running) {
            backupButtons.forEach(function (btn) {
              btn.disabled = running;
              if (running) {
                btn.dataset.originalText = btn.dataset.originalText || btn.textContent;
                btn.textContent = 'Running… (~5s)';
              } else if (btn.dataset.originalText) {
                btn.textContent = btn.dataset.originalText;
              }
            });
          };
          var runBackup = function () {
            setBackupState(true);
            fetch('backup_now.php', { method: 'POST' })
              .then(function (r) { return r.json(); })
              .then(function (data) {
                if (data.ok) {
                  showToast('Backup finished', true);
                  window.setTimeout(function () { window.location.reload(); }, 600);
                } else {
                  showToast('Backup failed: ' + (data.error || ('exit ' + data.exit)), false);
                }
              })
              .catch(function () { showToast('Backup failed.', false); })
              .finally(function () {
                setBackupState(false);
              });
          };
          backupButtons.forEach(function (btn) {
            btn.addEventListener('click', runBackup);
          });
        }

        var verifyButtons = Array.prototype.slice.call(document.querySelectorAll('[data-verify-backup]'));
        if (verifyButtons.length) {
          var setVerifyState = function (running) {
            verifyButtons.forEach(function (btn) {
              btn.disabled = running;
              if (running) {
                btn.dataset.originalText = btn.dataset.originalText || btn.textContent;
                btn.textContent = 'Verifying…';
              } else if (btn.dataset.originalText) {
                btn.textContent = btn.dataset.originalText;
              }
            });
          };
          var runVerify = function () {
            setVerifyState(true);
            fetch('verify_now.php', { method: 'POST' })
              .then(function (r) { return r.json(); })
              .then(function (data) {
                if (data.ok) {
                  showToast('Backup verified', true);
                } else {
                  showToast('Verify failed: ' + (data.error || ('exit ' + data.exit)), false);
                }
              })
              .catch(function () { showToast('Verify failed.', false); })
              .finally(function () {
                setVerifyState(false);
              });
          };
          verifyButtons.forEach(function (btn) {
            btn.addEventListener('click', runVerify);
          });
        }

        var healthChip = document.getElementById('health-chip');
        if (healthChip && window.fetch) {
          fetch('health.php')
            .then(function (r) { return r.json(); })
            .then(function (data) {
              var txt = 'Health: ok';
              var cls = 'badge subtle';
              if (data.maintenance) {
                txt = 'Health: maintenance';
                cls = 'badge danger';
              } else if (data.backup && typeof data.backup.age_hours === 'number') {
                if (data.backup.age_hours > 36) {
                  txt = 'Health: backup stale';
                  cls = 'badge warning';
                } else if (data.backup.checksum_ok === false) {
                  txt = 'Health: checksum mismatch';
                  cls = 'badge danger';
                } else {
                  txt = 'Health: ok · ' + data.backup.latest;
                  cls = 'badge subtle';
                }
              }
              healthChip.textContent = txt;
              healthChip.className = cls;
            })
            .catch(function () {
              healthChip.textContent = 'Health: unknown';
              healthChip.className = 'badge warning';
            });
        }
        if (chipRow) {
          chipRow.addEventListener('click', function (event) {
            if (!event.target || event.target.tagName !== 'BUTTON') return;
            var btn = event.target;
            var status = btn.getAttribute('data-lookup-status');
            var stale = parseInt(btn.getAttribute('data-lookup-stale') || '0', 10) || 0;
            if (status !== null && statusSelect) {
              statusSelect.value = status;
            }
            filterState.staleDays = stale;
            Array.prototype.forEach.call(chipRow.querySelectorAll('button'), function (b) {
              b.classList.toggle('is-active', b === btn);
            });
            schedulePreview();
            saveFilter();
          });
        }
        if (gapChips) {
          gapChips.addEventListener('click', function (event) {
            if (!event.target || event.target.tagName !== 'BUTTON') return;
            var btn = event.target;
            var gap = btn.getAttribute('data-gap') || '';
            gapState.noPhotos = gap === 'no-photos';
            gapState.missingPrice = gap === 'no-price';
            Array.prototype.forEach.call(gapChips.querySelectorAll('button'), function (b) {
              b.classList.toggle('is-active', b === btn);
            });
            schedulePreview();
          });
        }
        if (skuInput) {
          skuInput.addEventListener('input', function () {
            schedulePreview();
          });
        }
        if (statusSelect) {
          statusSelect.addEventListener('change', function () {
            schedulePreview();
            saveFilter();
          });
        }
        if (loadMoreBtn) {
          loadMoreBtn.addEventListener('click', function () {
            previewLimit = Math.min(previewLimit + 20, 200);
            schedulePreview();
          });
        }
        if (exportBtn) {
          exportBtn.addEventListener('click', function () {
            if (!window.fetch) return;
            var skuValue = (skuInput && skuInput.value.trim()) || '';
            var statusValue = (statusSelect && statusSelect.value.trim()) || '';
            if (skuValue === '' && statusValue === '') {
              showToast('Add a SKU or status before exporting.', false);
              return;
            }
            var params = new URLSearchParams();
            if (skuValue !== '') params.set('sku', skuValue);
            if (statusValue !== '') params.set('status', statusValue);
            params.set('limit', 200);
            fetch('lookup_preview.php?' + params.toString())
              .then(function (r) { return r.json(); })
              .then(function (items) {
                if (!Array.isArray(items) || !items.length) {
                  showToast('Nothing to export.', false);
                  return;
                }
                var header = ['SKU', 'Status', 'What is it?', 'Updated'];
                var rows = items.map(function (i) {
                  return [
                    (i.sku || '').replace(/\"/g, '\"\"'),
                    (i.status || '').replace(/\"/g, '\"\"'),
                    (i.what_is_it || '').replace(/\"/g, '\"\"'),
                    i.updated_at || ''
                  ];
                });
                var csv = [header].concat(rows).map(function (row) {
                  return row.map(function (cell) { return '\"' + cell + '\"'; }).join(',');
                }).join('\\r\\n');
                var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'lookup_export.csv';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
                showToast('Exported ' + rows.length + ' rows.', true);
              })
              .catch(function () { showToast('Export failed.', false); });
          });
        }
        var copyLinkBtn = document.getElementById('lookup-copy-link');
        if (copyLinkBtn && navigator.clipboard) {
          copyLinkBtn.addEventListener('click', function () {
            var params = new URLSearchParams();
            var skuVal = (skuInput && skuInput.value.trim()) || '';
            var statusVal = (statusSelect && statusSelect.value.trim()) || '';
            if (skuVal) params.set('sku', skuVal);
            if (statusVal) params.set('status', statusVal);
            if (filterState.staleDays) params.set('stale', String(filterState.staleDays));
            if (gapState.noPhotos) params.set('gap', 'no-photos');
            if (gapState.missingPrice) params.set('gap', 'no-price');
            var url = window.location.origin + window.location.pathname + '?' + params.toString() + '#sku-lookup-shell';
            navigator.clipboard.writeText(url)
              .then(function () { showToast('Lookup link copied', true); })
              .catch(function () { showToast('Copy failed.', false); });
          });
        }
        loadFilter();
        try {
          var recentList = JSON.parse(localStorage.getItem(recentSkuKey) || '[]');
          renderRecentSkus(recentList);
          renderActivityRecent(recentList);
        } catch (e) {}
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
