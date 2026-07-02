(function () {
  'use strict';

  var menuToggle = document.getElementById('menu-toggle');
  var menuPanel  = document.getElementById('global-menu');
  var menuLinks  = menuPanel ? menuPanel.querySelector('.menu-links') : null;

  /* ── On desktop the sidebar is always visible ──────── */
  if (menuToggle) {
    menuToggle.setAttribute('aria-expanded', 'true');
    menuToggle.setAttribute('aria-hidden', 'true');
  }
  if (menuPanel) {
    menuPanel.classList.add('is-open');
    menuPanel.setAttribute('aria-hidden', 'false');
  }

  /* ── Assign data-icon attributes to nav links ─────── */
  /* These show as single-character icons when sidebar is collapsed */
  var ICONS = {
    'home.php':          '🏠',
    'intake.php':        '📋',
    'kanban.php':        '📌',
    'lookup.php':        '🔍',
    'archive.php':       '📦',
    'prompt_builder.php':'✍️',
  };
  if (menuLinks) {
    menuLinks.querySelectorAll('a').forEach(function (a) {
      var href = (a.getAttribute('href') || '').split('?')[0].split('/').pop();
      a.setAttribute('data-icon', ICONS[href] || '•');
    });
  }

  /* ── Collapse toggle ──────────────────────────────── */
  var STORAGE_KEY = 'sidebarCollapsed';
  var isDesktop   = window.matchMedia('(min-width: 1024px)');

  function applyCollapsed(collapsed) {
    document.body.classList.toggle('sidebar-collapsed', collapsed);
    var btn = document.getElementById('sidebar-collapse-btn');
    if (btn) {
      btn.setAttribute('aria-pressed', String(collapsed));
      btn.title = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
    }
    try { localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0'); } catch (e) {}
  }

  function buildCollapseButton() {
    if (!menuPanel || document.getElementById('sidebar-collapse-btn')) return;

    var btn = document.createElement('button');
    btn.id            = 'sidebar-collapse-btn';
    btn.type          = 'button';
    btn.className     = 'sidebar-collapse-btn';
    btn.setAttribute('aria-pressed', 'false');
    btn.title         = 'Collapse sidebar';

    var icon = document.createElement('span');
    icon.className  = 'collapse-icon';
    icon.textContent = '◀';
    icon.setAttribute('aria-hidden', 'true');

    var label = document.createElement('span');
    label.className  = 'collapse-label';
    label.textContent = 'Collapse';

    btn.appendChild(icon);
    btn.appendChild(label);
    menuPanel.appendChild(btn);

    btn.addEventListener('click', function () {
      var nowCollapsed = !document.body.classList.contains('sidebar-collapsed');
      applyCollapsed(nowCollapsed);
    });
  }

  /* Only build and apply on desktop */
  function init() {
    if (!isDesktop.matches) return;
    buildCollapseButton();
    var stored = null;
    try { stored = localStorage.getItem(STORAGE_KEY); } catch (e) {}
    applyCollapsed(stored === '1');
  }

  /* Re-evaluate when viewport crosses the 1024px boundary */
  isDesktop.addEventListener('change', function () {
    if (isDesktop.matches) {
      buildCollapseButton();
    } else {
      /* On mobile: remove collapsed class so layout resets */
      document.body.classList.remove('sidebar-collapsed');
    }
  });

  /* ── Clear intake draft on New Intake clicks ─────── */
  document.querySelectorAll('[data-new-intake]').forEach(function (link) {
    link.addEventListener('click', function () {
      try { localStorage.removeItem('intakeDraftV1'); } catch (e) {}
    });
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
