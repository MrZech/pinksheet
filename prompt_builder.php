<?php
require_once __DIR__ . '/config.php';
checkMaintenance();
ensureStorageWritable();

const PROMPT_DB_PATH = __DIR__ . '/data/intake.sqlite';

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function normalizeSku(string $sku): string
{
    return strtoupper(trim($sku));
}

function formatLabel(string $key): string
{
    return ucwords(str_replace('_', ' ', $key));
}

$currentPage = 'prompt';
$statusMessage = '';
$recentSkus = [];
$currentSku = trim((string)($_GET['sku'] ?? ($_GET['copy_sku'] ?? '')));
$currentSkuNormalized = normalizeSku($currentSku);
$currentItem = null;
$photoCount = 0;
$intakeLink = $currentSkuNormalized !== '' ? 'intake.php?sku=' . urlencode($currentSkuNormalized) : 'intake.php?clear_draft=1';

if (is_readable(PROMPT_DB_PATH)) {
    try {
        $pdo = new PDO('sqlite:' . PROMPT_DB_PATH, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $recentStmt = $pdo->query("
            SELECT sku
            FROM intake_items
            WHERE sku IS NOT NULL AND TRIM(sku) <> ''
            ORDER BY updated_at DESC, id DESC
            LIMIT 60
        ");
        $recentSkus = array_values(array_filter(array_unique(array_map('trim', $recentStmt->fetchAll(PDO::FETCH_COLUMN))), static fn ($sku): bool => $sku !== ''));

        if ($currentSkuNormalized !== '') {
            $itemStmt = $pdo->prepare('SELECT * FROM intake_items WHERE sku_normalized = :sku ORDER BY id DESC LIMIT 1');
            $itemStmt->execute(['sku' => $currentSkuNormalized]);
            $currentItem = $itemStmt->fetch() ?: null;

            $photoStmt = $pdo->prepare('SELECT COUNT(*) FROM sku_photos WHERE sku_normalized = :sku');
            $photoStmt->execute(['sku' => $currentSkuNormalized]);
            $photoCount = (int)$photoStmt->fetchColumn();
        }
    } catch (Throwable $e) {
        $statusMessage = 'Could not read the database right now.';
    }
} else {
    $statusMessage = 'Database file is missing or unreadable.';
}

$initialItemJson = $currentItem ? json_encode($currentItem, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) : 'null';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dispo.Tech Prompt Builder</title>
  <link rel="stylesheet" href="assets/style.css">
  <link rel="stylesheet" media="print" href="assets/print.css">
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <style>
    .prompt-grid {
      align-items: start;
    }
    .prompt-output {
      width: 100%;
      min-height: 360px;
      resize: vertical;
      font-family: inherit;
      line-height: 1.45;
    }
    .prompt-source {
      margin-top: 12px;
      display: grid;
      gap: 10px;
    }
    .prompt-source-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 10px;
    }
    .prompt-field {
      border: 1px solid var(--line);
      border-radius: 12px;
      padding: 10px 12px;
      background: rgba(255, 255, 255, 0.7);
    }
    .prompt-field .label {
      display: block;
      font-size: 0.72rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--muted);
      margin-bottom: 4px;
    }
    .prompt-field .value {
      color: var(--ink);
      word-break: break-word;
    }
    .prompt-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      align-items: center;
    }
    .prompt-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      align-items: center;
      margin-top: 10px;
    }
    body.dark-mode .prompt-field {
      background: rgba(255, 255, 255, 0.05);
      border-color: var(--line-dark);
    }
  </style>
