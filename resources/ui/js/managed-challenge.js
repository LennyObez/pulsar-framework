/**
 * Pulsar Managed Challenge — Widget
 *
 * Self-hosted, privacy-preserving CAPTCHA: no external service, no cookies, no
 * fingerprinting. On load it reads the signed challenge embedded by the @shield
 * directive, solves the proof-of-work in a Web Worker, writes the solved token
 * into the hidden field, and blocks the enclosing form from submitting until
 * the challenge is solved. Invisible to legitimate users.
 *
 * Classic script (loaded via <script src defer>, CSP `script-src 'self'`); it
 * spawns the proof-of-work as an ES-module worker.
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
    const challenge = el.getAttribute('data-pmc-challenge');
    const id = el.getAttribute('data-pmc-id');
    const bits = parseInt(el.getAttribute('data-pmc-bits') || '0', 10);
    const workerUrl = el.getAttribute('data-pmc-worker');
    const input = el.querySelector('input[type="hidden"]');

    if (!challenge || !id || !workerUrl || !input || Number.isNaN(bits)) {
      el.setAttribute('data-pmc-state', 'error');
      return;
    }

    const form = el.closest('form');
    let solved = false;

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

    let worker;

    try {
      worker = new Worker(workerUrl, { type: 'module' });
    } catch {
      // Fail closed: leave the field empty so the server rejects the submission.
      el.setAttribute('data-pmc-state', 'error');
      return;
    }

    worker.addEventListener('message', (event) => {
      const data = event.data || {};

      if (typeof data.solution === 'string') {
        input.value = challenge + '.' + data.solution;
        solved = true;
        el.setAttribute('data-pmc-state', 'solved');

        if (form) {
          form.removeEventListener('submit', blockUntilSolved);
        }

        el.dispatchEvent(new CustomEvent('pulsar:challenge-solved', { bubbles: true }));
      } else {
        el.setAttribute('data-pmc-state', 'error');
        el.dispatchEvent(new CustomEvent('pulsar:challenge-error', { bubbles: true }));
      }

      worker.terminate();
    });

    el.setAttribute('data-pmc-state', 'solving');
    worker.postMessage({ id, bits });
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
