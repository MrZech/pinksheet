<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkMaintenance();
ensureStorageWritable();

const ARCHIVE_PAGE_SIZE = 50;

$currentPage = 'archive';

function resolveArchiveDbPath(): string
{
    $preferred = __DIR__ . '/data/archive.sqlite';
    if (is_file($preferred)) {
        return $preferred;
    }
    return __DIR__ . '/data/intake.sqlite';
}

function ensureArchiveItemsTable(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS archive_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    sku TEXT,
    sku_normalized TEXT,
    title TEXT,
    status TEXT,
    sold_at TEXT,
    sold_price REAL,
    purchase_price REAL,
    source TEXT,
    buyer TEXT,
    notes TEXT,
    legacy_source TEXT,
    legacy_table TEXT,
    legacy_id TEXT,
    legacy_payload TEXT NOT NULL
);
SQL);
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_archive_items_sku_normalized ON archive_items (sku_normalized)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_archive_items_status_sold_at ON archive_items (status, sold_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_archive_items_legacy_source ON archive_items (legacy_source, legacy_table)");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_archive_items_legacy_identity ON archive_items (legacy_source, legacy_table, legacy_id)");
}

if (!is_dir(__DIR__ . '/data')) {
    mkdir(__DIR__ . '/data', 0777, true);
}

$archiveDbPath = resolveArchiveDbPath();
$pdo = pdoConnect($archiveDbPath);
ensureArchiveItemsTable($pdo);

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$sourceFilter = trim((string)($_GET['source'] ?? ''));
$legacySourceFilter = trim((string)($_GET['legacy_source'] ?? ''));
$soldFrom = trim((string)($_GET['sold_from'] ?? ''));
$soldTo = trim((string)($_GET['sold_to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = ARCHIVE_PAGE_SIZE;
$offset = ($page - 1) * $limit;

$where = [];
$params = [];

if ($q !== '') {
    $prefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], strtolower($q)) . '%';
    $skuPrefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], strtoupper($q)) . '%';
    $any = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], strtolower($q)) . '%';
    $where[] = '(sku_normalized LIKE :sku_prefix OR lower(COALESCE(sku, \'\')) LIKE :q_prefix OR lower(COALESCE(title, \'\')) LIKE :q_prefix OR lower(COALESCE(status, \'\')) LIKE :q_prefix OR lower(COALESCE(source, \'\')) LIKE :q_prefix OR lower(COALESCE(buyer, \'\')) LIKE :q_prefix OR lower(COALESCE(legacy_source, \'\')) LIKE :q_prefix OR lower(COALESCE(legacy_table, \'\')) LIKE :q_prefix OR lower(COALESCE(legacy_id, \'\')) LIKE :q_prefix OR lower(COALESCE(notes, \'\')) LIKE :q_any)';
    $params[':sku_prefix'] = $skuPrefix;
    $params[':q_prefix'] = $prefix;
    $params[':q_any'] = $any;
}
if ($statusFilter !== '') {
    $where[] = 'lower(COALESCE(status, \'\')) = lower(:status)';
    $params[':status'] = $statusFilter;
}
if ($sourceFilter !== '') {
    $where[] = 'lower(COALESCE(source, \'\')) = lower(:source)';
    $params[':source'] = $sourceFilter;
}
if ($legacySourceFilter !== '') {
    $where[] = 'lower(COALESCE(legacy_source, \'\')) = lower(:legacy_source)';
    $params[':legacy_source'] = $legacySourceFilter;
}
if ($soldFrom !== '') {
    $where[] = 'date(COALESCE(sold_at, created_at, updated_at)) >= date(:sold_from)';
    $params[':sold_from'] = $soldFrom;
}
if ($soldTo !== '') {
    $where[] = 'date(COALESCE(sold_at, created_at, updated_at)) <= date(:sold_to)';
    $params[':sold_to'] = $soldTo;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM archive_items ' . $whereSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

$sql = '
    SELECT id, created_at, updated_at, sku, sku_normalized, title, status,
           sold_at, sold_price, purchase_price, source, buyer, notes,
           legacy_source, legacy_table, legacy_id
    FROM archive_items
    ' . $whereSql . '
    ORDER BY COALESCE(sold_at, updated_at, created_at) DESC, id DESC
    LIMIT :limit OFFSET :offset
';
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$distinctFilters = [];
if ($q === '' && $statusFilter === '' && $sourceFilter === '' && $legacySourceFilter === '' && $soldFrom === '' && $soldTo === '') {
    $distinctFilters['status'] = $pdo->query("SELECT DISTINCT status FROM archive_items WHERE status IS NOT NULL AND status != '' ORDER BY status")->fetchAll(PDO::FETCH_COLUMN);
    $distinctFilters['legacy_source'] = $pdo->query("SELECT DISTINCT legacy_source FROM archive_items WHERE legacy_source IS NOT NULL AND legacy_source != '' ORDER BY legacy_source")->fetchAll(PDO::FETCH_COLUMN);
    $distinctFilters['source'] = $pdo->query("SELECT DISTINCT source FROM archive_items WHERE source IS NOT NULL AND source != '' ORDER BY source")->fetchAll(PDO::FETCH_COLUMN);
}
$statusOptions = $distinctFilters['status'] ?? ['Sold', 'SOLD', 'Archived', 'Closed', 'Listed', 'Open'];
$legacySources = $distinctFilters['legacy_source'] ?? [];
$sources = $distinctFilters['source'] ?? [];
$rangeStart = $totalRows > 0 ? ($offset + 1) : 0;
$rangeEnd = min($offset + $limit, $totalRows);
$queryLabel = $q !== '' ? ' results for "' . $q . '"' : ' archive items';

function buildArchiveUrl(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    unset($query['partial']);
    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }
    return 'archive.php' . ($query ? '?' . http_build_query($query) : '');
}

