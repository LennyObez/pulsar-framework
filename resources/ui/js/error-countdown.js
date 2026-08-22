/**
 * Retry-After countdown for the 429 / 503 error pages — script-src 'self' clean.
 *
 * Served same-origin at /ui/js/error-countdown.js and loaded with `defer`, so it
 * replaces the inline <script> the error pages used to carry (which their own
 * default `script-src 'self'` policy blocked). The seconds value lives in the
 * DOM as the text of #retry-countdown, so no inline data is needed either.
 *
 * Purely an enhancement: if this file never loads, the page still shows the
 * error and the static retry-after value — only the live countdown and the
 * auto-reload are lost.
 */
(function () {
  'use strict';

  var el = document.getElementById('retry-countdown');
  if (!el) {
    return;
  }

  var seconds = parseInt(el.textContent, 10);
  if (isNaN(seconds) || seconds <= 0) {
    return;
  }

  var timer = setInterval(function () {
    seconds--;
    el.textContent = seconds;
    if (seconds <= 0) {
      clearInterval(timer);
      location.reload();
    }
  }, 1000);
})();
