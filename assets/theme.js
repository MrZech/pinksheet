(function () {
  var storageKey = 'themePreference';

  function getThemeToggle() {
    return document.getElementById('theme-toggle');
  }

  function getStoredTheme() {
    try {
      return localStorage.getItem(storageKey);
    } catch (e) {
      return null;
    }
  }

  function storeTheme(mode) {
    try {
      localStorage.setItem(storageKey, mode);
    } catch (e) {}
  }

  function getInitialTheme() {
    var storedTheme = getStoredTheme();
    if (storedTheme === 'dark' || storedTheme === 'light') {
      return storedTheme;
    }

    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function applyThemeMode(mode) {
    if (!document.body) {
      return;
    }

    var isDark = mode === 'dark';
    var themeToggle = getThemeToggle();

    document.body.dataset.theme = isDark ? 'dark' : 'light';
    document.body.classList.toggle('dark-mode', isDark);

    if (themeToggle) {
      themeToggle.textContent = isDark ? 'Light mode' : 'Dark mode';
      themeToggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');
    }
  }

  function handleToggleClick() {
    var nextMode = document.body && document.body.dataset.theme === 'dark' ? 'light' : 'dark';
    applyThemeMode(nextMode);
    storeTheme(nextMode);
  }

  function initTheme() {
    var themeToggle = getThemeToggle();

    applyThemeMode(getInitialTheme());

    if (themeToggle && themeToggle.dataset.themeBound !== '1') {
      themeToggle.dataset.themeBound = '1';
      themeToggle.addEventListener('click', handleToggleClick);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTheme, { once: true });
  } else {
    initTheme();
  }
})();
