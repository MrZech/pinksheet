(function () {
  var menuToggle = document.getElementById('menu-toggle');
  var menuPanel = document.getElementById('global-menu');

  if (menuToggle) {
    menuToggle.setAttribute('aria-expanded', 'true');
    menuToggle.setAttribute('aria-hidden', 'true');
  }

  if (menuPanel) {
    menuPanel.classList.add('is-open');
    menuPanel.setAttribute('aria-hidden', 'false');
  }

  document.querySelectorAll('[data-new-intake]').forEach(function (link) {
    link.addEventListener('click', function () {
      try {
        localStorage.removeItem('intakeDraftV1');
      } catch (e) {}
    });
  });
})();
