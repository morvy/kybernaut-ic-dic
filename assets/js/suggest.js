"use strict";

/**
 * Company name suggestions, shared by public.js (classic checkout) and blocks.js.
 *
 * Typing into the company field lists matching subjects from ARES (CZ) or the Slovak lists (SK)
 * (woolab_icdic_search_ajax()); picking one hands its IČO to the caller, whose IČO
 * lookup then fills the company, tax IDs and address as if the IČO had been typed.
 *
 * Loaded before the script that calls it, so it only reads `woolab` when used.
 *
 * @since 2.0.0
 */
window.woolabSuggest = function (options) {
  var list = document.createElement('ul');
  list.id = 'woolab-ic-dic-suggest';
  list.className = 'woolab-ic-dic-suggest';
  list.setAttribute('role', 'listbox');
  list.hidden = true;
  var input = null;
  var items = [];
  var active = -1;
  var timer;
  var request = 0;
  function matches(el) {
    return el && el.matches && el.matches(options.selector);
  }

  /**
   * Spinner in the field while a lookup runs (src/css/lookup.css).
   */
  function busy(el, on) {
    el.parentNode.classList.toggle('woolab-ic-dic-loading', on);
    el.setAttribute('aria-busy', on ? 'true' : 'false');
  }
  function close() {
    request++;
    list.hidden = true;
    active = -1;
    if (input) {
      busy(input, false);
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
    }
  }
  function highlight(index) {
    active = index;
    Array.prototype.forEach.call(list.children, function (li, i) {
      li.setAttribute('aria-selected', i === index ? 'true' : 'false');
    });
    if (index >= 0) {
      input.setAttribute('aria-activedescendant', list.id + '-' + index);
    }
  }
  function choose(index) {
    var item = items[index];
    close();
    if (item) {
      options.pick(item);
    }
  }

  /**
   * Dress the list like the field it belongs to. Themes style their inputs through
   * many routes (theme.json, a customizer, plain CSS), but the result is always in
   * the computed style, so read it there. A transparent input shows the page
   * behind it: take the first ancestor background that is not transparent.
   */
  function theme() {
    var style = getComputedStyle(input);
    var background = style.backgroundColor;
    for (var el = input; el && /^(transparent|rgba\(.*,\s*0\))$/.test(background); el = el.parentElement) {
      background = getComputedStyle(el).backgroundColor;
    }
    list.style.setProperty('--woolab-ic-dic-suggest-bg', background);
    list.style.setProperty('--woolab-ic-dic-suggest-text', style.color);
    list.style.setProperty('--woolab-ic-dic-suggest-border', style.borderTopColor);
    list.style.setProperty('--woolab-ic-dic-suggest-radius', style.borderTopLeftRadius);
    list.style.fontFamily = style.fontFamily;
  }
  function render() {
    list.textContent = '';
    if (!items.length) {
      close();
      return;
    }
    items.forEach(function (item, i) {
      var li = document.createElement('li');
      var name = document.createElement('strong');
      var meta = document.createElement('small');
      li.id = list.id + '-' + i;
      li.setAttribute('role', 'option');
      li.dataset.index = i;
      name.textContent = item.name;
      meta.textContent = item.ico + (item.address ? ' · ' + item.address : '');
      li.appendChild(name);
      li.appendChild(meta);
      list.appendChild(li);
    });

    // The field's own wrapper is the anchor, so the list sits right under it.
    var host = input.parentNode;
    host.classList.add('woolab-ic-dic-suggest-host');
    if (list.parentNode !== host) {
      host.appendChild(list);
    }
    theme();
    list.hidden = false;
    input.setAttribute('aria-expanded', 'true');
    highlight(-1);
  }
  function search(el) {
    var name = el.value.trim();
    var country = options.country();
    if (el.readOnly || name.length < 3 || !options.enabled(country)) {
      close();
      return;
    }
    var id = ++request;
    busy(el, true);
    fetch(woolab.ajaxurl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: 'action=woolab_icdic_search&name=' + encodeURIComponent(name) + '&country=' + encodeURIComponent(country) + '&nonce=' + encodeURIComponent(woolab.nonce)
    }).then(function (response) {
      return response.json();
    }).then(function (data) {
      // A newer keystroke, or the shopper has left the field.
      if (id !== request || document.activeElement !== el) {
        return;
      }
      busy(el, false);
      items = data && data.results ? data.results : [];
      render();
    })["catch"](close);
  }
  document.addEventListener('input', function (event) {
    if (!matches(event.target)) {
      return;
    }
    input = event.target;
    input.setAttribute('aria-controls', list.id);
    input.setAttribute('aria-autocomplete', 'list');
    // The browser's own autofill list would cover this one.
    input.setAttribute('autocomplete', 'off');
    clearTimeout(timer);
    timer = setTimeout(search, 400, input);
  });
  document.addEventListener('keydown', function (event) {
    if (list.hidden || event.target !== input) {
      return;
    }
    switch (event.key) {
      case 'ArrowDown':
        highlight((active + 1) % items.length);
        break;
      case 'ArrowUp':
        highlight(active <= 0 ? items.length - 1 : active - 1);
        break;
      case 'Enter':
        if (active < 0) {
          return;
        }
        choose(active);
        break;
      case 'Escape':
        close();
        break;
      default:
        return;
    }
    event.preventDefault();
  });
  document.addEventListener('focusout', function (event) {
    if (event.target === input) {
      clearTimeout(timer);
      close();
    }
  });

  // mousedown, not click: keeps focus in the field, so focusout does not close first.
  list.addEventListener('mousedown', function (event) {
    var li = event.target.closest('li');
    event.preventDefault();
    if (li) {
      choose(Number(li.dataset.index));
    }
  });
};