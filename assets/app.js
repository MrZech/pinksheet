(function () {
  'use strict';

  /* ──────────────────────────────────────
   *  Centralized Application State
   *  ──────────────────────────────────────
   *  All field mutations flow through
   *  window.updateState().  Event delegation
   *  at the document level replaces per-field
   *  listeners.  A 500 ms debounced watcher
   *  flushes dirty state to the server.
   */

  window.appState = {};
  var dirty = false;
  var syncTimer = null;
  var statusEl = document.getElementById('autosave-status');

  var subscribers = [];

  /* Subscribe a callback(key, value, fullState) that fires
     after every state change.  Used by UI widgets that need
     to react (e.g. Script Builder preview). */
  window.appSubscribe = function (fn) {
    if (typeof fn === 'function') subscribers.push(fn);
  };

  /* Notify all subscribers */
  var notify = function (key, value) {
    for (var i = 0; i < subscribers.length; i++) {
      try { subscribers[i](key, value, window.appState); } catch (e) {}
    }
  };

  /* ── Status Indicator ── */
  var statusTimer = null;
  var setStatus = function (text, className) {
    if (!statusEl) return;
    statusEl.textContent = text;
    statusEl.className = 'autosave-status';
    if (className) statusEl.classList.add(className);
    statusEl.hidden = false;
    clearTimeout(statusTimer);
    if (className === 'saved') {
      statusTimer = setTimeout(function () { statusEl.hidden = true; }, 3000);
    }
  };

  /* ── State Update (sole mutation entry point) ── */
  window.updateState = function (field, value) {
    window.appState[field] = value;
    dirty = true;
    notify(field, value);
    scheduleSync();
  };

  /* ── Debounced Server Sync ── */
  var SYNC_DELAY = 500;

  var scheduleSync = function () {
    clearTimeout(syncTimer);
    syncTimer = setTimeout(syncToServer, SYNC_DELAY);
  };

  var syncToServer = function () {
    if (!dirty) return;
    var sku = ((window.appState['sku'] || window.appState['prompt_sku'] || '') + '').trim().toUpperCase();
    if (!sku) {
      setStatus('Add a SKU to save', 'warn');
      return;
    }
    setStatus('Saving changes\u2026', 'saving');
    dirty = false;
    fetch('autosave.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        sku: sku,
        data: window.appState
      })
    })
    .then(function (r) { return r.json(); })
    .then(function (resp) {
      if (resp.status === 'ok') {
        setStatus('All changes saved', 'saved');
      } else {
        dirty = true;
        setStatus('Save failed: ' + (resp.message || 'unknown error'), 'err');
      }
    })
    .catch(function () {
      dirty = true;
      setStatus('Save failed (network)', 'err');
    });
  };

  /* ── Event Delegation ──
     Captures changes from ALL input,select,textarea
     elements without per-field listeners.          */
  var handleChange = function (target) {
    if (!target || !target.name) return;
    if (target.type === 'file') return;
    if (target.type === 'checkbox') {
      window.updateState(target.name, target.checked);
    } else {
      window.updateState(target.name, target.value);
    }
  };

  document.addEventListener('input', function (e) {
    handleChange(e.target);
  });

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t || !t.name) return;
    if (t.type === 'file') return;
    if (t.tagName === 'SELECT') {
      handleChange(t);
    }
  });

  /* ── Persist unsaved state to localStorage when SKU is missing ── */
  var saveDraftToLocal = function () {
    try {
      var state = window.appState || {};
      var sku = ((state['sku'] || '') + '').trim().toUpperCase();
      if (!sku && dirty && Object.keys(state).length > 0) {
        localStorage.setItem('intakeDraftV1', JSON.stringify(state));
        return true;
      }
      if (sku && dirty) {
        localStorage.removeItem('intakeDraftV1');
      }
    } catch (e) {}
    return false;
  };

  /* ── Flush pending changes before navigation ── */
  var flushBeforeUnload = function () {
    if (!dirty) return;
    saveDraftToLocal();
    clearTimeout(syncTimer);
    syncTimer = setTimeout(syncToServer, 0);
  };

  window.addEventListener('beforeunload', flushBeforeUnload);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') {
      flushBeforeUnload();
    }
  });

  /* ── Expose force-sync for form submit handlers ── */
  window.forceSync = function () {
    clearTimeout(syncTimer);
    dirty = true;
    return syncToServer();
  };

})();
