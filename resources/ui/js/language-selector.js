/**
 * Pulsar region/language/currency selector component v2.0.0
 *
 * Unified selector that supports three modes:
 *   - "language" (default, backward-compatible): simple language dropdown
 *   - "region": full-screen overlay with countries grouped by continent + language
 *   - "full": region + currency display
 *
 * Usage (declarative):
 *   <!-- Language only (backward compat) -->
 *   <div data-language-selector data-locales="en,fr,nl,de" data-current="en"></div>
 *
 *   <!-- Region + language overlay -->
 *   <div data-language-selector data-selector-mode="region" data-locales="en,fr,nl" data-current="en" data-country="BE"></div>
 *
 *   <!-- Full: region + language + currency -->
 *   <div data-language-selector data-selector-mode="full" data-locales="en,fr,nl" data-current="en" data-country="BE" data-currency="EUR"></div>
 *
 * Usage (programmatic):
 *   PulsarI18n.init({ el: '#selector', mode: 'region', locales: ['en','fr'], current: 'en', country: 'BE' });
 *
 * Zero dependencies. ARIA-compliant. Keyboard navigable.
 */
(function () {
  'use strict';

  /** @type {Map<string, Record<string, string>>} */
  var translationCache = new Map();

  /** @type {Map<string, Promise<Record<string, string>>>} */
  var pendingFetches = new Map();

  var LOCALE_KEY = 'pulsar_locale';
  var REGION_KEY = 'pulsar_region';
  var CURRENCY_KEY = 'pulsar_currency';

  /** Language metadata: code -> [nativeName, flag] */
  var LANGUAGES = {
    en: ['English', '\uD83C\uDDEC\uD83C\uDDE7'],
    fr: ['Fran\u00E7ais', '\uD83C\uDDEB\uD83C\uDDF7'],
    nl: ['Nederlands', '\uD83C\uDDF3\uD83C\uDDF1'],
    de: ['Deutsch', '\uD83C\uDDE9\uD83C\uDDEA'],
    es: ['Espa\u00F1ol', '\uD83C\uDDEA\uD83C\uDDF8'],
    it: ['Italiano', '\uD83C\uDDEE\uD83C\uDDF9'],
    pt: ['Portugu\u00EAs', '\uD83C\uDDF5\uD83C\uDDF9'],
    pl: ['Polski', '\uD83C\uDDF5\uD83C\uDDF1'],
    ro: ['Rom\u00E2n\u0103', '\uD83C\uDDF7\uD83C\uDDF4'],
    cs: ['\u010Ce\u0161tina', '\uD83C\uDDE8\uD83C\uDDFF'],
    el: ['\u0395\u03BB\u03BB\u03B7\u03BD\u03B9\u03BA\u03AC', '\uD83C\uDDEC\uD83C\uDDF7'],
    hu: ['Magyar', '\uD83C\uDDED\uD83C\uDDFA'],
    sv: ['Svenska', '\uD83C\uDDF8\uD83C\uDDEA'],
    da: ['Dansk', '\uD83C\uDDE9\uD83C\uDDF0'],
    fi: ['Suomi', '\uD83C\uDDEB\uD83C\uDDEE'],
    sk: ['Sloven\u010Dina', '\uD83C\uDDF8\uD83C\uDDF0'],
    bg: ['\u0411\u044A\u043B\u0433\u0430\u0440\u0441\u043A\u0438', '\uD83C\uDDE7\uD83C\uDDEC'],
    hr: ['Hrvatski', '\uD83C\uDDED\uD83C\uDDF7'],
    sl: ['Sloven\u0161\u010Dina', '\uD83C\uDDF8\uD83C\uDDEE'],
    lt: ['Lietuvi\u0173', '\uD83C\uDDF1\uD83C\uDDF9'],
    lv: ['Latvie\u0161u', '\uD83C\uDDF1\uD83C\uDDFB'],
    et: ['Eesti', '\uD83C\uDDEA\uD83C\uDDEA'],
    ga: ['Gaeilge', '\uD83C\uDDEE\uD83C\uDDEA'],
    mt: ['Malti', '\uD83C\uDDF2\uD83C\uDDF9'],
    lb: ['L\u00EBtzebuergesch', '\uD83C\uDDF1\uD83C\uDDFA'],
    ar: ['\u0627\u0644\u0639\u0631\u0628\u064A\u0629', ''],
    he: ['\u05E2\u05D1\u05E8\u05D9\u05EA', ''],
    fa: ['\u0641\u0627\u0631\u0633\u06CC', ''],
    ur: ['\u0627\u0631\u062F\u0648', ''],
    sq: ['Shqip', '\uD83C\uDDE6\uD83C\uDDF1'],
    ca: ['Catal\u00E0', ''],
  };

  /** Continent display labels */
  var CONTINENT_LABELS = {
    europe: 'Europe',
    north_america: 'North America',
    south_america: 'South America',
    asia: 'Asia',
    africa: 'Africa',
    oceania: 'Oceania',
    antarctica: 'Antarctica',
  };

  /** @type {Object|null} Cached country registry from API */
  var registryCache = null;

  /** @type {Promise<Object>|null} */
  var registryFetch = null;

  // =========================================================================
  // SVG icon builders (safe DOM construction, no innerHTML with user data)
  // =========================================================================

  /**
   * Create an SVG element from a trusted static template.
   * All SVG strings here are compile-time constants -- never user input.
   *
   * @param {string} svgMarkup  Hardcoded SVG string (trusted)
   * @returns {Element}
   */
  function createSvgFromTrusted(svgMarkup) {
    var parser = new DOMParser();
    var doc = parser.parseFromString(svgMarkup, 'image/svg+xml');
    return doc.documentElement;
  }

  function createSearchIcon() {
    return createSvgFromTrusted(
      '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
    );
  }

  function createCloseIcon() {
    return createSvgFromTrusted(
      '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
    );
  }

  function createChevronIcon() {
    var svg = createSvgFromTrusted(
      '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>',
    );
    svg.classList.add('pui-region-trigger__chevron');
    return svg;
  }

  function createGlobeIcon() {
    var svg = createSvgFromTrusted(
      '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
    );
    svg.classList.add('pui-region-empty__icon');
    return svg;
  }

  // =========================================================================
  // Preference storage (localStorage — no cookies, no consent required)
  // =========================================================================

  function setPreference(key, value) {
    try {
      localStorage.setItem(key, value);
    } catch (_e) {
      // Private browsing or storage full — silently ignore
    }
  }

  function getPreference(key) {
    try {
      return localStorage.getItem(key);
    } catch (_e) {
      return null;
    }
  }

  // =========================================================================
  // Translation fetching
  // =========================================================================

  /**
   * Build headers for API calls with locale/region preferences.
   * Server-side reads these instead of cookies for GDPR compliance.
   */
  function preferenceHeaders() {
    var headers = {};
    var locale = getPreference(LOCALE_KEY);
    var region = getPreference(REGION_KEY);
    if (locale) headers['X-Pulsar-Locale'] = locale;
    if (region) headers['X-Pulsar-Region'] = region;
    return headers;
  }

  function fetchTranslations(locale) {
    if (translationCache.has(locale)) {
      return Promise.resolve(translationCache.get(locale));
    }

    if (pendingFetches.has(locale)) {
      return pendingFetches.get(locale);
    }

    var promise = fetch('/api/i18n/' + locale + '.json', {
      headers: preferenceHeaders(),
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('Failed to fetch translations for ' + locale);
        }
        return response.json();
      })
      .then(function (data) {
        translationCache.set(locale, data);
        pendingFetches.delete(locale);
        return data;
      })
      .catch(function (err) {
        pendingFetches.delete(locale);
        console.error('[PulsarI18n]', err.message);
        return {};
      });

    pendingFetches.set(locale, promise);
    return promise;
  }

  function applyTranslations(translations) {
    var elements = document.querySelectorAll('[data-t]');
    for (var i = 0; i < elements.length; i++) {
      var el = elements[i];
      var key = el.getAttribute('data-t');
      if (key && translations[key] !== undefined) {
        el.textContent = translations[key];
      }
    }

    var inputs = document.querySelectorAll('[data-t-placeholder]');
    for (var j = 0; j < inputs.length; j++) {
      var input = inputs[j];
      var pKey = input.getAttribute('data-t-placeholder');
      if (pKey && translations[pKey] !== undefined) {
        input.setAttribute('placeholder', translations[pKey]);
      }
    }

    var titled = document.querySelectorAll('[data-t-title]');
    for (var k = 0; k < titled.length; k++) {
      var tEl = titled[k];
      var tKey = tEl.getAttribute('data-t-title');
      if (tKey && translations[tKey] !== undefined) {
        tEl.setAttribute('title', translations[tKey]);
      }
    }

    var ariaLabeled = document.querySelectorAll('[data-t-aria-label]');
    for (var m = 0; m < ariaLabeled.length; m++) {
      var aEl = ariaLabeled[m];
      var aKey = aEl.getAttribute('data-t-aria-label');
      if (aKey && translations[aKey] !== undefined) {
        aEl.setAttribute('aria-label', translations[aKey]);
      }
    }
  }

  function switchLocale(locale) {
    fetchTranslations(locale).then(function (translations) {
      applyTranslations(translations);
      setPreference(LOCALE_KEY, locale);
      document.documentElement.setAttribute('lang', locale);

      var event;
      try {
        event = new CustomEvent('pulsar:locale-changed', {
          detail: { locale: locale },
        });
      } catch (_e) {
        event = document.createEvent('CustomEvent');
        event.initCustomEvent('pulsar:locale-changed', true, true, {
          locale: locale,
        });
      }
      document.dispatchEvent(event);
    });
  }

  // =========================================================================
  // Country registry API
  // =========================================================================

  function fetchRegistry() {
    if (registryCache) {
      return Promise.resolve(registryCache);
    }

    if (registryFetch) {
      return registryFetch;
    }

    registryFetch = fetch('/api/i18n/regions.json', {
      headers: preferenceHeaders(),
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('Failed to fetch country registry');
        }
        return response.json();
      })
      .then(function (data) {
        registryCache = data;
        registryFetch = null;
        return data;
      })
      .catch(function (err) {
        registryFetch = null;
        console.error('[PulsarI18n]', err.message);
        return {};
      });

    return registryFetch;
  }

  // =========================================================================
  // Language-only dropdown (backward-compatible mode)
  // =========================================================================

  function renderLanguageDropdown(container, locales, current) {
    var wrapper = document.createElement('div');
    wrapper.className = 'pulsar-language-selector';
    wrapper.setAttribute('role', 'listbox');
    wrapper.setAttribute('aria-label', 'Select language');

    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'pulsar-language-selector__trigger';
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-haspopup', 'listbox');

    var langInfo = LANGUAGES[current] || [current, ''];
    button.textContent = langInfo[1] + ' ' + langInfo[0];

    var dropdown = document.createElement('ul');
    dropdown.className = 'pulsar-language-selector__dropdown';
    dropdown.setAttribute('role', 'listbox');
    dropdown.hidden = true;

    locales.forEach(function (locale) {
      var info = LANGUAGES[locale] || [locale, ''];
      var li = document.createElement('li');
      li.className = 'pulsar-language-selector__option';
      li.setAttribute('role', 'option');
      li.setAttribute('data-locale', locale);
      if (locale === current) {
        li.setAttribute('aria-selected', 'true');
        li.classList.add('pulsar-language-selector__option--active');
      }
      li.textContent = info[1] + ' ' + info[0];

      li.addEventListener('mouseenter', function () {
        fetchTranslations(locale);
      });

      li.addEventListener('click', function () {
        switchLocale(locale);

        var allOptions = dropdown.querySelectorAll('.pulsar-language-selector__option');
        for (var n = 0; n < allOptions.length; n++) {
          allOptions[n].classList.remove('pulsar-language-selector__option--active');
          allOptions[n].setAttribute('aria-selected', 'false');
        }
        li.classList.add('pulsar-language-selector__option--active');
        li.setAttribute('aria-selected', 'true');

        button.textContent = info[1] + ' ' + info[0];

        dropdown.hidden = true;
        button.setAttribute('aria-expanded', 'false');
      });

      dropdown.appendChild(li);
    });

    button.addEventListener('click', function () {
      var isOpen = dropdown.hidden === false;
      dropdown.hidden = isOpen;
      button.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
    });

    document.addEventListener('click', function (e) {
      if (!wrapper.contains(e.target)) {
        dropdown.hidden = true;
        button.setAttribute('aria-expanded', 'false');
      }
    });

    wrapper.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        dropdown.hidden = true;
        button.setAttribute('aria-expanded', 'false');
        button.focus();
      }
    });

    wrapper.appendChild(button);
    wrapper.appendChild(dropdown);
    container.appendChild(wrapper);
  }

  // =========================================================================
  // Region/Full overlay
  // =========================================================================

  /**
   * Build the full-screen region selector overlay.
   *
   * @param {HTMLElement} container  The mounting element
   * @param {Object}      opts       { mode, locales, current, country, currency, flag }
   */
  function renderRegionSelector(container, opts) {
    var mode = opts.mode || 'region';
    var currentLocale = opts.current || 'en';
    var currentCountry = opts.country || '';
    var currentCurrency = opts.currency || '';
    var currentFlag = opts.flag || '';
    var showCurrency = mode === 'full';

    // --- Trigger button ---
    var trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'pui-region-trigger';
    trigger.setAttribute('aria-expanded', 'false');
    trigger.setAttribute('aria-haspopup', 'dialog');
    trigger.setAttribute('aria-label', 'Select your region and language');

    function updateTriggerLabel() {
      // Clear children safely
      while (trigger.firstChild) {
        trigger.removeChild(trigger.firstChild);
      }

      if (currentFlag) {
        var flagSpan = document.createElement('span');
        flagSpan.className = 'pui-region-trigger__flag';
        flagSpan.textContent = currentFlag;
        flagSpan.setAttribute('aria-hidden', 'true');
        trigger.appendChild(flagSpan);
      }

      var label = document.createElement('span');
      label.className = 'pui-region-trigger__label';
      var parts = [];
      if (currentCountry) parts.push(currentCountry);
      var langName = LANGUAGES[currentLocale];
      if (langName) parts.push(langName[0]);
      if (showCurrency && currentCurrency) parts.push(currentCurrency);
      label.textContent = parts.join(' / ');
      trigger.appendChild(label);

      trigger.appendChild(createChevronIcon());
    }

    updateTriggerLabel();

    // --- Overlay ---
    var overlayId = 'pui-region-overlay-' + Math.random().toString(36).substring(2, 9);

    var overlay = document.createElement('div');
    overlay.id = overlayId;
    overlay.className = 'pui-region-overlay';
    overlay.hidden = true;
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', 'Select your region and language');

    // --- Overlay header ---
    var header = document.createElement('div');
    header.className = 'pui-region-header';

    var title = document.createElement('h2');
    title.className = 'pui-region-header__title';
    title.textContent = 'Choose your region';
    title.id = overlayId + '-title';
    overlay.setAttribute('aria-labelledby', title.id);

    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'pui-region-close';
    closeBtn.setAttribute('aria-label', 'Close region selector');
    closeBtn.appendChild(createCloseIcon());

    header.appendChild(title);
    header.appendChild(closeBtn);

    // --- Search ---
    var searchSection = document.createElement('div');
    searchSection.className = 'pui-region-search';

    var searchWrapper = document.createElement('div');
    searchWrapper.className = 'pui-region-search__wrapper';

    var searchIconWrap = document.createElement('span');
    searchIconWrap.className = 'pui-region-search__icon';
    searchIconWrap.appendChild(createSearchIcon());

    var searchInput = document.createElement('input');
    searchInput.type = 'search';
    searchInput.className = 'pui-region-search__input';
    searchInput.placeholder = 'Search countries...';
    searchInput.setAttribute('aria-label', 'Search countries');

    searchWrapper.appendChild(searchIconWrap);
    searchWrapper.appendChild(searchInput);
    searchSection.appendChild(searchWrapper);

    // --- Content ---
    var content = document.createElement('div');
    content.className = 'pui-region-content';

    overlay.appendChild(header);
    overlay.appendChild(searchSection);
    overlay.appendChild(content);

    // --- Open/close logic ---
    var previousFocus = null;

    function openOverlay() {
      previousFocus = document.activeElement;
      overlay.hidden = false;
      overlay.classList.remove('pui-region-overlay--exiting');
      trigger.setAttribute('aria-expanded', 'true');
      document.body.style.overflow = 'hidden';
      searchInput.value = '';
      searchInput.focus();
      loadContent('');
    }

    function closeOverlay() {
      overlay.classList.add('pui-region-overlay--exiting');
      trigger.setAttribute('aria-expanded', 'false');

      function onEnd() {
        overlay.classList.remove('pui-region-overlay--exiting');
        overlay.hidden = true;
        document.body.style.overflow = '';
        overlay.removeEventListener('animationend', onEnd);
        if (previousFocus && previousFocus.focus) {
          previousFocus.focus();
        }
      }

      // If animations are disabled, close immediately
      if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        overlay.hidden = true;
        document.body.style.overflow = '';
        if (previousFocus && previousFocus.focus) {
          previousFocus.focus();
        }
      } else {
        overlay.addEventListener('animationend', onEnd);
      }
    }

    trigger.addEventListener('click', openOverlay);
    closeBtn.addEventListener('click', closeOverlay);

    // Escape key and focus trap
    overlay.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        closeOverlay();
      }

      if (e.key === 'Tab') {
        var focusable = overlay.querySelectorAll(
          'button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])',
        );
        if (focusable.length === 0) return;
        var first = focusable[0];
        var last = focusable[focusable.length - 1];

        if (e.shiftKey) {
          if (document.activeElement === first) {
            e.preventDefault();
            last.focus();
          }
        } else {
          if (document.activeElement === last) {
            e.preventDefault();
            first.focus();
          }
        }
      }
    });

    // --- Country selection handler ---
    function selectCountryLanguage(countryCode, countryName, flag, lang, currency) {
      currentCountry = countryCode;
      currentLocale = lang;
      currentCurrency = currency;
      currentFlag = flag;

      setPreference(REGION_KEY, countryCode);
      setPreference(LOCALE_KEY, lang);
      if (showCurrency) {
        setPreference(CURRENCY_KEY, currency);
      }

      switchLocale(lang);
      updateTriggerLabel();
      closeOverlay();

      // Dispatch region change event
      var event;
      try {
        event = new CustomEvent('pulsar:region-changed', {
          detail: {
            country: countryCode,
            language: lang,
            currency: currency,
          },
        });
      } catch (_e) {
        event = document.createEvent('CustomEvent');
        event.initCustomEvent('pulsar:region-changed', true, true, {
          country: countryCode,
          language: lang,
          currency: currency,
        });
      }
      document.dispatchEvent(event);
    }

    // --- Content rendering ---
    function loadContent(filter) {
      fetchRegistry().then(function (registry) {
        renderCountryGrid(content, registry, filter, showCurrency);
      });
    }

    function renderCountryGrid(targetEl, registry, filter, showCurr) {
      // Clear content safely
      while (targetEl.firstChild) {
        targetEl.removeChild(targetEl.firstChild);
      }

      var query = (filter || '').toLowerCase().trim();
      var hasResults = false;

      var continentOrder = [
        'europe',
        'north_america',
        'south_america',
        'asia',
        'africa',
        'oceania',
        'antarctica',
      ];

      continentOrder.forEach(function (continentKey) {
        var countries = registry[continentKey];
        if (!countries || !countries.length) return;

        var filtered = countries;
        if (query) {
          filtered = countries.filter(function (c) {
            return (
              c.name.toLowerCase().indexOf(query) !== -1 ||
              c.code.toLowerCase().indexOf(query) !== -1
            );
          });
        }

        if (filtered.length === 0) return;
        hasResults = true;

        var section = document.createElement('div');
        section.className = 'pui-region-continent';

        var heading = document.createElement('h3');
        heading.className = 'pui-region-continent__heading';
        heading.textContent = CONTINENT_LABELS[continentKey] || continentKey;
        section.appendChild(heading);

        var grid = document.createElement('div');
        grid.className = 'pui-region-continent__grid';
        grid.setAttribute('role', 'list');

        filtered.forEach(function (country) {
          var item = document.createElement('div');
          item.className = 'pui-region-country';
          item.setAttribute('role', 'listitem');
          item.setAttribute('tabindex', '0');

          if (country.code === currentCountry && !query) {
            item.classList.add('pui-region-country--active');
          }

          // Flag
          var flagEl = document.createElement('span');
          flagEl.className = 'pui-region-country__flag';
          flagEl.textContent = country.flag;
          flagEl.setAttribute('aria-hidden', 'true');
          item.appendChild(flagEl);

          // Info column
          var info = document.createElement('div');
          info.className = 'pui-region-country__info';

          var nameEl = document.createElement('span');
          nameEl.className = 'pui-region-country__name';
          nameEl.textContent = country.name;
          info.appendChild(nameEl);

          // Language buttons
          var langWrap = document.createElement('div');
          langWrap.className = 'pui-region-country__languages';

          country.languages.forEach(function (lang) {
            var langBtn = document.createElement('button');
            langBtn.type = 'button';
            langBtn.className = 'pui-region-country__lang';

            if (lang === currentLocale && country.code === currentCountry) {
              langBtn.classList.add('pui-region-country__lang--active');
            }

            var langMeta = LANGUAGES[lang];
            langBtn.textContent = langMeta ? langMeta[0] : lang;
            langBtn.setAttribute(
              'aria-label',
              country.name + ' - ' + (langMeta ? langMeta[0] : lang),
            );

            langBtn.addEventListener('click', function (e) {
              e.stopPropagation();
              selectCountryLanguage(
                country.code,
                country.name,
                country.flag,
                lang,
                country.currency,
              );
            });

            langWrap.appendChild(langBtn);
          });

          info.appendChild(langWrap);

          // Currency (full mode only)
          if (showCurr) {
            var currEl = document.createElement('span');
            currEl.className = 'pui-region-country__currency';
            currEl.textContent = country.currency;
            info.appendChild(currEl);
          }

          item.appendChild(info);

          // Clicking the item row selects the primary language
          item.addEventListener('click', function () {
            selectCountryLanguage(
              country.code,
              country.name,
              country.flag,
              country.languages[0],
              country.currency,
            );
          });

          // Enter/Space on focused item
          item.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
              e.preventDefault();
              selectCountryLanguage(
                country.code,
                country.name,
                country.flag,
                country.languages[0],
                country.currency,
              );
            }
          });

          grid.appendChild(item);
        });

        section.appendChild(grid);
        targetEl.appendChild(section);
      });

      if (!hasResults) {
        var empty = document.createElement('div');
        empty.className = 'pui-region-empty';
        empty.appendChild(createGlobeIcon());
        var emptyText = document.createElement('p');
        emptyText.className = 'pui-region-empty__text';
        emptyText.textContent = 'No countries match your search';
        empty.appendChild(emptyText);
        targetEl.appendChild(empty);
      }
    }

    // Search filtering with debounce
    var searchTimeout = null;
    searchInput.addEventListener('input', function () {
      clearTimeout(searchTimeout);
      searchTimeout = setTimeout(function () {
        loadContent(searchInput.value);
      }, 150);
    });

    // Mount
    container.appendChild(trigger);
    document.body.appendChild(overlay);
  }

  // =========================================================================
  // Auto-init
  // =========================================================================

  function autoInit() {
    var selectors = document.querySelectorAll('[data-language-selector]');
    for (var i = 0; i < selectors.length; i++) {
      var el = selectors[i];
      var mode = el.getAttribute('data-selector-mode') || 'language';
      var localesAttr = el.getAttribute('data-locales') || 'en';
      var current = el.getAttribute('data-current') || getPreference(LOCALE_KEY) || 'en';
      var locales = localesAttr.split(',').map(function (s) {
        return s.trim();
      });

      if (mode === 'language') {
        renderLanguageDropdown(el, locales, current);
      } else {
        renderRegionSelector(el, {
          mode: mode,
          locales: locales,
          current: current,
          country: el.getAttribute('data-country') || getPreference(REGION_KEY) || '',
          currency: el.getAttribute('data-currency') || getPreference(CURRENCY_KEY) || '',
          flag: el.getAttribute('data-flag') || '',
        });
      }
    }
  }

  // =========================================================================
  // Public API
  // =========================================================================

  window.PulsarI18n = {
    init: function (options) {
      var el = typeof options.el === 'string' ? document.querySelector(options.el) : options.el;
      if (!el) return;

      var mode = options.mode || 'language';

      if (mode === 'language') {
        renderLanguageDropdown(el, options.locales || ['en'], options.current || 'en');
      } else {
        renderRegionSelector(el, {
          mode: mode,
          locales: options.locales || ['en'],
          current: options.current || 'en',
          country: options.country || '',
          currency: options.currency || '',
          flag: options.flag || '',
        });
      }
    },
    switchLocale: switchLocale,
    fetchTranslations: fetchTranslations,
    fetchRegistry: fetchRegistry,
    getCache: function () {
      return translationCache;
    },
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoInit);
  } else {
    autoInit();
  }
})();
