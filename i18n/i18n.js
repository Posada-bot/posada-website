/**
 * Posada.io i18n — powered by i18next
 * Loads translations, detects browser language, renders language picker.
 */
(function () {
  'use strict';

  var LANGUAGES = [
    { code: 'en', flag: '\u{1F1EC}\u{1F1E7}', label: 'English' },
    { code: 'pt', flag: '\u{1F1E7}\u{1F1F7}', label: 'Portugu\u00eas' },
    { code: 'es', flag: '\u{1F1EA}\u{1F1F8}', label: 'Espa\u00f1ol' },
    { code: 'fr', flag: '\u{1F1EB}\u{1F1F7}', label: 'Fran\u00e7ais' },
    { code: 'de', flag: '\u{1F1E9}\u{1F1EA}', label: 'Deutsch' },
    { code: 'it', flag: '\u{1F1EE}\u{1F1F9}', label: 'Italiano' },
    { code: 'nl', flag: '\u{1F1F3}\u{1F1F1}', label: 'Nederlands' },
    { code: 'ru', flag: '\u{1F1F7}\u{1F1FA}', label: '\u0420\u0443\u0441\u0441\u043a\u0438\u0439' },
    { code: 'ja', flag: '\u{1F1EF}\u{1F1F5}', label: '\u65e5\u672c\u8a9e' },
    { code: 'ko', flag: '\u{1F1F0}\u{1F1F7}', label: '\ud55c\uad6d\uc5b4' },
    { code: 'zh', flag: '\u{1F1E8}\u{1F1F3}', label: '\u4e2d\u6587' },
    { code: 'ar', flag: '\u{1F1F8}\u{1F1E6}', label: '\u0627\u0644\u0639\u0631\u0628\u064a\u0629' },
    { code: 'hi', flag: '\u{1F1EE}\u{1F1F3}', label: '\u0939\u093f\u0928\u094d\u0926\u0940' },
    { code: 'tr', flag: '\u{1F1F9}\u{1F1F7}', label: 'T\u00fcrk\u00e7e' },
    { code: 'pl', flag: '\u{1F1F5}\u{1F1F1}', label: 'Polski' },
    { code: 'sv', flag: '\u{1F1F8}\u{1F1EA}', label: 'Svenska' },
    { code: 'th', flag: '\u{1F1F9}\u{1F1ED}', label: '\u0e44\u0e17\u0e22' },
    { code: 'fa', flag: '\u{1F1EE}\u{1F1F7}', label: '\u0641\u0627\u0631\u0633\u06cc' },
    { code: 'da', flag: '\u{1F1E9}\u{1F1F0}', label: 'Dansk' }
  ];

  var RTL_LANGS = ['ar', 'fa'];

  // Resolve base path to /i18n/ regardless of current page depth
  function getBasePath() {
    var scripts = document.getElementsByTagName('script');
    for (var i = 0; i < scripts.length; i++) {
      var src = scripts[i].getAttribute('src') || '';
      if (src.indexOf('i18n.js') !== -1) {
        return src.replace('i18n.js', '');
      }
    }
    return '/i18n/';
  }

  var BASE = getBasePath();

  function loadJSON(url, cb) {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onreadystatechange = function () {
      if (xhr.readyState === 4) {
        if (xhr.status === 200) {
          try { cb(null, JSON.parse(xhr.responseText)); }
          catch (e) { cb(e); }
        } else {
          cb(new Error('Failed to load ' + url));
        }
      }
    };
    xhr.send();
  }

  function detectLanguage() {
    // 1. Saved preference
    var saved = localStorage.getItem('posada_lang');
    if (saved && LANGUAGES.some(function (l) { return l.code === saved; })) return saved;
    // 2. Browser language
    var nav = (navigator.language || navigator.userLanguage || 'en').split('-')[0].toLowerCase();
    if (LANGUAGES.some(function (l) { return l.code === nav; })) return nav;
    return 'en';
  }

  function applyTranslations(translations) {
    var elements = document.querySelectorAll('[data-i18n]');
    for (var i = 0; i < elements.length; i++) {
      var el = elements[i];
      var key = el.getAttribute('data-i18n');
      var val = resolveKey(translations, key);
      if (val !== undefined && val !== null) {
        // Check for attribute translations like [placeholder], [title]
        if (key.indexOf('[') === 0) {
          var attr = key.substring(1, key.indexOf(']'));
          var realKey = key.substring(key.indexOf(']') + 1);
          val = resolveKey(translations, realKey);
          if (val) el.setAttribute(attr, val);
        } else {
          el.innerHTML = val;
        }
      }
    }
    // Also handle data-i18n-placeholder, data-i18n-title
    var placeholders = document.querySelectorAll('[data-i18n-placeholder]');
    for (var j = 0; j < placeholders.length; j++) {
      var ph = placeholders[j];
      var phKey = ph.getAttribute('data-i18n-placeholder');
      var phVal = resolveKey(translations, phKey);
      if (phVal) ph.setAttribute('placeholder', phVal);
    }
  }

  function resolveKey(obj, key) {
    var parts = key.split('.');
    var cur = obj;
    for (var i = 0; i < parts.length; i++) {
      if (cur === undefined || cur === null) return undefined;
      cur = cur[parts[i]];
    }
    return cur;
  }

  function setLang(code) {
    localStorage.setItem('posada_lang', code);
    // Set dir for RTL
    document.documentElement.dir = RTL_LANGS.indexOf(code) !== -1 ? 'rtl' : 'ltr';
    document.documentElement.lang = code;

    loadJSON(BASE + code + '.json', function (err, data) {
      if (err) {
        // Fallback to English
        if (code !== 'en') {
          loadJSON(BASE + 'en.json', function (e2, d2) {
            if (!e2) applyTranslations(d2);
          });
        }
        return;
      }
      applyTranslations(data);
      updatePickerDisplay(code);
    });
  }

  function updatePickerDisplay(code) {
    var lang = LANGUAGES.find(function (l) { return l.code === code; });
    var btn = document.getElementById('langPickerBtn');
    if (btn && lang) {
      btn.innerHTML = lang.flag + ' <span class="lp-code">' + code.toUpperCase() + '</span>';
    }
    // Update active state in dropdown
    var items = document.querySelectorAll('.lp-item');
    for (var i = 0; i < items.length; i++) {
      if (items[i].getAttribute('data-lang') === code) {
        items[i].classList.add('lp-active');
      } else {
        items[i].classList.remove('lp-active');
      }
    }
  }

  function buildPicker() {
    // Find the nav element or create picker container
    var nav = document.querySelector('.nav') || document.querySelector('.nav-brand');
    if (!nav) return;

    var wrapper = document.createElement('div');
    wrapper.className = 'lang-picker';
    wrapper.innerHTML = '<button id="langPickerBtn" class="lp-btn" aria-label="Select language"></button>' +
      '<div id="langDropdown" class="lp-dropdown"></div>';

    var dropdown = wrapper.querySelector('#langDropdown');
    var html = '';
    for (var i = 0; i < LANGUAGES.length; i++) {
      var l = LANGUAGES[i];
      html += '<div class="lp-item" data-lang="' + l.code + '">' +
        '<span class="lp-flag">' + l.flag + '</span>' +
        '<span class="lp-label">' + l.label + '</span></div>';
    }
    dropdown.innerHTML = html;

    // Insert into nav
    nav.appendChild(wrapper);

    // Toggle dropdown
    var btn = wrapper.querySelector('#langPickerBtn');
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      dropdown.classList.toggle('lp-open');
    });

    // Language selection
    dropdown.addEventListener('click', function (e) {
      var item = e.target.closest('.lp-item');
      if (item) {
        var code = item.getAttribute('data-lang');
        dropdown.classList.remove('lp-open');
        setLang(code);
      }
    });

    // Close on outside click
    document.addEventListener('click', function () {
      dropdown.classList.remove('lp-open');
    });
  }

  // Inject picker CSS
  function injectStyles() {
    var style = document.createElement('style');
    style.textContent =
      '.lang-picker{position:relative;z-index:100}' +
      '.lp-btn{background:none;border:1px solid rgba(255,255,255,0.1);border-radius:8px;padding:5px 10px;cursor:pointer;font-size:0.85rem;color:#8b949e;display:flex;align-items:center;gap:4px;transition:all 0.22s ease}' +
      '.lp-btn:hover{border-color:rgba(255,255,255,0.25);color:#e8eaed}' +
      '.lp-code{font-size:0.7rem;letter-spacing:1px;font-weight:600}' +
      '.lp-dropdown{display:none;position:absolute;right:0;top:calc(100% + 8px);background:#111820;border:1px solid rgba(255,255,255,0.1);border-radius:12px;padding:8px;min-width:180px;max-height:320px;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,0.5)}' +
      '.lp-dropdown.lp-open{display:block}' +
      '.lp-item{display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:8px;cursor:pointer;transition:background 0.15s ease;font-size:0.85rem;color:#8b949e}' +
      '.lp-item:hover{background:rgba(255,255,255,0.06);color:#e8eaed}' +
      '.lp-item.lp-active{color:#e8eaed;background:rgba(255,255,255,0.04)}' +
      '.lp-item.lp-active::after{content:"\\2713";margin-left:auto;color:#22c55e;font-weight:700}' +
      '.lp-flag{font-size:1.1rem}' +
      '.lp-label{flex:1}' +
      '.lp-dropdown::-webkit-scrollbar{width:4px}' +
      '.lp-dropdown::-webkit-scrollbar-track{background:transparent}' +
      '.lp-dropdown::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.1);border-radius:4px}';
    document.head.appendChild(style);
  }

  // Init
  function init() {
    injectStyles();
    buildPicker();
    var lang = detectLanguage();
    setLang(lang);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
