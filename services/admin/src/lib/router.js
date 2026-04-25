/**
 * Client-side router for Pulsar Admin Console.
 *
 * Hand-rolled router on the History API. No framework dependency.
 * Routes are registered as path patterns ; the router resolves the active route
 * and invokes its handler when navigation occurs.
 */

import { signal } from './signals.js';

/** @typedef {{ path: string, handler: (params: Record<string, string>) => void }} Route */

/** @type {Route[]} */
const routes = [];
const currentPath = signal(window.location.pathname);

/**
 * Register a route pattern with its handler.
 *
 * @param {string} pattern - e.g. "/users/:id"
 * @param {(params: Record<string, string>) => void} handler
 */
export function registerRoute(pattern, handler) {
  routes.push({ path: pattern, handler });
}

/**
 * Navigate to a path, updating history state.
 *
 * @param {string} path
 */
export function navigate(path) {
  history.pushState(null, '', path);
  currentPath.set(path);
  resolve();
}

function resolve() {
  const path = currentPath.get();
  for (const route of routes) {
    const params = match(route.path, path);
    if (params !== null) {
      route.handler(params);
      return;
    }
  }
}

/**
 * @param {string} pattern
 * @param {string} path
 * @returns {Record<string, string> | null}
 */
function match(pattern, path) {
  const patternParts = pattern.split('/');
  const pathParts = path.split('/');
  if (patternParts.length !== pathParts.length) return null;

  /** @type {Record<string, string>} */
  const params = {};
  for (let i = 0; i < patternParts.length; i++) {
    const p = patternParts[i];
    const v = pathParts[i];
    if (p === undefined || v === undefined) return null;
    if (p.startsWith(':')) {
      params[p.slice(1)] = v;
    } else if (p !== v) {
      return null;
    }
  }
  return params;
}

window.addEventListener('popstate', () => {
  currentPath.set(window.location.pathname);
  resolve();
});
