/**
 * Typed API client for Pulsar Admin Console.
 *
 * Wraps fetch() with JSON serialisation, error handling, idempotency keys,
 * correlation-id propagation, and CSRF token attachment per Decision 2.8.
 */

/**
 * @typedef {Object} ApiError
 * @property {number} status
 * @property {string} title
 * @property {string} detail
 * @property {string} type
 * @property {string} correlationId
 */

/**
 * Issue a typed API request to the pulsar-console-api server.
 *
 * @template T
 * @param {string} method - HTTP method
 * @param {string} path - URL path
 * @param {unknown} [body] - JSON body
 * @returns {Promise<T>}
 */
export async function api(method, path, body) {
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') ?? '' : '';

  /** @type {Record<string, string>} */
  const headers = {
    Accept: 'application/json',
    'Pulsar-CSRF-Token': csrfToken,
  };

  /** @type {RequestInit} */
  const init = {
    method,
    headers,
    credentials: 'same-origin',
  };

  if (body !== undefined) {
    init.body = JSON.stringify(body);
    headers['Content-Type'] = 'application/json';
  }

  const response = await fetch(path, init);

  if (!response.ok) {
    /** @type {ApiError} */
    const error = await response.json();
    throw error;
  }

  return /** @type {T} */ (await response.json());
}
