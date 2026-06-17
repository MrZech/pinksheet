<?php
require_once __DIR__ . '/config.php';
checkMaintenance();
ensureStorageWritable();

const PROMPT_DB_PATH = __DIR__ . '/data/intake.sqlite';

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatLabel(string $key): string
{
    return ucwords(str_replace('_', ' ', $key));
}

$currentPage = 'script';
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
  <title>Dispo.Tech eBay Script Builder</title>
  <link rel="stylesheet" href="assets/style.css?v=<?= filemtime('assets/style.css') ?>">
  <script src="assets/menu.js?v=<?= filemtime('assets/menu.js') ?>" defer></script>
  <link rel="stylesheet" media="print" href="assets/print.css?v=<?= filemtime('assets/print.css') ?>">
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <script src="assets/qz-tray.js?v=<?= filemtime('assets/qz-tray.js') ?>"></script>
  <script>window.CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;</script>
  <script src="assets/theme.js?v=<?= filemtime('assets/theme.js') ?>" defer></script>
  <script src="assets/app.js?v=<?= filemtime('assets/app.js') ?>"></script>
</head>
<body class="home prompt-page">
  <div class="layout-wrapper">
  <div class="app-menu">
      <button type="button" class="menu-toggle" aria-expanded="false" aria-controls="global-menu" id="menu-toggle">
        <span class="hamburger" aria-hidden="true"></span>
        <span class="menu-label">Menu</span>
      </button>
      <nav class="menu-panel" id="global-menu" aria-hidden="true">
        <ul class="menu-links">
          <li><a class="menu-link <?php echo $currentPage === 'home' ? 'is-active' : ''; ?>" href="home.php">Dashboard</a></li>
          <li><a class="menu-link <?php echo $currentPage === 'intake' ? 'is-active' : ''; ?>" href="intake.php?clear_draft=1" data-new-intake>Intake</a></li>
          <li><a class="menu-link <?php echo $currentPage === 'kanban' ? 'is-active' : ''; ?>" href="kanban.php">Status Board</a></li>
          <li><a class="menu-link <?php echo $currentPage === 'lookup' ? 'is-active' : ''; ?>" href="lookup.php">SKU Lookup</a></li>
          <li><a class="menu-link <?php echo $currentPage === 'archive' ? 'is-active' : ''; ?>" href="archive.php">Archive</a></li>
          <li><a class="menu-link <?php echo $currentPage === 'script' ? 'is-active' : ''; ?>" href="prompt_builder.php">Script Builder</a></li>
        </ul>
      </nav>
    </div>
  <main class="page">
    <section class="sheet home-sheet">
      <header class="sheet-header">
        <div class="updated">Dispo.Tech eBay Script Builder</div>
        <div class="sheet-header-right">
          <span class="autosave-status" id="autosave-status" hidden>Autosave ready</span>
          <span class="badge subtle" id="prompt-status-chip" title="Prompt status">Ready</span>
          <span class="badge subtle" id="save-status-chip" title="Autosave status">No SKU</span>
          <button type="button" class="theme-toggle" id="theme-toggle">Dark mode</button>
        </div>
      </header>

      <h1>SKU eBay Script Builder</h1>
      <nav class="breadcrumbs" aria-label="Breadcrumb">
        <a href="home.php">Dashboard</a>
        <span>eBay Script Builder</span>
      </nav>
      <p class="lead">Load a SKU record, generate a ChatGPT prompt, then paste ChatGPT's response into the final eBay description builder.</p>

      <?php if ($statusMessage !== ''): ?>
        <div class="alert-block" role="status">
          <div class="alert-item"><?php echo h($statusMessage); ?></div>
        </div>
      <?php endif; ?>

      <section class="section prompt-builder-shell">
        <div class="lookup-grid prompt-grid">
          <div class="lookup-card">
            <h2>Select SKU</h2>
            <p class="hint">Pick a recent SKU or type one directly, then generate a prompt from the latest record.</p>
            <div class="hint" id="prompt-recent-skus" aria-label="Recently viewed SKUs"></div>
            <form class="form-grid" id="prompt-form">
              <div class="row">
                <label>SKU
                  <input type="text" id="prompt-sku" name="prompt_sku" list="prompt-sku-suggestions" value="<?php echo h($currentSku); ?>" autofocus>
                </label>
                <datalist id="prompt-sku-suggestions">
                  <?php foreach ($recentSkus as $sku): ?>
                    <option value="<?php echo h($sku); ?>">
                  <?php endforeach; ?>
                </datalist>
              </div>
              <div class="prompt-actions">
                <button type="submit" id="generate-prompt-btn">Build ChatGPT prompt</button>
                <button type="button" class="ghost" id="clear-prompt-btn">Clear SKU</button>
                <a class="button-link subtle" href="<?php echo h($intakeLink); ?>">Open intake sheet</a>
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
                <h2>ChatGPT prompt</h2>
                <p class="hint">Edit the generated text if needed, then copy it into ChatGPT.</p>
              </div>
              <div class="lookup-results-actions">
                <button type="button" class="ghost" id="copy-prompt-btn">Copy to ChatGPT</button>
                <a class="button-link subtle" href="lookup.php">Back to SKU Lookup</a>
              </div>
            </div>
            <textarea class="prompt-output" id="prompt-output" name="prompt_output" spellcheck="false" aria-label="Generated ChatGPT prompt"></textarea>
            <div class="prompt-source stacked-section">
              <h3>Paste ChatGPT output</h3>
              <p class="hint">Paste the response you want to use on eBay, then build the final listing script with the boilerplate below it.</p>
              <textarea class="final-input" id="chatgpt-output" name="chatgpt_output" spellcheck="false" aria-label="Pasted ChatGPT output"></textarea>
              <div class="prompt-actions">
                <button type="button" id="build-final-btn">Build final eBay script</button>
                <button type="button" class="ghost" id="clear-final-btn">Clear final text</button>
              </div>
            </div>
            <div class="prompt-source stacked-section">
              <h3>Final eBay script</h3>
              <p class="hint">This puts your pasted ChatGPT copy above the boilerplate you wanted under the description section.</p>
              <textarea class="prompt-output" id="final-output" name="final_output" spellcheck="false" aria-label="Final eBay listing script"></textarea>
              <div class="prompt-actions">
                <button type="button" class="ghost" id="save-all-btn">Save all</button>
                <button type="button" class="ghost" id="copy-final-btn">Copy final script</button>
              </div>
            </div>
          </div>
        </div>
      </section>
    </section>
  </main>

  <script>
    (function () {
      var statusChip = document.getElementById('prompt-status-chip');
      var skuInput = document.getElementById('prompt-sku');
      var form = document.getElementById('prompt-form');
      var clearBtn = document.getElementById('clear-prompt-btn');
      var copyBtn = document.getElementById('copy-prompt-btn');
      var promptOutput = document.getElementById('prompt-output');
      var chatgptOutput = document.getElementById('chatgpt-output');
      var buildFinalBtn = document.getElementById('build-final-btn');
      var clearFinalBtn = document.getElementById('clear-final-btn');
      var copyFinalBtn = document.getElementById('copy-final-btn');
      var saveAllBtn = document.getElementById('save-all-btn');
      var finalOutput = document.getElementById('final-output');
      var sourceWrap = document.getElementById('prompt-source');
      var saveStatusChip = document.getElementById('save-status-chip');
      var initialItem = <?php echo $initialItemJson; ?>;
      var currentSkuNormalized = '';

      var setStatus = function (message, tone) {
        if (!statusChip) return;
        statusChip.textContent = message;
        statusChip.className = tone ? ('badge ' + tone) : 'badge subtle';
      };

      var setSaveStatus = function (message, tone) {
        if (!saveStatusChip) return;
        saveStatusChip.textContent = message;
        saveStatusChip.className = tone ? ('badge ' + tone) : 'badge subtle';
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
        price: 'Price',
        in_ebay_room: 'In eBay Room',
        what_box: 'What Box',
        notes: 'Notes'
      };

      var finalBoilerplate = [
        'Please Read This First',
        'This product previously belonged to someone who either upgraded or no longer needed it. Regardless of the reason, it has come to us with the hope of finding a new purpose. (Lucky you!)',
        '',
        'Descriptions',
        'We strive to provide accurate product descriptions by including the following details:',
        'The item\'s brand and model name or number.',
        'The item\'s physical condition.',
        'Basic BIOS or system information (if applicable).',
        'Testing status (if applicable—see below).',
        'Any included accessories (see below).',
        '',
        'The weight, length, width, height, circumference, volume, diameter, etc., were likely entered to calculate shipping. Therefore, if the item includes any packaging, that is what was measured, and the actual product may be smaller. If you have any questions, please contact us; we will get those exact measurements for you.',
        '',
        'While we sometimes use AI to assist with descriptions, it may not always be as accurate as you might expect from a robot. Use the provided details to verify the product\'s suitability for your needs. Let us know if you spot an error—we appreciate your input!',
        '',
        'Images',
        'In most cases, the pictures in the listing are of the actual item for sale. However, we may use representative images instead for bulk listings or new, unused items in original packaging. Pay close attention to the photos to identify any physical defects and to confirm what is (or is not) included.',
        '',
        'Accessories',
        'While we wish every previous owner included power cables, connectors, chargers, dongles, keyboards, mice, and other accessories, this is rarely the case. Unless specifically mentioned in the product description or visible in the product photos, accessories are not included.',
        '',
        'Testing',
        'Although we aim to test all items thoroughly, there are instances where we may lack the technical expertise for certain equipment. In other cases, testing may not be possible due to missing cables or connectors. If an item cannot be tested but appears to be in working condition, we will label it "as-is" to indicate that its functionality cannot be guaranteed. These items are priced significantly lower, leaving the testing up to you.',
        '',
        'Storage',
        'We take data security seriously. Unless we specify something different in the product description, computers do not include hard disk drives (HDDs) or solid-state drives (SSDs). Exceptions will be noted in the description. In those cases, we obtained the previous owner\'s assurances that they had removed all sensitive data or that we had reformatted the storage media ourselves.',
        '',
        'Pricing',
        'Please note that prices are subject to change.',
        '',
        'Shipping Times',
        'Estimated shipping times are provided as general guidelines and may vary. Orders are processed in the order received, Tuesday through Saturday, from 9:00 AM to 3:30 PM CST. Please remember that weather, carrier workloads, and holiday delivery schedules can affect delivery times.',
        '',
        '—'
      ].join('\n');

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

      var buildFinalScript = function (chatgptText) {
        var text = (chatgptText || '').trim();
        if (!text) {
          return 'Paste the ChatGPT output first.';
        }
        return [
          finalBoilerplate,
          '',
          text
        ].join('\n');
      };

      var loadAppState = function (sku) {
        return fetch('autosave.php?sku=' + encodeURIComponent(sku))
          .then(function (resp) {
            return resp.json().then(function (data) {
              if (!resp.ok) {
                throw new Error(data.message || 'Could not load saved state.');
              }
              return data && data.has_draft ? data.data : null;
            });
          })
          .catch(function () {
            return null;
          });
      };

      var renderSource = function (sku, item) {
        if (!sourceWrap) return;
        sourceWrap.innerHTML = '';
        var price = (item && (item.dispotech_price || item.ebay_price)) || '';
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
          ['Price', price],
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
          currentSkuNormalized = '';
            promptOutput.value = '';
            if (chatgptOutput) chatgptOutput.value = '';
            if (finalOutput) finalOutput.value = '';
            renderSource('', null);
            setSaveStatus('No SKU', 'subtle');
          return;
        }
        setStatus('Loading...', 'warning');
        currentSkuNormalized = normalized;
        promptOutput.value = '';
        if (chatgptOutput) chatgptOutput.value = '';
        if (finalOutput) finalOutput.value = '';
        renderSource(normalized, null);

        var itemPromise = fetch('copy_item.php?sku=' + encodeURIComponent(normalized))
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
              return null;
            }
            return payload.data;
          })
          .catch(function () {
            return null;
          });

        var statePromise = loadAppState(normalized);

        Promise.all([itemPromise, statePromise])
          .then(function (results) {
            var item = results[0];
            var state = results[1];

            if (item) {
              renderSource(normalized, item);
            }

            if (state) {
              if (state.prompt_output) {
                promptOutput.value = state.prompt_output;
              } else if (item) {
                promptOutput.value = buildPrompt(normalized, item);
              }
              if (state.chatgpt_output && chatgptOutput) {
                chatgptOutput.value = state.chatgpt_output;
              }
              if (state.final_output && finalOutput) {
                finalOutput.value = state.final_output;
              } else if (chatgptOutput && chatgptOutput.value.trim()) {
                finalOutput.value = buildFinalScript(chatgptOutput.value);
              }
              setStatus('Saved state loaded', 'subtle');
              setSaveStatus('Loaded', 'success');
              return;
            }

            if (item) {
              promptOutput.value = buildPrompt(normalized, item);
              if (chatgptOutput && chatgptOutput.value.trim()) {
                finalOutput.value = buildFinalScript(chatgptOutput.value);
              }
              setStatus('Prompt ready', 'subtle');
              setSaveStatus('Ready', 'subtle');
              return;
            }

            promptOutput.value = '';
            setStatus('No record found for that SKU.', 'warning');
            setSaveStatus('No cache', 'subtle');
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
          currentSkuNormalized = '';
          promptOutput.value = '';
          if (chatgptOutput) chatgptOutput.value = '';
          if (finalOutput) finalOutput.value = '';
          renderSource('', null);
          setStatus('Ready', 'subtle');
          setSaveStatus('No SKU', 'subtle');
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

      if (buildFinalBtn && chatgptOutput && finalOutput) {
        buildFinalBtn.addEventListener('click', function () {
          finalOutput.value = buildFinalScript(chatgptOutput.value);
          if (window.updateState) {
            window.updateState('final_output', finalOutput.value);
          }
          setStatus('Final script ready', 'subtle');
        });
      }

      if (clearFinalBtn && chatgptOutput && finalOutput) {
        clearFinalBtn.addEventListener('click', function () {
          chatgptOutput.value = '';
          finalOutput.value = '';
          if (window.updateState) {
            window.updateState('chatgpt_output', '');
            window.updateState('final_output', '');
          }
          setStatus('Ready', 'subtle');
          if (window.forceSync) window.forceSync();
          chatgptOutput.focus();
        });
      }

      if (saveAllBtn) {
        saveAllBtn.addEventListener('click', function () {
          var skuVal = currentSkuNormalized || (skuInput ? skuInput.value.trim().toUpperCase() : '');
          if (!skuVal) {
            setStatus('Load a SKU first', 'warning');
            return;
          }
          if (window.forceSync) window.forceSync();
          setStatus('All scripts saved', 'subtle');
        });
      }

      if (copyFinalBtn && finalOutput) {
        copyFinalBtn.addEventListener('click', function () {
          var text = finalOutput.value.trim();
          if (!text) {
            setStatus('Build the final script first', 'warning');
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
              .then(function () { copied = true; setStatus('Final script copied', 'subtle'); })
              .catch(function () { fallbackCopy(); setStatus(copied ? 'Final script copied' : 'Copy failed', copied ? 'subtle' : 'warning'); });
          } else {
            fallbackCopy();
            setStatus(copied ? 'Final script copied' : 'Copy failed', copied ? 'subtle' : 'warning');
          }
        });
      }

      if (skuInput && skuInput.value.trim()) {
        loadSku(skuInput.value);
      } else if (initialItem) {
        currentSkuNormalized = skuInput ? skuInput.value.trim().toUpperCase() : '';
        renderSource(currentSkuNormalized, initialItem);
        promptOutput.value = buildPrompt(currentSkuNormalized, initialItem);
        setStatus('Prompt ready', 'subtle');
        setSaveStatus('Ready', 'subtle');
      } else {
        promptOutput.value = [
          'Enter a SKU and click Generate prompt.',
          '',
          'This page will assemble a ChatGPT-ready prompt from the latest inventory record.'
        ].join('\n');
        setStatus('Ready', 'subtle');
        setSaveStatus('No SKU', 'subtle');
      }

      // State persistence handled by app.js via window.appState -> autosave.php
    })();
  </script>
  </div>
</body>
</html>
