(function () {
  'use strict';

  // ── State ──────────────────────────────────────────────────────────
  var state = {
    open: false,
    results: [],
    selectedIndex: -1,
    query: '',
    recentSearches: [],
    abortController: null,
    debounceTimer: null,
    scanTimer: null,
    scanInput: '',
    lastKeyTime: 0,
    recentStorageKey: 'cpRecentSearches',
  };

  // ── DOM References (created lazily) ────────────────────────────────
  var overlay = null;
  var panel = null;
  var input = null;
  var resultsEl = null;
  var emptyEl = null;

  // ── Helpers ────────────────────────────────────────────────────────
  function qs(sel, ctx) { return (ctx || document).querySelector(sel); }

  function esc(str) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(str));
    return d.innerHTML;
  }

  function relativeTime(dateStr) {
    var t = Date.parse((dateStr || '').replace(' ', 'T'));
    if (isNaN(t)) return dateStr || '';
    var diff = Date.now() - t;
    var mins = Math.round(diff / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return mins + 'm ago';
    var hrs = Math.round(mins / 60);
    if (hrs < 24) return hrs + 'h ago';
    var days = Math.round(hrs / 24);
    return days + 'd ago';
  }

  function statusClass(s) {
    var map = {
      'intake': 'intake',
      'ebay draft': 'ebay-draft',
      'ebay review': 'ebay-review',
      'ebay listed': 'ebay-listed',
      'dispo tech store': 'store',
      'sold': 'sold'
    };
    return map[(s || '').toLowerCase()] || 'intake';
  }

  // ── Recent searches (localStorage) ─────────────────────────────────
  function loadRecent() {
    try {
      var data = JSON.parse(localStorage.getItem(state.recentStorageKey) || '[]');
      state.recentSearches = Array.isArray(data) ? data.slice(0, 20) : [];
    } catch (e) {
      state.recentSearches = [];
    }
  }

  function saveRecent(query) {
    if (!query || query.trim().length < 2) return;
    try {
      var list = [query.trim()].concat(state.recentSearches.filter(function (s) { return s !== query.trim(); }));
      state.recentSearches = list.slice(0, 20);
      localStorage.setItem(state.recentStorageKey, JSON.stringify(state.recentSearches));
    } catch (e) {}
  }

  // ── Build DOM ──────────────────────────────────────────────────────
  function buildPalette() {
    if (overlay) return;

    overlay = document.createElement('div');
    overlay.id = 'cp-overlay';
    overlay.className = 'cp-overlay';
    overlay.setAttribute('aria-hidden', 'true');
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-label', 'Command center');

    panel = document.createElement('div');
    panel.className = 'cp-panel';

    var header = document.createElement('div');
    header.className = 'cp-header';

    var searchIcon = document.createElement('span');
    searchIcon.className = 'cp-search-icon';
    searchIcon.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';

    input = document.createElement('input');
    input.id = 'cp-input';
    input.className = 'cp-input';
    input.type = 'text';
    input.placeholder = 'Search devices by SKU, serial, model, or any field…';
    input.setAttribute('autocomplete', 'off');
    input.setAttribute('spellcheck', 'false');
    input.setAttribute('aria-label', 'Search inventory');

    var shortcutHint = document.createElement('kbd');
    shortcutHint.className = 'cp-shortcut-hint';
    shortcutHint.textContent = 'ESC';

    header.appendChild(searchIcon);
    header.appendChild(input);
    header.appendChild(shortcutHint);

    resultsEl = document.createElement('div');
    resultsEl.className = 'cp-results';
    resultsEl.setAttribute('role', 'listbox');
    resultsEl.setAttribute('aria-label', 'Search results');

    emptyEl = document.createElement('div');
    emptyEl.className = 'cp-empty';
    emptyEl.innerHTML = '<div class="cp-empty-icon"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></div><div class="cp-empty-text">Type to search devices</div><div class="cp-empty-hint">Search by SKU, serial number, model, or any field</div>';

    panel.appendChild(header);
    panel.appendChild(resultsEl);
    panel.appendChild(emptyEl);
    overlay.appendChild(panel);
    document.body.appendChild(overlay);

    // Events
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) close();
    });

    input.addEventListener('input', onInput);
    input.addEventListener('keydown', onKeyDown);
  }

  // ── Open / Close ────────────────────────────────────────────────────
  function open() {
    if (state.open) {
      input && input.focus();
      input && input.select();
      return;
    }
    buildPalette();
    state.open = true;
    state.selectedIndex = -1;
    state.query = '';
    overlay.removeAttribute('aria-hidden');
    overlay.classList.add('is-open');
    input.value = '';
    resultsEl.innerHTML = '';
    emptyEl.style.display = '';
    showRecent();
    setTimeout(function () { input.focus(); }, 50);
  }

  function close() {
    if (!state.open) return;
    state.open = false;
    if (state.abortController) { state.abortController.abort(); state.abortController = null; }
    overlay.setAttribute('aria-hidden', 'true');
    overlay.classList.remove('is-open');
    if (document.activeElement && document.activeElement === input) {
      document.activeElement.blur();
    }
  }

  // ── Recent items (empty state) ─────────────────────────────────────
  function showRecent() {
    emptyEl.style.display = 'none';
    resultsEl.innerHTML = '<div class="cp-section-label">Recent</div>';
    fetch('command_palette.php?recent=1')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var items = data.results || [];
        if (items.length) {
          items.forEach(function (item) { resultsEl.appendChild(buildCard(item)); });
        } else {
          resultsEl.innerHTML = '';
          emptyEl.style.display = '';
        }
      })
      .catch(function () {
        resultsEl.innerHTML = '';
        emptyEl.style.display = '';
      });
  }

  // ── Search ──────────────────────────────────────────────────────────
  function onInput() {
    var val = input.value.trim();
    state.query = val;
    state.selectedIndex = -1;

    if (state.debounceTimer) { clearTimeout(state.debounceTimer); state.debounceTimer = null; }
    state.debounceTimer = setTimeout(function () { executeSearch(val); }, 75);
  }

  function executeSearch(query) {
    if (state.abortController) { state.abortController.abort(); state.abortController = null; }

    if (query.length < 1) {
      resultsEl.innerHTML = '';
      emptyEl.style.display = '';
      showRecent();
      return;
    }

    emptyEl.style.display = 'none';
    resultsEl.innerHTML = '<div class="cp-loading"><div class="cp-spinner"></div></div>';

    state.abortController = new AbortController();
    fetch('command_palette.php?q=' + encodeURIComponent(query), {
      signal: state.abortController.signal
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var items = data.results || [];
        renderResults(items);
        if (items.length > 0 && items.length <= 3) {
          saveRecent(query);
        }
      })
      .catch(function (err) {
        if (err && err.name === 'AbortError') return;
        resultsEl.innerHTML = '<div class="cp-error">Search failed. Try again.</div>';
      });
  }

  function renderResults(items) {
    resultsEl.innerHTML = '';
    if (!items.length) {
      emptyEl.style.display = '';
      return;
    }
    emptyEl.style.display = 'none';
    items.forEach(function (item, i) {
      var card = buildCard(item);
      card.setAttribute('data-index', String(i));
      resultsEl.appendChild(card);
    });
    state.selectedIndex = -1;
  }

  // ── Build Result Card ──────────────────────────────────────────────
  function buildCard(item) {
    var card = document.createElement('div');
    card.className = 'cp-card';
    card.setAttribute('role', 'option');
    card.setAttribute('data-id', String(item.id));
    card.setAttribute('data-sku', item.sku || '');
    card.tabIndex = -1;

    var iconDiv = document.createElement('div');
    iconDiv.className = 'cp-card-icon';
    iconDiv.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>';

    var bodyDiv = document.createElement('div');
    bodyDiv.className = 'cp-card-body';

    var top = document.createElement('div');
    top.className = 'cp-card-top';

    var sku = document.createElement('span');
    sku.className = 'cp-card-sku';
    sku.textContent = item.sku || '—';

    var model = document.createElement('span');
    model.className = 'cp-card-model';
    model.textContent = item.brand_model || item.what_is_it || '—';

    top.appendChild(sku);
    top.appendChild(model);

    var meta = document.createElement('div');
    meta.className = 'cp-card-meta';

    if (item.serial_number) {
      var serial = document.createElement('span');
      serial.className = 'cp-card-serial';
      serial.textContent = 'SN: ' + item.serial_number;
      meta.appendChild(serial);
    }

    if (item.cpu || item.ram || item.ssd_gb) {
      var specs = document.createElement('span');
      specs.className = 'cp-card-specs';
      var parts = [];
      if (item.cpu) parts.push(item.cpu);
      if (item.ram) parts.push(item.ram);
      if (item.ssd_gb) parts.push(item.ssd_gb);
      specs.textContent = parts.join(' · ');
      meta.appendChild(specs);
    }

    bodyDiv.appendChild(top);
    bodyDiv.appendChild(meta);

    var right = document.createElement('div');
    right.className = 'cp-card-right';

    if (item.status) {
      var badge = document.createElement('span');
      badge.className = 'cp-status-badge cp-status-' + statusClass(item.status);
      badge.textContent = item.status;
      right.appendChild(badge);
    }

    if (item.where_it_goes) {
      var loc = document.createElement('span');
      loc.className = 'cp-card-location';
      loc.textContent = item.where_it_goes;
      right.appendChild(loc);
    }

    var time = document.createElement('span');
    time.className = 'cp-card-time';
    time.textContent = relativeTime(item.updated_at);
    right.appendChild(time);

    card.appendChild(iconDiv);
    card.appendChild(bodyDiv);
    card.appendChild(right);

    card.addEventListener('click', function () { selectItem(item); });
    card.addEventListener('mouseenter', function () {
      var prev = qs('.cp-card.is-selected', resultsEl);
      if (prev) prev.classList.remove('is-selected');
      card.classList.add('is-selected');
      var idx = parseInt(card.getAttribute('data-index'), 10);
      if (!isNaN(idx)) state.selectedIndex = idx;
    });

    return card;
  }

  // ── Keyboard Navigation ────────────────────────────────────────────
  function onKeyDown(e) {
    var key = e.key;

    if (key === 'Escape') {
      e.preventDefault();
      close();
      return;
    }

    if (key === 'ArrowDown') {
      e.preventDefault();
      navigate(1);
      return;
    }

    if (key === 'ArrowUp') {
      e.preventDefault();
      navigate(-1);
      return;
    }

    if (key === 'Enter') {
      e.preventDefault();
      activateSelected();
      return;
    }

    // Barcode scanner detection: rapid keystrokes
    if (key.length === 1 || key === 'Enter') {
      var now = Date.now();
      if (key === 'Enter') {
        if (state.scanInput.length >= 4 && (now - state.lastKeyTime) < 80) {
          // Likely a barcode scan — use the accumulated input
          input.value = state.scanInput;
          state.query = state.scanInput;
          state.scanInput = '';
          executeSearch(state.query);
          // Auto-open first result if there's a match
          var checkAndOpen = function () {
            var firstCard = qs('.cp-card', resultsEl);
            if (firstCard) {
              firstCard.click();
            } else {
              // Wait for results and try again
              var observer = new MutationObserver(function () {
                var card = qs('.cp-card', resultsEl);
                if (card) {
                  card.click();
                  observer.disconnect();
                }
              });
              observer.observe(resultsEl, { childList: true, subtree: true });
              setTimeout(function () { observer.disconnect(); }, 2000);
            }
          };
          setTimeout(checkAndOpen, 150);
        }
        state.scanInput = '';
        state.lastKeyTime = now;
        return;
      }

      state.scanInput += key;
      state.lastKeyTime = now;

      // Reset scan buffer if typing pauses
      if (state.scanTimer) clearTimeout(state.scanTimer);
      state.scanTimer = setTimeout(function () { state.scanInput = ''; }, 200);

      return;
    }
  }

  function navigate(dir) {
    var cards = Array.prototype.slice.call(resultsEl.querySelectorAll('.cp-card'));
    if (!cards.length) return;

    if (state.selectedIndex < 0 || state.selectedIndex >= cards.length) {
      state.selectedIndex = dir > 0 ? 0 : cards.length - 1;
    } else {
      state.selectedIndex = Math.max(0, Math.min(cards.length - 1, state.selectedIndex + dir));
    }

    cards.forEach(function (c) { c.classList.remove('is-selected'); });
    cards[state.selectedIndex].classList.add('is-selected');
    cards[state.selectedIndex].scrollIntoView({ block: 'nearest' });
  }

  function activateSelected() {
    var selected = qs('.cp-card.is-selected', resultsEl);
    if (selected) {
      selected.click();
      return;
    }
    // If no selection but we have results, open the first one
    var first = qs('.cp-card', resultsEl);
    if (first) {
      first.click();
    }
  }

  function selectItem(item) {
    if (!item || !item.sku) return;
    saveRecent(item.sku);
    close();
    window.location.href = 'intake.php?sku=' + encodeURIComponent(item.sku_normalized || item.sku);
  }

  // ── Global shortcut ────────────────────────────────────────────────
  document.addEventListener('keydown', function (e) {
    // Ctrl+K (or Ctrl+k)
    if (e.ctrlKey && (e.key === 'k' || e.key === 'K')) {
      e.preventDefault();
      e.stopPropagation();
      if (state.open) {
        close();
      } else {
        open();
      }
      return;
    }

    // Close on ESC even when not focused on input
    if (e.key === 'Escape' && state.open) {
      e.preventDefault();
      close();
    }
  });

  // ── Init ────────────────────────────────────────────────────────────
  loadRecent();
})();
