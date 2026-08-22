/**
 * Pulsar Behavioural Signals — Collector
 *
 * Privacy-preserving, same-origin, CSP `script-src 'self'` clean. Collects only
 * NON-IDENTIFYING, aggregate interaction measurements for the server-side
 * score-only behavioural check — never field values, text, keystroke content,
 * mouse coordinates, IP, or any persistent identifier, and nothing leaves the
 * page except the compact blob written into the form's own hidden field.
 *
 * Signals: interaction-present, total fill duration, normalised pointer-movement
 * entropy, navigator.webdriver, paste-vs-type ratio, keydown count. With no
 * JavaScript the hidden field stays empty and the server scores it neutrally.
 *
 * @module behavior-collector
 */
'use strict';

(function () {
  /** @param {HTMLInputElement} field */
  function attach(field) {
    const form = field.closest('form');

    if (!form) {
      return;
    }

    const startedAt = Date.now();
    let interaction = false;
    let firstInteractionAt = 0;
    let keydownCount = 0;
    let typedChars = 0;
    let pastedChars = 0;

    // Pointer-movement entropy: accumulate the variance of step directions
    // without ever storing coordinates. Reset/streaming Welford-style stats.
    let moveCount = 0;
    let dirMean = 0;
    let dirM2 = 0;
    let lastX = null;
    let lastY = null;

    const markInteraction = () => {
      if (!interaction) {
        interaction = true;
        firstInteractionAt = Date.now();
      }
    };

    form.addEventListener('focusin', markInteraction, { passive: true });
    form.addEventListener('scroll', markInteraction, { passive: true, capture: true });

    form.addEventListener(
      'keydown',
      (e) => {
        markInteraction();
        keydownCount++;

        // Count only character-producing keys towards the typed total.
        if (typeof e.key === 'string' && e.key.length === 1) {
          typedChars++;
        }
      },
      { passive: true },
    );

    form.addEventListener(
      'paste',
      (e) => {
        markInteraction();
        const text = e.clipboardData ? e.clipboardData.getData('text') : '';
        pastedChars += text.length;
      },
      { passive: true },
    );

    const onMove = (x, y) => {
      markInteraction();

      if (lastX !== null && lastY !== null) {
        const angle = Math.atan2(y - lastY, x - lastX);
        moveCount++;
        // Welford online mean/variance of movement direction.
        const delta = angle - dirMean;
        dirMean += delta / moveCount;
        dirM2 += delta * (angle - dirMean);
      }

      lastX = x;
      lastY = y;
    };

    form.addEventListener('pointermove', (e) => onMove(e.clientX, e.clientY), { passive: true });
    form.addEventListener(
      'touchmove',
      (e) => {
        const t = e.touches && e.touches[0];

        if (t) {
          onMove(t.clientX, t.clientY);
        }
      },
      { passive: true },
    );

    form.addEventListener('submit', () => {
      const now = Date.now();
      const base = firstInteractionAt > 0 ? firstInteractionAt : startedAt;
      const totalChars = typedChars + pastedChars;

      // Normalise movement variance into [0,1]: human pointer paths vary in
      // direction (high entropy); a teleporting bot produces little to none.
      const variance = moveCount > 1 ? dirM2 / moveCount : 0;
      const entropy = Math.max(0, Math.min(1, variance / (Math.PI * Math.PI)));

      const payload = {
        i: interaction ? 1 : 0,
        d: Math.max(0, now - base),
        pe: Math.round(entropy * 1000) / 1000,
        wd: navigator.webdriver === true ? 1 : 0,
        pr: totalChars > 0 ? Math.round((pastedChars / totalChars) * 1000) / 1000 : 0,
        kc: keydownCount,
      };

      field.value = JSON.stringify(payload);
    });
  }

  function ready(fn) {
    if (document.readyState !== 'loading') {
      fn();
    } else {
      document.addEventListener('DOMContentLoaded', fn);
    }
  }

  ready(() => {
    document.querySelectorAll('input[data-pulsar-behavior]').forEach((el) => {
      if (el instanceof HTMLInputElement) {
        attach(el);
      }
    });
  });
})();
