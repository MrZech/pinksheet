(function () {
  var menuToggle = document.getElementById('menu-toggle');
  var menuPanel = document.getElementById('global-menu');
  if (!menuToggle || !menuPanel) {
    return;
  }

  var bodyElement = document.body;
  var desktopQuery = window.matchMedia('(min-width: 1024px)');

  var setMenuState = function (open) {
    // On desktop we allow collapsing the sidebar by toggling a body class.
    if (desktopQuery.matches) {
      menuPanel.classList.toggle('is-open', open);
      menuPanel.setAttribute('aria-hidden', open ? 'false' : 'true');
      menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      bodyElement.classList.toggle('menu-collapsed', !open);
      return;
    }
    menuPanel.classList.toggle('is-open', open);
    menuPanel.setAttribute('aria-hidden', open ? 'false' : 'true');
    menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    bodyElement.classList.toggle('has-open-menu', open);
  };

  var syncMenuLayout = function () {
    if (desktopQuery.matches) {
      // Preserve collapsed state if the body has the class; otherwise open.
      var collapsed = bodyElement.classList.contains('menu-collapsed');
      setMenuState(!collapsed);
      return;
    }
    if (!menuPanel.classList.contains('is-open')) {
      menuPanel.setAttribute('aria-hidden', 'true');
      menuToggle.setAttribute('aria-expanded', 'false');
      bodyElement.classList.remove('has-open-menu');
    }
  };

  syncMenuLayout();
  if (typeof desktopQuery.addEventListener === 'function') {
    desktopQuery.addEventListener('change', syncMenuLayout);
  } else if (typeof desktopQuery.addListener === 'function') {
    desktopQuery.addListener(syncMenuLayout);
  }

  menuToggle.addEventListener('click', function () {
    setMenuState(!menuPanel.classList.contains('is-open'));
  });

  document.addEventListener('click', function (event) {
    // On mobile, clicking outside closes the menu. On desktop, ignore.
    if (desktopQuery.matches) {
      return;
    }
    if (!menuPanel.classList.contains('is-open')) {
      return;
    }
    if (!menuPanel.contains(event.target) && !menuToggle.contains(event.target)) {
      setMenuState(false);
    }
  });

  document.addEventListener('keydown', function (event) {
    if (desktopQuery.matches) {
      return;
    }
    if (event.key === 'Escape') {
      setMenuState(false);
    }
  });

  document.querySelectorAll('[data-new-intake]').forEach(function (link) {
    link.addEventListener('click', function () {
      try {
        localStorage.removeItem('intakeDraftV1');
      } catch (e) {}
    });
  });
})();
