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
  var syncInFlight = false;    // a save request is currently uploading
  var syncNeedsAnother = false; // new edits arrived while a save was in flight

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
      // Don't re-warn on every focusout while the SKU is still missing.
      if (!statusEl || statusEl.textContent !== 'Add a SKU to save') {
        setStatus('Add a SKU to save', 'warn');
      }
      return;
    }
    if (syncInFlight) {
      // An earlier save is still uploading.  Re-run once it finishes so a
      // stale response can never overwrite the newest field values.
      syncNeedsAnother = true;
      return;
    }
    setStatus('Saving changes\u2026', 'saving');
    dirty = false;
    syncInFlight = true;
    fetch('autosave.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
      body: JSON.stringify({
        sku: sku,
        data: window.appState
      })
    })
    .then(function (r) { return r.json(); })
    .then(function (resp) {
      if (resp && (resp.ok === true || resp.status === 'ok')) {
        setStatus('All changes saved', 'saved');
      } else {
        dirty = true;
        setStatus('Save failed: ' + (resp.error || resp.message || 'unknown error'), 'err');
      }
    })
    .catch(function () {
      dirty = true;
      setStatus('Save failed (network)', 'err');
    })
    .finally(function () {
      syncInFlight = false;
      if (syncNeedsAnother) {
        syncNeedsAnother = false;
        clearTimeout(syncTimer);
        syncTimer = setTimeout(syncToServer, 50);
      }
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

  /* ── Save when clicking away from a field (focusout) ──
     Any pending keystrokes are flushed to the server as soon as the
     user leaves the field, instead of waiting out the debounce timer.
     When no SKU has been entered yet, the draft is still persisted to
     localStorage so nothing typed is lost. */
  document.addEventListener('focusout', function (e) {
    var t = e.target;
    if (!t || !t.name) return;
    if (t.type === 'file') return;
    if (t.tagName !== 'INPUT' && t.tagName !== 'SELECT' && t.tagName !== 'TEXTAREA') return;
    flushBeforeUnload();
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

  /* ═══════════════════════════════════════════════════════════
   *  QZ Tray Label Printing
   *  ═══════════════════════════════════════════════════════════
   *  Fetches ZPL from the server, initialises a QZ Tray
   *  connection (certificate + RSA-SHA512 signing handshake),
   *  discovers available printers, and prints directly.
   *
   *  Connection uses a non-blocking 3-try loop.  When the
   *  local engine is absent, print buttons are silently
   *  disabled and a static badge reads "Local Print Engine
   *  Offline" — no alerts, no console noise.
   *
   *  Both the intake-form "Print Sticker" button and kanban
   *  "Print Label" buttons flow through this pipeline.
   */

  /* ── Print status toast ───────────────────── */
  var printToastEl = null;

  var showPrintToast = function (message, type, duration) {
    if (!printToastEl) {
      printToastEl = document.createElement('div');
      printToastEl.className = 'qz-print-toast';
      document.body.appendChild(printToastEl);
    }
    printToastEl.textContent = message;
    printToastEl.className = 'qz-print-toast';
    if (type === 'ok') { printToastEl.classList.add('qz-print-toast-ok'); }
    if (type === 'err') { printToastEl.classList.add('qz-print-toast-err'); }
    printToastEl.classList.add('qz-print-toast-visible');

    clearTimeout(printToastEl._hideTimer);
    printToastEl._hideTimer = setTimeout(function () {
      printToastEl.classList.remove('qz-print-toast-visible');
    }, duration || 4000);
  };

  /* ── Offline badge ─────────────────────────── */
  var offlineBadgeEl = null;

  var showOfflineBadge = function () {
    if (offlineBadgeEl) { return; }
    offlineBadgeEl = document.createElement('div');
    offlineBadgeEl.className = 'print-engine-badge';
    offlineBadgeEl.textContent = 'Local Print Engine Offline';
    document.body.appendChild(offlineBadgeEl);
  };

  var removeOfflineBadge = function () {
    if (offlineBadgeEl) {
      offlineBadgeEl.parentNode.removeChild(offlineBadgeEl);
      offlineBadgeEl = null;
    }
  };

  var printBtnCache = null;
  var refreshPrintBtnCache = function () {
    printBtnCache = document.querySelectorAll('#print-sticker-btn');
  };
  refreshPrintBtnCache();

  var disablePrintButtons = function () {
    refreshPrintBtnCache();
    var buttons = printBtnCache;
    for (var i = 0; i < buttons.length; i++) {
      buttons[i]._qzOffline = true;
      buttons[i].setAttribute('disabled', 'disabled');
      buttons[i].classList.add('qz-offline');
    }
  };

  var enablePrintButtons = function () {
    refreshPrintBtnCache();
    var buttons = printBtnCache;
    for (var i = 0; i < buttons.length; i++) {
      buttons[i]._qzOffline = false;
      buttons[i].removeAttribute('disabled');
      buttons[i].classList.remove('qz-offline');
    }
  };

  /* ── QZ Tray initialisation (cert + sign handshake) ───── */
  var qzReady = null;
  var qzInitAttempts = 0;
  var QZ_MAX_RETRIES = 3;
  var QZ_RETRY_DELAY_MS = 1000;

  var initQzTray = function () {
    if (qzReady !== null) { return qzReady; }

    if (!window.qz) {
      qzReady = Promise.reject(new Error('qz-tray.js not loaded'));
      return qzReady;
    }

    if (qz.websocket.isActive()) {
      qzReady = Promise.resolve();
      removeOfflineBadge();
      enablePrintButtons();
      return qzReady;
    }

    qzInitAttempts = 0;
    qzReady = attemptConnect();

    return qzReady;
  };

  var attemptConnect = function () {
    qzInitAttempts++;

    if (!window.qz) {
      return handleConnectFailure(new Error('qz-tray.js not loaded'));
    }

    return trySignedConnect().catch(function () {
      return tryUnsignedConnect();
    })
    .then(function () {
      qzInitAttempts = 0;
      removeOfflineBadge();
      enablePrintButtons();
    })
    .catch(function (err) {
      return handleConnectFailure(err);
    });
  };

  var trySignedConnect = function () {
    return fetch('api/qz/certificate.php', { cache: 'no-store' })
      .then(function (r) {
        if (!r.ok) { throw new Error('Certificate not available (HTTP ' + r.status + ')'); }
        return r.text();
      })
      .then(function (cert) {
        return new Promise(function (resolve, reject) {
          try {
            qz.security.setCertificatePromise(function (res) { res(cert); });
            qz.security.setSignatureAlgorithm('SHA512');
            qz.security.setSignaturePromise(function (requestToSign) {
              return function (res, rej) {
                fetch('api/qz/sign.php', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({ request: requestToSign })
                })
                  .then(function (resp) {
                    if (!resp.ok) { throw new Error('QZ signing request failed (HTTP ' + resp.status + ').'); }
                    return resp.text();
                  })
                  .then(res, rej);
              };
            });
            qz.websocket.connect().then(resolve, reject);
          } catch (e) {
            reject(e);
          }
        });
      });
  };

  var tryUnsignedConnect = function () {
    return new Promise(function (resolve, reject) {
      try {
        qz.websocket.connect().then(resolve, reject);
      } catch (e) {
        reject(e);
      }
    });
  };

  var handleConnectFailure = function (err) {
    if (qzInitAttempts < QZ_MAX_RETRIES) {
      return new Promise(function (resolve, reject) {
        setTimeout(function () {
          attemptConnect().then(resolve, reject);
        }, QZ_RETRY_DELAY_MS);
      });
    }

    qzInitAttempts = 0;
    showOfflineBadge();
    disablePrintButtons();
    return Promise.reject(err);
  };

  /* ── Reset QZ connection state (exposed for recovery) ── */
  window.resetQzTray = function () {
    qzReady = null;
    qzInitAttempts = 0;
    removeOfflineBadge();
    enablePrintButtons();
  };

  /* ── Print a ZPL string via QZ Tray ────────── */
  var printZplViaQz = function (zpl) {
    if (!window.qz || !qz.websocket.isActive()) {
      return initQzTray().then(function () {
        return doPrint(zpl);
      });
    }
    return doPrint(zpl);
  };

  var doPrint = function (zpl) {
    return qz.printers.find()
      .then(function (printers) {
        if (!printers || printers.length === 0) {
          throw new Error('No printers found via QZ Tray. Check that a Zebra printer is installed and QZ Tray is running.');
        }

        var printer = printers[0];
        for (var i = 0; i < printers.length; i++) {
          var name = (printers[i] || '').toLowerCase();
          if (name.indexOf('zebra') !== -1 || name.indexOf('zpl') !== -1) {
            printer = printers[i];
            break;
          }
        }

        var config = qz.configs.create(printer);
        return qz.print(config, [{ type: 'raw', format: 'plain', data: zpl }])
          .then(function () { return printer; });
      });
  };

  /* ── Fetch ZPL from server ─────────────────── */
  var fetchLabelZpl = function (sku, preset) {
    var url = 'get_label_zpl.php?sku=' + encodeURIComponent(sku) + '&preset=' + encodeURIComponent(preset || 'compact');
    return fetch(url, { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.status !== 'ok') {
          throw new Error(data.message || 'Could not generate label.');
        }
        return data;
      });
  };

  /* ── Shared print pipeline for both buttons ── */
  var runPrintPipeline = function (sku, preset, btn, restoreFn) {
    if (!sku) {
      showPrintToast('Enter a SKU before printing.', 'err');
      return;
    }

    if (btn._qzOffline) {
      showPrintToast('Print engine is offline. Start QZ Tray and try again.', 'err');
      return;
    }

    btn._disabled = true;

    var step = function (msg) {
      if (restoreFn) { restoreFn(msg); }
    };

    step('Generating\u2026');

    fetchLabelZpl(sku, preset)
      .then(function (data) {
        step('Connecting to QZ Tray\u2026');
        return printZplViaQz(data.zpl).then(function (printerName) {
          return { printer: printerName, sku: data.sku };
        });
      })
      .then(function (result) {
        showPrintToast('Label for ' + result.sku + ' sent to ' + result.printer + '.', 'ok');
      })
      .catch(function () {
        showPrintToast('Print failed. Check that QZ Tray and a Zebra printer are running.', 'err', 6000);
      })
      .finally(function () {
        btn._disabled = false;
        if (restoreFn) { restoreFn(''); }
      });
  };

  /* ── Print Sticker button (intake form) ─────── */
  var initPrintSticker = function () {
    var btn = document.getElementById('print-sticker-btn');
    if (!btn) { return; }

    btn.addEventListener('click', function () {
      if (btn._disabled || btn._qzOffline) { return; }

      // Scope to the intake form: the photo-delete form also carries a hidden
      // input[name=sku] earlier in the DOM, so a global query grabs the wrong
      // (hidden, empty) field and printing fails with "Enter a SKU before printing."
      var skuField = document.querySelector('#intake-form input[name="sku"]');
      var sku = skuField ? (skuField.value || '').trim() : '';
      var preset = btn.getAttribute('data-label-preset') || 'compact';

      var origText = btn.textContent;
      runPrintPipeline(sku, preset, btn, function (msg) {
        btn.textContent = msg || origText;
      });
    });
  };

  /* ── Print Card (kanban cards — browser print) ── */
  var initKanbanPrint = function () {
    var board = document.getElementById('kanban-board');
    if (!board) { return; }

    board.addEventListener('click', function (e) {
      var btn = e.target.closest('.card-print-btn');
      if (!btn) { return; }

      e.stopPropagation();
      var sku = btn.getAttribute('data-sku') || '';
      if (!sku) { return; }

      printCardBrowser(sku);
    });
  };

  var printCardBrowser = function (sku) {
    var iframe = document.createElement('iframe');
    iframe.id = 'print-frame';
    iframe.style.position = 'fixed';
    iframe.style.left = '-9999px';
    iframe.style.top = '0';
    iframe.style.width = '8.5in';
    iframe.style.height = '11in';
    iframe.style.border = '0';
    iframe.style.background = '#ffffff';
    iframe.setAttribute('aria-hidden', 'true');
    document.body.appendChild(iframe);

    iframe.src = 'print_card.php?sku=' + encodeURIComponent(sku);

    var printTimer = null;

    iframe.addEventListener('load', function () {
      var iframeWindow = iframe.contentWindow;
      var iframeDoc = iframe.contentDocument || iframeWindow.document;

      var images = Array.prototype.slice.call(iframeDoc.querySelectorAll('img'));
      var remaining = images.length;

      var printIt = function () {
        clearTimeout(printTimer);
        try { iframeWindow.focus(); } catch (e) {}
        iframeWindow.print();
        setTimeout(function () {
          try { iframe.remove(); } catch (e) {}
        }, 400);
      };

      if (remaining === 0) {
        printIt();
        return;
      }

      var markLoaded = function () {
        remaining -= 1;
        if (remaining <= 0) { printIt(); }
      };

      images.forEach(function (img) {
        if (img.complete && img.naturalWidth > 0) {
          markLoaded();
          return;
        }
        img.addEventListener('load', markLoaded, { once: true });
        img.addEventListener('error', markLoaded, { once: true });
      });

      printTimer = setTimeout(printIt, 5000);
    });
  };

  /* ── Initialise on DOM ready ───────────────── */
  var initAll = function () {
    initPrintSticker();
    initKanbanPrint();

    /* Pre-flight check: if QZ is already loaded and active, mark ready. */
    if (window.qz && qz.websocket && qz.websocket.isActive && qz.websocket.isActive()) {
      removeOfflineBadge();
      enablePrintButtons();
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }

  /* ── Recovery listener: re-check on page visibility change ── */
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible' && window.qz) {
      try {
        if (qz.websocket && qz.websocket.isActive && qz.websocket.isActive()) {
          removeOfflineBadge();
          enablePrintButtons();
        }
      } catch (_) {}
    }
  });

})();
