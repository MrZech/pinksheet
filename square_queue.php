<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/square_sync.php';
require_once __DIR__ . '/square_sync_queue.php';

checkMaintenance();
ensureStorageWritable();

$pdo = pdoConnect(__DIR__ . '/data/intake.sqlite');
squareSyncEnsureSchema($pdo);

$filter = trim($_GET['filter'] ?? '');
$allowedFilters = ['queued', 'processing', 'completed', 'failed', 'retrying', 'dead_letter'];
if ($filter !== '' && !in_array($filter, $allowedFilters, true)) {
    $filter = '';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$where = $filter !== '' ? 'WHERE status = :filter' : '';
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM sync_queue ' . $where);
if ($filter !== '') {
    $countStmt->execute(['filter' => $filter]);
} else {
    $countStmt->execute();
}
$totalJobs = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalJobs / $perPage));

$jobsStmt = $pdo->prepare('SELECT * FROM sync_queue ' . $where . ' ORDER BY priority DESC, created_at ASC LIMIT :lim OFFSET :off');
$jobsParams = ['lim' => $perPage, 'off' => $offset];
if ($filter !== '') {
    $jobsParams['filter'] = $filter;
}
$jobsStmt->execute($jobsParams);
$jobs = $jobsStmt->fetchAll(PDO::FETCH_ASSOC);

$stats = squareQueueStats($pdo);

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
  <title>Square Sync Queue — Dispo.Tech</title>
  <link rel="stylesheet" href="assets/style.css?v=<?= getAssetVersion() ?>">
  <script src="assets/menu.js?v=<?= getAssetVersion() ?>" defer></script>
  <link rel="stylesheet" media="print" href="assets/print.css?v=<?= getAssetVersion() ?>">
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <script>window.CSRF_TOKEN = <?= json_encode($csrfToken) ?>;</script>
  <script src="assets/theme.js?v=<?= getAssetVersion() ?>" defer></script>
  <script src="assets/app.js?v=<?= getAssetVersion() ?>" defer></script>
  <script src="assets/nav.js?v=<?= getAssetVersion() ?>" defer></script>
  <script src="assets/command-palette.js?v=<?= getAssetVersion() ?>" defer></script>
</head>
<body class="queue-page">
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
        <li><a class="menu-link" href="archive.php">Archive</a></li>
        <li><a class="menu-link" href="prompt_builder.php">Script Builder</a></li>
      </ul>
    </nav>
  </div>
  <div id="content-area">
