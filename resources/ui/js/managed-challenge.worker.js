/**
 * Pulsar Managed Challenge — Web Worker
 *
 * Solves the proof-of-work off the main thread so the page stays responsive
 * while the puzzle is computed. Receives `{ id, bits }`, searches for a
 * solution, and posts `{ solution }` (or `{ error }`). An ES-module worker so
 * it can share the tested proof-of-work core; under CSP it requires only
 * `worker-src 'self'` / `script-src 'self'` (no blob:, no external origin).
 *
 * @module managed-challenge.worker
 */
'use strict';

import { solve } from './managed-challenge.pow.js';

self.addEventListener('message', (event) => {
  const data = event.data || {};
  const { id, bits } = data;

  if (typeof id !== 'string' || typeof bits !== 'number' || bits < 0) {
    self.postMessage({ error: 'invalid-input' });
    return;
  }

  const solution = solve(id, bits);

  if (solution === null) {
    self.postMessage({ error: 'no-solution' });
    return;
  }

  self.postMessage({ solution });
});
