/**
 * Pulsar contact-cloak reassembler v1.0.0
 *
 * Rebuilds anti-scraping contact links rendered by the @cloakmail / @cloaktel
 * directives (Pulsar\View\ContactCloak). The address never appears literally in
 * the served HTML — it travels as base64-encoded data attributes — so scrapers
 * reading the markup find nothing to lift. On load this script decodes the
 * attributes and sets the mailto:/tel: href and the visible text.
 *
 * Progressive enhancement: with JavaScript disabled the anchors stay readable
 * fallback text ("email" / "call" or a caller-supplied label) with no href, so
 * nothing is broken — there is simply no clickable link.
 *
 * CSP `script-src 'self'` clean: served from your own origin, no inline code, no
 * eval, zero dependencies.
 */
(function () {
  'use strict';

  /**
   * @param {string} value
   * @returns {string}
   */
  function decode(value) {
    try {
      return value ? atob(value) : '';
    } catch (e) {
      return '';
    }
  }

  /** @param {Element} el */
  function reassembleMail(el) {
    var user = decode(el.getAttribute('data-u') || '');
    var domain = decode(el.getAttribute('data-d') || '');
    if (user === '' || domain === '') {
      return;
    }
    var address = user + '@' + domain;
    el.setAttribute('href', 'mailto:' + address);
    el.textContent = address;
    el.removeAttribute('data-u');
    el.removeAttribute('data-d');
  }

  /** @param {Element} el */
  function reassembleTel(el) {
    var number = decode(el.getAttribute('data-n') || '');
    if (number === '') {
      return;
    }
    el.setAttribute('href', 'tel:' + number);
    el.textContent = number;
    el.removeAttribute('data-n');
  }

  function reassembleAll() {
    var mails = document.querySelectorAll('a.pulsar-cloak-mail');
    for (var i = 0; i < mails.length; i++) {
      reassembleMail(mails[i]);
    }
    var tels = document.querySelectorAll('a.pulsar-cloak-tel');
    for (var j = 0; j < tels.length; j++) {
      reassembleTel(tels[j]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', reassembleAll);
  } else {
    reassembleAll();
  }
})();