$csrfToken = csrf_token();
session_write_close();

$isPartial = ($_GET['partial'] ?? '') === '1';
if (!$isPartial):
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Archive - Dispo.Tech</title>
  <link rel="stylesheet" href="assets/style.css?v=<?= getAssetVersion() ?>">
  <script src="assets/menu.js?v=<?= getAssetVersion() ?>" defer></script>
  <link rel="stylesheet" media="print" href="assets/print.css?v=<?= getAssetVersion() ?>">
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg?v=2">
  <script>window.CSRF_TOKEN = <?= json_encode($csrfToken) ?>;</script>
  <script src="assets/theme.js?v=<?= getAssetVersion() ?>" defer></script>
  <script src="assets/app.js?v=<?= getAssetVersion() ?>" defer></script>
  <script src="assets/nav.js?v=<?= getAssetVersion() ?>" defer></script>
</head>
<body class="archive-page">
  <div class="layout-wrapper">
  <div class="app-menu">
      <button type="button" class="menu-toggle" aria-expanded="false" aria-controls="global-menu" id="menu-toggle">
        <span class="hamburger" aria-hidden="true"></span>
        <span class="menu-label">Menu</span>
      </button>
      <nav class="menu-panel" id="global-menu" aria-hidden="true">
        <ul class="menu-links">
          <li><a class="menu-link" href="home.php">Dashboard</a></li>
          <li><a class="menu-link" href="intake.php?clear_draft=1" data-new-intake>Intake</a></li>
          <li><a class="menu-link" href="kanban.php">Status Board</a></li>
          <li><a class="menu-link" href="lookup.php">SKU Lookup</a></li>
          <li><a class="menu-link is-active" href="archive.php">Archive</a></li>
          <li><a class="menu-link" href="prompt_builder.php">Script Builder</a></li>
        </ul>
      </nav>
    </div>
  <div id="content-area">
