/**
 * Escape a string for safe insertion into HTML.
 *
 * Replaces the five characters that have special meaning in HTML
 * (`&`, `<`, `>`, `"`, `'`) with their corresponding HTML entities.
 */
export function escapeHtml(str: string): string {
  return str
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}
