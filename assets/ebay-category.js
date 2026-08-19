(function () {
  'use strict';

  var input = document.getElementById('ebay-category-input');
  if (!input) { return; }

  var menu = document.getElementById('ebay-category-menu');
  var form = document.getElementById('intake-form');
  var pathInput = form ? form.querySelector('input[name="ebay_category_path"]') : null;
  var idInput = form ? form.querySelector('input[name="ebay_category_id"]') : null;
  var wrapper = input.closest('.ebay-category-combo');

  var categories = [];
  var filtered = [];
  var activeIndex = -1;
  var loaded = false;

  function normalize(text) {
    return (text || '').toLowerCase().replace(/\s+/g, ' ').trim();
  }

  function setHidden(path, id) {
    if (pathInput) { pathInput.value = path || ''; }
    if (idInput) { idInput.value = id || ''; }
    if (window.updateState) {
      window.updateState('ebay_category_path', path || '');
      window.updateState('ebay_category_id', id || '');
    }
  }

  function selectCategory(cat) {
    input.value = cat.name;
    setHidden(cat.path, cat.id || '');
    if (window.updateState) { window.updateState('ebay_category', cat.name); }
    close();
  }

  function clearActive() {
    var options = menu.querySelectorAll('.ebay-combo-option');
    for (var i = 0; i < options.length; i++) { options[i].classList.remove('is-active'); }
  }

  function setActive(i) {
    clearActive();
    var options = menu.querySelectorAll('.ebay-combo-option');
    if (i >= 0 && options[i]) {
      options[i].classList.add('is-active');
      try { options[i].scrollIntoView({ block: 'nearest' }); } catch (e) {}
    }
  }

  function renderMenu() {
    menu.innerHTML = '';
    var items = filtered.slice(0, 60);
    if (!items.length) {
      var empty = document.createElement('li');
      empty.className = 'ebay-combo-empty';
      empty.textContent = 'No matching category';
      menu.appendChild(empty);
    } else {
      items.forEach(function (cat, i) {
        var li = document.createElement('li');
        li.setAttribute('role', 'option');
        li.className = 'ebay-combo-option';
        li.dataset.index = String(i);

        var nameSpan = document.createElement('span');
        nameSpan.className = 'ebay-combo-name';
        nameSpan.textContent = cat.name;
        li.appendChild(nameSpan);

        if (cat.path) {
          var pathSpan = document.createElement('span');
          pathSpan.className = 'ebay-combo-path';
          pathSpan.textContent = cat.path;
          li.appendChild(pathSpan);
        }

        li.addEventListener('mousedown', function (e) {
          e.preventDefault(); // keep focus on the text input
          selectCategory(cat);
        });
        menu.appendChild(li);
      });
    }
    activeIndex = -1;
    menu.hidden = false;
  }

  function filter() {
    var q = normalize(input.value);
    if (!q) {
      filtered = categories;
    } else {
      filtered = categories.filter(function (c) {
        return normalize(c.name).indexOf(q) !== -1 || normalize(c.path).indexOf(q) !== -1;
      });
    }
    renderMenu();
  }

  function open() {
    if (!loaded) { return; }
    filter();
  }

  function close() {
    menu.hidden = true;
    activeIndex = -1;
  }

  input.addEventListener('focus', open);

  input.addEventListener('input', function () {
    // Manual typing replaces a previously selected category with free text.
    setHidden('', '');
    open();
  });

  input.addEventListener('keydown', function (e) {
    if (menu.hidden) { return; }
    var options = menu.querySelectorAll('.ebay-combo-option');
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      activeIndex = Math.min(activeIndex + 1, options.length - 1);
      setActive(activeIndex);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      activeIndex = Math.max(activeIndex - 1, 0);
      setActive(activeIndex);
    } else if (e.key === 'Enter') {
      if (activeIndex >= 0 && filtered[activeIndex]) {
        e.preventDefault();
        selectCategory(filtered[activeIndex]);
      }
    } else if (e.key === 'Escape') {
      close();
    }
  });

  document.addEventListener('click', function (e) {
    if (wrapper && !wrapper.contains(e.target)) { close(); }
  });

  fetch('ebay_categories.php', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data && Array.isArray(data.categories)) {
        categories = data.categories;
      }
      loaded = true;
      if (document.activeElement === input) { open(); }
    })
    .catch(function () {
      loaded = true;
    });
})();