<?php endif; ?>
  <main class="page">
    <section class="sheet">
      <header class="sheet-header">
        <div class="updated">Square Sync Queue</div>
        <div class="sheet-header-right">
          <span class="badge subtle"><?= $totalJobs ?> total</span>
          <a class="button-link" href="home.php">Dashboard</a>
        </div>
      </header>
      <nav class="breadcrumbs" aria-label="Breadcrumb">
        <a href="home.php">Dashboard</a>
        <span>Sync Queue</span>
      </nav>
      <h1>Square Sync Queue</h1>
      <p class="lead">Monitor and manage pending, failed, and completed sync operations.</p>

      <div class="dashboard-grid" style="margin-bottom: 16px;">
        <div class="dash-card dash-card--metric">
          <p class="dash-label">Queued</p>
          <p class="dash-value" style="color: var(--palette-steel-blue);"><?= $stats['queued'] ?? 0 ?></p>
        </div>
        <div class="dash-card dash-card--metric">
          <p class="dash-label">Retrying</p>
          <p class="dash-value" style="color: #F59E0B;"><?= $stats['retrying'] ?? 0 ?></p>
        </div>
        <div class="dash-card dash-card--metric">
          <p class="dash-label">Failed</p>
          <p class="dash-value" style="color: #EF4444;"><?= $stats['failed'] ?? 0 ?></p>
        </div>
        <div class="dash-card dash-card--metric">
          <p class="dash-label">Dead Letter</p>
          <p class="dash-value" style="color: #DC2626;"><?= $stats['dead_letter'] ?? 0 ?></p>
        </div>
      </div>

      <div class="filter-chips" style="margin-bottom: 12px;">
        <a class="button-link ghost" href="square_queue.php">All</a>
        <?php foreach ($allowedFilters as $f): ?>
          <a class="button-link ghost<?= $filter === $f ? ' is-active' : '' ?>" href="square_queue.php?filter=<?= $f ?>"><?= ucfirst(str_replace('_', ' ', $f)) ?></a>
        <?php endforeach; ?>
        <span style="margin-left: auto;">
          <button type="button" class="button-link ghost" id="reset-dead-letters" style="color: #EF4444;">Reset dead letters</button>
        </span>
      </div>

      <?php if (empty($jobs)): ?>
        <p class="hint">No queue items match the current filter.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="lookup-table">
            <thead>
              <tr>
                <th>SKU</th>
                <th>Operation</th>
                <th>Status</th>
                <th>Retries</th>
                <th>Last Error</th>
                <th>Next Retry</th>
                <th>Created</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($jobs as $job): ?>
                <tr>
                  <td><strong><?= htmlspecialchars($job['sku_normalized'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong></td>
                  <td><code><?= htmlspecialchars($job['operation'] ?? '', ENT_QUOTES, 'UTF-8') ?></code></td>
                  <td>
                    <span class="status-chip" data-status="<?= htmlspecialchars($job['status'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                      <?= htmlspecialchars($job['status'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                    </span>
                  </td>
                  <td><?= (int)($job['retry_count'] ?? 0) ?>/<?= (int)($job['max_retries'] ?? 10) ?></td>
                  <td style="max-width: 300px; overflow: hidden; text-overflow: ellipsis;">
                    <?= $job['last_error'] ? htmlspecialchars($job['last_error'], ENT_QUOTES, 'UTF-8') : '—' ?>
                  </td>
                  <td><?= htmlspecialchars($job['next_retry_at'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars($job['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if ($totalPages > 1): ?>
          <div class="pagination-bar">
            <?php if ($page > 1): ?>
              <a class="button-link ghost" href="square_queue.php?page=<?= $page - 1 ?>&filter=<?= $filter ?>">← Prev</a>
            <?php else: ?>
              <span class="button-link ghost" disabled>← Prev</span>
            <?php endif; ?>
            <span class="pagination-info">Page <?= $page ?> of <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?>
              <a class="button-link ghost" href="square_queue.php?page=<?= $page + 1 ?>&filter=<?= $filter ?>">Next →</a>
            <?php else: ?>
              <span class="button-link ghost" disabled>Next →</span>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  </main>
  <script>
    (function () {
      var resetBtn = document.getElementById('reset-dead-letters');
      if (resetBtn) {
        resetBtn.addEventListener('click', function () {
          if (!confirm('Reset all dead letter queue items to queued?')) return;
          fetch('square_queue.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=reset_dead_letters&csrf_token=' + encodeURIComponent(window.CSRF_TOKEN)
          })
            .then(function (r) { return r.json(); })
            .then(function (data) {
              if (data.ok) {
                location.reload();
              } else {
                alert('Failed: ' + (data.error || 'unknown'));
              }
            })
            .catch(function () { alert('Request failed.'); });
        });
      }
    })();
  </script>
<?php if (!$isPartial): ?>
  </div>
  </div>
</body>
</html>
<?php endif; ?>
<?php
/* ── Handle POST actions ────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['partial'] ?? '') !== '1') {
    header('Content-Type: application/json; charset=utf-8');
    require_csrf();

    $action = trim($_POST['action'] ?? '');
    switch ($action) {
        case 'reset_dead_letters':
            squareQueueResetDeadLetter($pdo);
            echo json_encode(['ok' => true]);
            break;
        default:
            echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    }
    exit;
}
