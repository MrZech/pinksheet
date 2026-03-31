<?php
require_once __DIR__ . '/config.php';
checkMaintenance();
ensureStorageWritable();
$currentPage = 'home';
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
$alerts = [];
$latestBackup = null;
$latestBackupAgeHours = null;
$latestBackupSize = null;
$backupBadge = null;
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
        if ($latestBackupAgeHours !== null && $latestBackupAgeHours > 36) {
            $alerts[] = 'Latest backup is older than 36 hours.';
        }
    } else {
        $alerts[] = 'No backups found in data/backups.';
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
  <title>Dispo.Tech Intake Home</title>
  <link rel="stylesheet" href="assets/style.css">
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
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
          <li><a class="menu-link <?php echo $currentPage === 'intake' ? 'is-active' : ''; ?>" href="index.php?clear_draft=1" data-new-intake>New Intake</a></li>
        </ul>
      </nav>
    </div>
    <section class="sheet home-sheet">
      <header class="sheet-header">
        <div class="updated">Dispo.Tech Intake</div>
        <div class="sheet-header-right">
          <?php if ($backupBadge): ?>
            <span class="badge" title="Latest backup"><?php echo htmlspecialchars($backupBadge, ENT_QUOTES, 'UTF-8'); ?></span>
          <?php endif; ?>
          <button type="button" class="print-button" id="print-button">Print</button>
          <button type="button" class="theme-toggle" id="theme-toggle">Dark mode</button>
          <a class="button-link new-intake-cta" href="index.php?clear_draft=1" data-new-intake>New Intake</a>
        </div>
      </header>
      <h1>Ops Home</h1>
      <nav class="breadcrumbs" aria-label="Breadcrumb">
        <a href="home.php">Home</a>
        <span>Dashboard</span>
      </nav>
      <p class="lead">Snapshot of intake health plus a focused SKU search workspace.</p>

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
          </div>
        </div>
      </section>

      <section class="section activity">
        <h2>Recent activity</h2>
        <?php if (!empty($recentActivity)): ?>
          <ul class="activity-list">
            <?php foreach ($recentActivity as $row): ?>
              <li>
                <div class="activity-main">
                  <span class="sku"><?php echo htmlspecialchars($row['sku'] ?: 'Unknown', ENT_QUOTES, 'UTF-8'); ?></span>
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

      <section class="section quick-actions">
        <h2>Quick actions</h2>
        <div class="quick-links">
          <a class="button-link" href="index.php?clear_draft=1" data-new-intake>New Intake</a>
          <a class="button-link" href="#sku-lookup-shell">Search SKUs</a>
          <a class="button-link" href="upload_photo.php">Upload photos</a>
          <a class="button-link" href="docs/maintenance.md">Maintenance docs</a>
          <button type="button" class="button-link ghost" id="run-backup-now">Run backup now</button>
        </div>
      </section>
    </section>

    <section class="section lookup-shell" aria-live="polite" id="sku-lookup-shell">
      <div class="lookup-grid">
        <div class="lookup-card">
          <h2>SKU Lookup</h2>
          <p class="hint">Search by SKU or filter by status. Results preview live as you type.</p>
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
            <div class="filter-chips" id="lookup-chips">
              <button type="button" data-lookup-status="">Any</button>
              <button type="button" data-lookup-status="Intake">Intake</button>
              <button type="button" data-lookup-status="Description">Description</button>
              <button type="button" data-lookup-status="Tested">Tested</button>
              <button type="button" data-lookup-status="Listed">Listed</button>
              <button type="button" data-lookup-status="SOLD">Sold</button>
              <button type="button" data-lookup-stale="7">Stale >7d</button>
            </div>
            <div class="actions lookup-actions">
              <button type="submit">Open in intake</button>
              <button type="button" id="lookup-preview-refresh" class="ghost">Refresh preview</button>
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
              <a class="button-link subtle" href="lookup_preview.php">Preview API</a>
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
        var chipRow = document.getElementById('lookup-chips');
        var filterState = { staleDays: 0 };
        var runBackupBtn = document.getElementById('run-backup-now');
        var loadMoreBtn = document.getElementById('lookup-load-more');
        var previewLimit = 20;
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
          previewBody.innerHTML = '';
          if (!filtered.length) {
            previewBody.innerHTML = '<tr><td colspan="4">No matches found.</td></tr>';
            return;
          }
          filtered.forEach(function (entry) {
            var row = document.createElement('tr');
            var skuTd = createCell(entry.sku);
            if (entry.photo_url) {
              var img = document.createElement('img');
              img.src = entry.photo_url;
              img.alt = 'thumb';
              img.className = 'preview-thumb';
              var wrap = document.createElement('div');
              wrap.className = 'thumb-wrap';
              wrap.appendChild(img);
              skuTd.appendChild(wrap);
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
          if (!params.toString()) {
            resetPreview();
            return;
          }
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
        if (runBackupBtn) {
          runBackupBtn.addEventListener('click', function () {
            runBackupBtn.disabled = true;
            runBackupBtn.textContent = 'Running...';
            fetch('backup_now.php', { method: 'POST' })
              .then(function (r) { return r.json(); })
              .then(function (data) {
                if (data.ok) {
                  alert('Backup finished.');
                } else {
                  alert('Backup failed: ' + (data.error || ('exit ' + data.exit)));
                }
              })
              .catch(function () { alert('Backup failed.'); })
              .finally(function () {
                runBackupBtn.disabled = false;
                runBackupBtn.textContent = 'Run backup now';
              });
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
          });
        }
        if (loadMoreBtn) {
          loadMoreBtn.addEventListener('click', function () {
            previewLimit = Math.min(previewLimit + 20, 100);
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