<?php endif; /* end outer shell */ ?>
  <main class="page">
    <section class="sheet archive-sheet">
      <header class="sheet-header">
        <div class="updated">Dispo.Tech Archive</div>
        <div class="sheet-header-right">
          <span class="autosave-status" id="autosave-status" hidden>Autosave ready</span>
          <a class="button-link new-intake-cta" href="intake.php?clear_draft=1" data-new-intake>New Intake</a>
          <button type="button" class="theme-toggle" id="theme-toggle">Dark mode</button>
        </div>
      </header>

      <h1>Archive</h1>
      <nav class="breadcrumbs" aria-label="Breadcrumb">
        <a href="home.php">Dashboard</a>
        <span>Archive</span>
      </nav>
      <p class="lead">Search old records here. This page is read-only and intended for legacy purchase history, sold inventory, and other historical references.</p>
      <section class="section archive-summary archive-pill-row">
        <div class="badge archive-db-path">Archive DB: <?php echo h($archiveDbPath); ?></div>
        <div class="badge">Rows: <?php echo h((string)$totalRows); ?></div>
      </section>

      <section class="section archive-summary archive-pill-row">
        <div class="badge">Showing <?php echo h((string)$rangeStart); ?>-<?php echo h((string)$rangeEnd); ?> of <?php echo h((string)$totalRows); ?><?php echo h($queryLabel); ?></div>
        <?php if ($statusFilter !== ''): ?><div class="badge subtle">Status: <?php echo h($statusFilter); ?></div><?php endif; ?>
        <?php if ($sourceFilter !== ''): ?><div class="badge subtle">Source: <?php echo h($sourceFilter); ?></div><?php endif; ?>
        <?php if ($legacySourceFilter !== ''): ?><div class="badge subtle">Legacy source: <?php echo h($legacySourceFilter); ?></div><?php endif; ?>
      </section>

      <section class="section">
        <h2>Search archive</h2>
        <form class="archive-filters" method="get" action="archive.php">
          <div class="row">
            <label>Search
              <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="SKU, title, notes, buyer, legacy id">
            </label>
            <label>Status
              <select name="status">
                <option value="">Any status</option>
                <?php foreach ($statusOptions as $opt): ?>
                  <option value="<?php echo h($opt); ?>" <?php echo $statusFilter === $opt ? 'selected' : ''; ?>><?php echo h($opt); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Source
              <select name="source">
                <option value="">Any source</option>
                <?php foreach ($sources as $opt): ?>
                  <option value="<?php echo h((string)$opt); ?>" <?php echo $sourceFilter === $opt ? 'selected' : ''; ?>><?php echo h((string)$opt); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Legacy source
              <select name="legacy_source">
                <option value="">Any legacy source</option>
                <?php foreach ($legacySources as $opt): ?>
                  <option value="<?php echo h((string)$opt); ?>" <?php echo $legacySourceFilter === $opt ? 'selected' : ''; ?>><?php echo h((string)$opt); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <div class="row">
            <label>Sold from
              <input type="date" name="sold_from" value="<?php echo h($soldFrom); ?>">
            </label>
            <label>Sold to
              <input type="date" name="sold_to" value="<?php echo h($soldTo); ?>">
            </label>
          </div>
          <div class="actions">
            <button type="submit">Search</button>
            <a class="button-link" href="archive.php">Reset</a>
          </div>
        </form>
      </section>

      <section class="section">
        <h2>Archive items</h2>
        <div class="table-wrap">
          <table class="archive-table">
            <thead>
              <tr>
                <th>SKU</th>
                <th>Title</th>
                <th>Status</th>
                <th>Sold / Date</th>
                <th>Sale Price</th>
                <th>Source</th>
                <th>Legacy</th>
                <th>Notes / Raw</th>
                <th>Updated</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$rows): ?>
                <tr>
                  <td colspan="9">No archive records found.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($rows as $row): ?>
                  <?php
                    $legacyParts = array_values(array_filter([
                        trim((string)($row['legacy_source'] ?? '')),
                        trim((string)($row['legacy_table'] ?? '')),
                        trim((string)($row['legacy_id'] ?? '')),
                    ]));
                    $legacyLabel = $legacyParts ? implode(' / ', $legacyParts) : '—';
                    $rawPayload = (string)($row['legacy_payload'] ?? '');
                    $payloadDecoded = json_decode($rawPayload, true);
                    $payloadPretty = is_array($payloadDecoded)
                        ? json_encode($payloadDecoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                        : $rawPayload;
                    $title = trim((string)($row['title'] ?? ''));
                    if ($title === '') {
                        $title = trim((string)($row['notes'] ?? ''));
                    }
                  ?>
                  <tr>
                    <td><?php echo h((string)($row['sku'] ?? '')); ?></td>
                    <td><?php echo h($title !== '' ? $title : '—'); ?></td>
                    <td><?php echo h((string)($row['status'] ?? '')); ?></td>
                    <td><?php echo h((string)($row['sold_at'] ?? '')); ?></td>
                    <td><?php echo h((string)($row['sold_price'] ?? '')); ?></td>
                    <td><?php echo h((string)($row['source'] ?? '')); ?></td>
                    <td><?php echo h($legacyLabel); ?></td>
                    <td class="archive-notes">
                      <div><?php echo h((string)($row['notes'] ?? '')); ?></div>
                      <details class="archive-raw">
                        <summary>Raw legacy data</summary>
                        <pre><?php echo h($payloadPretty !== '' ? $payloadPretty : '{}'); ?></pre>
                      </details>
                    </td>
                    <td><?php echo h((string)($row['updated_at'] ?? '')); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="archive-pager">
          <a class="button-link <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo h(buildArchiveUrl(['page' => max(1, $page - 1)])); ?>">Previous</a>
          <span class="hint">Page <?php echo h((string)$page); ?> of <?php echo h((string)$totalPages); ?></span>
          <a class="button-link <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="<?php echo h(buildArchiveUrl(['page' => min($totalPages, $page + 1)])); ?>">Next</a>
        </div>
      </section>
    </section>
  </main>
<?php if (!$isPartial): ?>
  </div> <!-- /content-area -->
  </div> <!-- /layout-wrapper -->
</body>
</html>
<?php endif; ?>
