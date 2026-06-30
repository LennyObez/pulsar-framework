/**
 * Pulsar Managed Challenge — Widget
 *
 * Self-hosted, privacy-preserving CAPTCHA: no external service, no cookies, no
 * fingerprinting. On load it reads the signed challenge embedded by the @shield
 * directive, solves the proof-of-work in a Web Worker, writes the solved token
 * into the hidden field, and blocks the enclosing form from submitting until
 * the challenge is solved. Invisible to legitimate users.
 *
 * Silent refresh: when a refresh endpoint and TTL are advertised, the widget
 * re-mints and re-solves a fresh challenge at ~80% of the TTL (and on the form's
 * first focus if the current token is already stale), overwriting the hidden
 * field. This lets the server keep a tight TTL (small replay window) without
 * ever rejecting a slow human. Refresh never blocks the user: on any failure
 * the last solved token is kept.
 *
 * Classic script (loaded via <script src defer>, CSP `script-src 'self'`); it
 * spawns the proof-of-work as an ES-module worker and fetches refreshes from
 * the same origin (`connect-src 'self'`).
 *
 * @module managed-challenge
 */
'use strict';

(function () {
  /**
   * Initialise a single managed-challenge widget element.
   * @param {Element} el
   */
  function init(el) {
    const initialChallenge = el.getAttribute('data-pmc-challenge');
    const initialId = el.getAttribute('data-pmc-id');
    const initialBits = parseInt(el.getAttribute('data-pmc-bits') || '0', 10);
    const workerUrl = el.getAttribute('data-pmc-worker');
    const refreshUrl = el.getAttribute('data-pmc-refresh') || '';
    const ttl = parseInt(el.getAttribute('data-pmc-ttl') || '0', 10);
    const input = el.querySelector('input[type="hidden"]');

    if (!initialChallenge || !initialId || !workerUrl || !input || Number.isNaN(initialBits)) {
      el.setAttribute('data-pmc-state', 'error');
      return;
    }

    const form = el.closest('form');
    let solved = false;
    let mintedAt = 0;
    let refreshTimer = null;

    // Block submission until the proof-of-work completes. Each widget manages
    // its own blocker, so a form with several challenges only submits once they
    // have all resolved.
    const blockUntilSolved = (event) => {
      if (!solved) {
        event.preventDefault();
      }
    };

    if (form) {
      form.addEventListener('submit', blockUntilSolved);
    }

    /**
     * Solve a challenge in a fresh worker and write the token on success.
     * @param {string} challenge signed challenge token
     * @param {string} id challenge id the worker hashes over
     * @param {number} bits difficulty
     */
    function solve(challenge, id, bits) {
      let worker;

      try {
        worker = new Worker(workerUrl, { type: 'module' });
      } catch {
        // Fail closed only on the FIRST solve (no token yet); a failed refresh
        // keeps the previous valid token.
        if (!solved) {
          el.setAttribute('data-pmc-state', 'error');
        }
        return;
      }

      worker.addEventListener('message', (event) => {
        const data = event.data || {};

        if (typeof data.solution === 'string') {
          input.value = challenge + '.' + data.solution;
          solved = true;
          mintedAt = Date.now();
          el.setAttribute('data-pmc-state', 'solved');

          if (form) {
            form.removeEventListener('submit', blockUntilSolved);
          }

          el.dispatchEvent(new CustomEvent('pulsar:challenge-solved', { bubbles: true }));
          scheduleRefresh();
        } else if (!solved) {
          el.setAttribute('data-pmc-state', 'error');
          el.dispatchEvent(new CustomEvent('pulsar:challenge-error', { bubbles: true }));
        }

        worker.terminate();
      });

      if (!solved) {
        el.setAttribute('data-pmc-state', 'solving');
      }
      worker.postMessage({ id, bits });
    }

    /** Fetch a fresh challenge and re-solve it; keep the last token on failure. */
    function refresh() {
      if (!refreshUrl) {
        return;
      }

      fetch(refreshUrl, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      })
        .then((response) =>
          response.ok ? response.json() : Promise.reject(new Error('refresh failed')),
        )
        .then((data) => {
          const bits = parseInt(String(data.bits), 10);

          if (
            typeof data.challenge === 'string' &&
            typeof data.id === 'string' &&
            !Number.isNaN(bits)
          ) {
            solve(data.challenge, data.id, bits);
          } else {
            scheduleRefresh();
          }
        })
        .catch(() => {
          // Network/endpoint hiccup: keep the last valid token and try again at
          // the next interval rather than leaving the user blocked.
          scheduleRefresh();
        });
    }

    /** Schedule the next silent refresh at ~80% of the TTL. */
    function scheduleRefresh() {
      if (!refreshUrl || ttl <= 0) {
        return;
      }

      if (refreshTimer !== null) {
        clearTimeout(refreshTimer);
      }

      refreshTimer = setTimeout(refresh, Math.max(1000, Math.floor(ttl * 0.8 * 1000)));
    }

    // If the user starts filling the form after the token has gone stale, mint a
    // fresh one immediately so the submission lands inside a valid window.
    if (form && refreshUrl && ttl > 0) {
      form.addEventListener(
        'focusin',
        () => {
          if (solved && mintedAt > 0 && Date.now() - mintedAt >= ttl * 1000) {
            refresh();
          }
        },
        { passive: true },
      );
    }

    solve(initialChallenge, initialId, initialBits);
  }

  function ready(fn) {
    if (document.readyState !== 'loading') {
      fn();
    } else {
      document.addEventListener('DOMContentLoaded', fn);
    }
  }

  ready(() => {
    document.querySelectorAll('.pulsar-managed-challenge').forEach(init);
  });
})();