</head>
<body class="home prompt-page">
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
          <li><a class="menu-link <?php echo $currentPage === 'prompt' ? 'is-active' : ''; ?>" href="prompt_builder.php">Prompt Builder</a></li>
        </ul>
      </nav>
    </div>

    <section class="sheet home-sheet">
      <header class="sheet-header">
        <div class="updated">Dispo.Tech Prompt Builder</div>
        <div class="sheet-header-right">
          <span class="badge subtle" id="prompt-status-chip" title="Prompt status">Ready</span>
          <button type="button" class="theme-toggle" id="theme-toggle">Dark mode</button>
        </div>
      </header>

      <h1>SKU Prompt Builder</h1>
      <nav class="breadcrumbs" aria-label="Breadcrumb">
        <a href="home.php">Home</a>
        <span>Prompt Builder</span>
      </nav>
      <p class="lead">Load a SKU record, generate a clean ChatGPT prompt from the inventory data, and copy it for later use.</p>

      <?php if ($statusMessage !== ''): ?>
        <div class="alert-block" role="status">
          <div class="alert-item"><?php echo h($statusMessage); ?></div>
        </div>
      <?php endif; ?>

      <section class="section prompt-builder-shell">
        <div class="lookup-grid prompt-grid">
          <div class="lookup-card">
            <h2>Load SKU</h2>
            <p class="hint">Pick a recent SKU or type one directly, then generate a prompt from the latest record.</p>
            <div class="hint" id="prompt-recent-skus" aria-label="Recently viewed SKUs"></div>
            <form class="form-grid" id="prompt-form">
              <div class="row">
                <label>SKU
                  <input type="text" id="prompt-sku" list="prompt-sku-suggestions" value="<?php echo h($currentSku); ?>" autofocus>
                </label>
                <datalist id="prompt-sku-suggestions">
                  <?php foreach ($recentSkus as $sku): ?>
                    <option value="<?php echo h($sku); ?>">
                  <?php endforeach; ?>
                </datalist>
              </div>
              <div class="prompt-actions">
                <button type="submit" id="generate-prompt-btn">Generate prompt</button>
                <button type="button" class="ghost" id="clear-prompt-btn">Clear</button>
                <a class="button-link subtle" href="<?php echo h($intakeLink); ?>">Open in intake</a>
              </div>
            </form>

            <div class="prompt-source" id="prompt-source-wrap">
              <h3>Source facts</h3>
              <div class="prompt-source-grid" id="prompt-source">
                <div class="prompt-field">
                  <span class="label">SKU</span>
                  <span class="value"><?php echo $currentSkuNormalized !== '' ? h($currentSkuNormalized) : 'No SKU loaded'; ?></span>
                </div>
              </div>
            </div>
          </div>

          <div class="lookup-card lookup-results">
            <div class="lookup-results-header">
              <div>
                <h2>Prompt output</h2>
                <p class="hint">Edit the generated text if needed, then copy it into ChatGPT.</p>
              </div>
              <div class="lookup-results-actions">
                <button type="button" class="ghost" id="copy-prompt-btn">Copy prompt</button>
                <a class="button-link subtle" href="lookup.php">Back to lookup</a>
              </div>
            </div>
            <textarea class="prompt-output" id="prompt-output" spellcheck="false" aria-label="Generated ChatGPT prompt"></textarea>
          </div>
        </div>
      </section>
    </section>
  </main>

  <script>
    (function () {
      var themeToggle = document.getElementById('theme-toggle');
      var statusChip = document.getElementById('prompt-status-chip');
      var skuInput = document.getElementById('prompt-sku');
      var form = document.getElementById('prompt-form');
      var clearBtn = document.getElementById('clear-prompt-btn');
      var copyBtn = document.getElementById('copy-prompt-btn');
      var promptOutput = document.getElementById('prompt-output');
      var sourceWrap = document.getElementById('prompt-source');
      var initialItem = <?php echo $initialItemJson; ?>;

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
          try { localStorage.setItem('themePreference', nextMode); } catch (e) {}
        });
      }

      var setStatus = function (message, tone) {
        if (!statusChip) return;
        statusChip.textContent = message;
        statusChip.className = tone ? ('badge ' + tone) : 'badge subtle';
      };

      var labelMap = {
        sku: 'SKU',
        status: 'Status',
        what_is_it: 'What Is It',
        date_received: 'Date Received',
        source: 'Source',
        functional: 'Functional',
        condition: 'Condition',
        cords_adapters: 'Cords Adapters',
        keep_items_together: 'Keep Items Together',
        picture_taken: 'Picture Taken',
        power_on: 'Power On',
        brand_model: 'Brand Model',
        ram: 'RAM',
        ssd_gb: 'SSD GB',
        cpu: 'CPU',
        os: 'OS',
        battery_health: 'Battery Health',
        graphics_card: 'Graphics Card',
        screen_resolution: 'Screen Resolution',
        where_it_goes: 'Where It Goes',
        ebay_status: 'eBay Status',
        ebay_price: 'eBay Price',
        dispotech_price: 'DispoTech Price',
        in_ebay_room: 'In eBay Room',
        what_box: 'What Box',
        notes: 'Notes'
      };

      var buildFactLines = function (sku, item) {
        var lines = [];
        lines.push('SKU: ' + sku);
        Object.keys(labelMap).forEach(function (key) {
          if (key === 'sku') return;
          var value = item && item[key];
          if (value === null || typeof value === 'undefined') return;
          var text = String(value).trim();
          if (text === '') return;
          lines.push(labelMap[key] + ': ' + text);
        });
        return lines;
      };

      var buildPrompt = function (sku, item) {
        var factLines = buildFactLines(sku, item);
        var promptLines = [
          'You are helping me prepare an eBay listing from an internal inventory record.',
          '',
          'Use the facts below as the source of truth. Do not invent details. If a field is missing, omit it.',
          'If the item looks like a computer or electronics device, you may research missing public specs such as model family, UPC, MPN, dimensions, storage type, and ports using reliable sources.',
          'Keep the result factual and neutral. Do not use sales language or unsupported claims.',
          '',
          'Return:',
          '1. A recommended eBay title, 80 characters max',
          '2. A concise description',
          '3. Key item specifics, one per line',
          '4. Any missing facts worth researching',
          '5. A short shipping or packaging note if it is actually helpful',
          '',
          'Inventory record:',
          factLines.map(function (line) { return '- ' + line; }).join('\n')
        ];
        return promptLines.join('\n');
      };

      var renderSource = function (sku, item) {
        if (!sourceWrap) return;
        sourceWrap.innerHTML = '';
        var entries = [
          ['SKU', sku],
          ['Status', item && item.status],
          ['What is it?', item && item.what_is_it],
          ['Brand / Model', item && item.brand_model],
          ['RAM', item && item.ram],
          ['SSD GB', item && item.ssd_gb],
          ['CPU', item && item.cpu],
          ['OS', item && item.os],
          ['Battery Health', item && item.battery_health],
          ['eBay Price', item && item.ebay_price],
          ['DispoTech Price', item && item.dispotech_price],
          ['Notes', item && item.notes]
        ];
        entries.forEach(function (entry) {
          var value = entry[1];
          if (value === null || typeof value === 'undefined' || String(value).trim() === '') {
            return;
          }
          var block = document.createElement('div');
          block.className = 'prompt-field';
          var label = document.createElement('span');
          label.className = 'label';
          label.textContent = entry[0];
          var text = document.createElement('span');
          text.className = 'value';
          text.textContent = String(value);
          block.appendChild(label);
          block.appendChild(text);
          sourceWrap.appendChild(block);
        });
        if (!sourceWrap.children.length) {
          var empty = document.createElement('div');
          empty.className = 'prompt-field';
          empty.innerHTML = '<span class="label">Source</span><span class="value">No item loaded yet.</span>';
          sourceWrap.appendChild(empty);
        }
      };

      var loadSku = function (sku) {
        var normalized = (sku || '').trim().toUpperCase();
        if (!normalized) {
          setStatus('Enter a SKU first', 'warning');
          promptOutput.value = '';
          renderSource('', null);
          return;
        }
        setStatus('Loading...', 'warning');
        fetch('copy_item.php?sku=' + encodeURIComponent(normalized))
          .then(function (resp) {
            return resp.json().then(function (data) {
              if (!resp.ok) {
                throw new Error(data.message || 'Could not load that SKU.');
              }
              return data;
            });
          })
          .then(function (payload) {
            if (!payload || payload.status !== 'ok' || !payload.data) {
              throw new Error('No record found for that SKU.');
            }
            var item = payload.data;
            promptOutput.value = buildPrompt(normalized, item);
            renderSource(normalized, item);
            setStatus('Prompt ready', 'subtle');
          })
          .catch(function (err) {
            promptOutput.value = '';
            renderSource(normalized, null);
            setStatus(err.message || 'Load failed', 'warning');
          });
      };

      if (form) {
        form.addEventListener('submit', function (event) {
          event.preventDefault();
          loadSku(skuInput ? skuInput.value : '');
        });
      }

      if (clearBtn) {
        clearBtn.addEventListener('click', function () {
          if (skuInput) skuInput.value = '';
          promptOutput.value = '';
          renderSource('', null);
          setStatus('Ready', 'subtle');
          if (skuInput) skuInput.focus();
        });
      }

      if (copyBtn && promptOutput) {
        copyBtn.addEventListener('click', function () {
          var text = promptOutput.value.trim();
          if (!text) {
            setStatus('Generate a prompt first', 'warning');
            return;
          }
          var copied = false;
          var fallbackCopy = function () {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'absolute';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            try {
              document.execCommand('copy');
              copied = true;
            } catch (e) {}
            document.body.removeChild(ta);
          };
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text)
              .then(function () { copied = true; setStatus('Prompt copied', 'subtle'); })
              .catch(function () { fallbackCopy(); setStatus(copied ? 'Prompt copied' : 'Copy failed', copied ? 'subtle' : 'warning'); });
          } else {
            fallbackCopy();
            setStatus(copied ? 'Prompt copied' : 'Copy failed', copied ? 'subtle' : 'warning');
          }
        });
      }

      if (skuInput && skuInput.value.trim()) {
        loadSku(skuInput.value);
      } else if (initialItem) {
        renderSource(skuInput ? skuInput.value.trim().toUpperCase() : '', initialItem);
        promptOutput.value = buildPrompt((skuInput ? skuInput.value.trim().toUpperCase() : ''), initialItem);
        setStatus('Prompt ready', 'subtle');
      } else {
        promptOutput.value = [
          'Enter a SKU and click Generate prompt.',
          '',
          'This page will assemble a ChatGPT-ready prompt from the latest inventory record.'
        ].join('\n');
        setStatus('Ready', 'subtle');
      }
    })();
  </script>
</body>
</html>
