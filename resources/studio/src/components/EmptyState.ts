import { escapeHtml } from '../utils/escapeHtml.js';

export interface EmptyStateOptions {
  title: string;
  message: string;
  backUrl?: string;
  backLabel?: string;
}

/**
 * Validate that a URL is safe (not javascript: or data:).
 */
function isSafeUrl(url: string): boolean {
  // Only allow relative URLs starting with /
  // or absolute HTTP(S) URLs
  if (url.startsWith('/')) return true;
  if (url.startsWith('https://')) return true;
  if (url.startsWith('http://')) return true;
  return false;
}

export function renderEmptyState(options: EmptyStateOptions): string {
  const backLink =
    options.backUrl && isSafeUrl(options.backUrl)
      ? `<a href="${escapeHtml(options.backUrl)}" class="btn">${escapeHtml(options.backLabel ?? 'Go Back')}</a>`
      : '';

  return `
    <div class="empty-state">
      <h2>${escapeHtml(options.title)}</h2>
      <p>${escapeHtml(options.message)}</p>
      ${backLink}
    </div>
  `;
}
