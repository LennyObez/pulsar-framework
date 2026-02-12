/**
 * CSRF-aware fetch wrapper for CMS API calls.
 *
 * Reads the CSRF token from `<meta name="csrf-token">` and attaches it
 * as an `X-CSRF-Token` header on every request. Sets `Accept: application/json`
 * and, for non-FormData bodies, `Content-Type: application/json`.
 */
export async function cmsApi(url: string, options: RequestInit = {}): Promise<Response> {
  const csrfToken =
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

  const headers = new Headers(options.headers);
  headers.set('X-CSRF-Token', csrfToken);
  headers.set('Accept', 'application/json');

  if (options.body && !(options.body instanceof FormData)) {
    headers.set('Content-Type', 'application/json');
  }

  return fetch(url, { ...options, headers });
}
