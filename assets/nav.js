(function () {
  'use strict';

  var contentId = 'content-area';
  var contentEl = document.getElementById(contentId);
  if (!contentEl) return;

  /* ── Navigation ──────────────────────────────────── */
  function navigateTo(url, addHistory) {
    if (addHistory !== false) {
      history.pushState({ url: url, ts: Date.now() }, '', url);
    }

    /* Build partial URL */
    var qs = url.indexOf('?') !== -1 ? url.slice(url.indexOf('?')) : '';
    var path = url.indexOf('?') !== -1 ? url.slice(0, url.indexOf('?')) : url;
    var partialUrl = path + '?partial=1' + (qs ? '&' + qs.slice(1) : '');

    contentEl.style.opacity = '0.5';

    fetch(partialUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.text();
      })
      .then(function (html) {
        contentEl.innerHTML = html;
        execScripts(contentEl);
        contentEl.style.opacity = '1';
        updateActiveLink(url);
        updateTitle(contentEl);
        window.scrollTo(0, 0);
      })
      .catch(function () {
        contentEl.style.opacity = '1';
        window.location.href = url;
      });
  }

  /* ── Execute <script> tags found in swapped content ── */
  function execScripts(container) {
    var scripts = container.querySelectorAll('script');
    for (var i = 0; i < scripts.length; i++) {
      var old = scripts[i];
      var s = document.createElement('script');
      if (old.src) {
        s.src = old.src;
        s.async = false;
      } else {
        s.textContent = old.textContent;
      }
      old.parentNode.replaceChild(s, old);
    }
  }

  /* ── Active menu link ─────────────────────────────── */
  function updateActiveLink(url) {
    var links = document.querySelectorAll('.menu-link');
    for (var i = 0; i < links.length; i++) {
      var href = links[i].getAttribute('href') || '';
      links[i].classList.toggle('is-active', href && url.indexOf(href) !== -1);
    }
  }

  /* ── Document title from page response ────────────── */
  function updateTitle(container) {
    var title = container.querySelector('title');
    if (title && title.textContent) {
      document.title = title.textContent;
    }
  }

  /* ── Intercept internal navigation clicks ─────────── */
  document.addEventListener('click', function (e) {
    var link = e.target.closest('a');
    if (!link) return;
    if (link.hasAttribute('data-new-intake') || link.getAttribute('data-run-square-sync') !== null) return;
    /* CSV export links trigger their own download handling (assets/app.js) and must not be AJAX-navigated. */
    if (link.hasAttribute('data-csv-export')) return;
    var href = link.getAttribute('href');
    if (!href || href === '#' || href.indexOf('//') !== -1 || href.indexOf('http') === 0) return;
    if (href.indexOf('?') === 0 || href.indexOf('#') === 0) return;

    e.preventDefault();
    navigateTo(href, true);
  });

  /* ── Back / Forward ───────────────────────────────── */
  window.addEventListener('popstate', function (e) {
    if (e.state && e.state.url) {
      navigateTo(e.state.url, false);
    }
  });

  window.appNavigate = navigateTo;
})();
