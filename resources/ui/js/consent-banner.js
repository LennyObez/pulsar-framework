/**
 * Cookie-consent banner behaviour — script-src 'self' clean.
 *
 * Served same-origin (no inline <script>). All configuration travels in data-*
 * attributes on #pulsar-consent-banner, so there is no inline config literal
 * either. `data-consent="necessary"` marks this as a strictly-necessary script
 * for consent-aware blockers. Visibility is toggled with the HTML `hidden`
 * attribute, so there is no inline style and no helper class to ship.
 */
(function () {
  'use strict';

  var banner = document.getElementById('pulsar-consent-banner');
  if (!banner) {
    return;
  }

  var cookieName = banner.getAttribute('data-cookie-name') || 'pulsar_consent';
  var cookieTtlDays = parseInt(banner.getAttribute('data-cookie-ttl-days'), 10) || 365;
  var granular = banner.getAttribute('data-granular') === '1';
  var categories = [];
  try {
    categories = JSON.parse(banner.getAttribute('data-categories') || '[]');
  } catch (_e) {
    categories = [];
  }

  // Consent already recorded: leave the banner hidden.
  var existing = document.cookie.match(new RegExp('(?:^|;\\s*)' + cookieName + '=([^;]*)'));
  if (existing) {
    return;
  }

  banner.hidden = false;

  function saveConsent(accepted) {
    var expires = new Date();
    expires.setDate(expires.getDate() + cookieTtlDays);
    document.cookie =
      cookieName +
      '=' +
      accepted.join(',') +
      ';expires=' +
      expires.toUTCString() +
      ';path=/;SameSite=Lax';
    banner.hidden = true;
  }

  function keysOf(list) {
    return list.map(function (category) {
      return category.key;
    });
  }

  var acceptBtn = document.getElementById('pulsar-consent-accept');
  if (acceptBtn) {
    acceptBtn.addEventListener('click', function () {
      saveConsent(keysOf(categories));
    });
  }

  var rejectBtn = document.getElementById('pulsar-consent-reject');
  if (rejectBtn) {
    rejectBtn.addEventListener('click', function () {
      saveConsent(
        keysOf(
          categories.filter(function (category) {
            return category.required;
          }),
        ),
      );
    });
  }

  var saveBtn = document.getElementById('pulsar-consent-save');
  if (granular && saveBtn) {
    saveBtn.hidden = false;
    saveBtn.addEventListener('click', function () {
      var accepted = [];
      categories.forEach(function (category) {
        var checkbox = document.getElementById('consent-' + category.key);
        if (category.required || (checkbox && checkbox.checked)) {
          accepted.push(category.key);
        }
      });
      saveConsent(accepted);
    });
  }
})();
