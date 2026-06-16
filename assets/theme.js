(function () {
  var themeToggle = document.getElementById('theme-toggle');
  function applyThemeMode(mode) {
    var isDark = mode === 'dark';
    document.body.dataset.theme = isDark ? 'dark' : 'light';
    document.body.classList.toggle('dark-mode', isDark);
    if (themeToggle) {
      themeToggle.textContent = isDark ? 'Light mode' : 'Dark mode';
    }
  }
  var storedTheme = null;
  try {
    storedTheme = localStorage.getItem('themePreference');
  } catch (e) {}
  var initialTheme = storedTheme || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  applyThemeMode(initialTheme);
  if (themeToggle) {
    themeToggle.addEventListener('click', function () {
      var nextMode = document.body.dataset.theme === 'dark' ? 'light' : 'dark';
      applyThemeMode(nextMode);
      try { localStorage.setItem('themePreference', nextMode); } catch (e) {}
    });
  }
})();
