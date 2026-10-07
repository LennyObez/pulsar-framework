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
 * nothing is broken — there is simply no clickable link. An anchor whose
 * payload does not decode to an address or a dialable number keeps that same
 * fallback text: the script writes an href it can vouch for, or none.
 *
 * CSP `script-src 'self'` clean: served from your own origin, no inline code, no
 * eval, zero dependencies.
 */
(function () {
  'use strict';

  /**
   * The address shape this script is willing to build a URL from: a dot-atom
   * local part and a dotted domain, drawn from characters that mean nothing to
   * a URI parser. base64 decodes to arbitrary bytes, so what comes back from
   * the payload is not an address until something says it is — and an address
   * carrying '?' or '&' would open a header field section (RFC 6068 §2), which
   * turns a contact link into a pre-filled message somebody else wrote.
   */
  var MAIL_ADDRESS = /^[A-Za-z0-9._+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+$/;

  /** The digits RFC 3966 dials with. */
  var TEL_DIGITS = '0123456789';

  /** The visual separators RFC 3966 permits between them. */
  var TEL_SEPARATORS = '-.()';

  /**
   * @param {string} value
   * @returns {string}
   */
  function decode(value) {
    try {
      return value ? atob(value) : '';
    } catch {
      // A malformed payload decodes to nothing rather than throwing at the
      // caller: the cloak degrades to an empty address, never a broken page.
      return '';
    }
  }

  /**
   * Normalise a decoded payload into an RFC 3966 telephone-subscriber.
   *
   * Every character kept is taken from the constant alphabets above rather than
   * from the payload, so the returned string is assembled out of this file
   * alone. Spaces are a writing convention with no place in the URI, so they
   * are dropped; anything else means the payload is not a phone number, and a
   * value that is not a phone number does not become a tel: URL.
   *
   * @param {string} raw  The decoded payload
   * @returns {string} The dialable number, or '' when `raw` is not one
   */
  function dialable(raw) {
    var out = '';
    var digits = 0;

    for (var i = 0; i < raw.length; i++) {
      var ch = raw.charAt(i);

      if (ch === ' ') {
        continue;
      }

      if (ch === '+') {
        // RFC 3966 puts the '+' of a global number first or not at all.
        if (out !== '') {
          return '';
        }
        out += '+';
        continue;
      }

      var digit = TEL_DIGITS.indexOf(ch);
      if (digit !== -1) {
        out += TEL_DIGITS.charAt(digit);
        digits++;
        continue;
      }

      var separator = TEL_SEPARATORS.indexOf(ch);
      if (separator === -1) {
        return '';
      }

      out += TEL_SEPARATORS.charAt(separator);
    }

    return digits === 0 ? '' : out;
  }

  /** @param {Element} el */
  function reassembleMail(el) {
    var user = decode(el.getAttribute('data-u') || '');
    var domain = decode(el.getAttribute('data-d') || '');
    var address = user + '@' + domain;
    if (!MAIL_ADDRESS.test(address)) {
      return;
    }

    // RFC 6068 §2 percent-encodes the addr-spec and the mail handler decodes it
    // again, so encoding here is both the conformant way to write the URL and
    // the guarantee that the href carries one addr-spec and no header fields.
    el.setAttribute(
      'href',
      'mailto:' + encodeURIComponent(user) + '@' + encodeURIComponent(domain),
    );
    el.textContent = address;
    el.removeAttribute('data-u');
    el.removeAttribute('data-d');
  }

  /** @param {Element} el */
  function reassembleTel(el) {
    var raw = decode(el.getAttribute('data-n') || '');
    var number = dialable(raw);
    if (number === '') {
      return;
    }

    // The href dials the normalised number; the text keeps the spacing the
    // author wrote, which is what a reader is meant to see.
    el.setAttribute('href', 'tel:' + number);
    el.textContent = raw;
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
