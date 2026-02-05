export interface EmptyStateOptions {
  title: string;
  message: string;
  backUrl?: string;
  backLabel?: string;
}

export function renderEmptyState(options: EmptyStateOptions): string {
  const backLink = options.backUrl
    ? `<a href="${options.backUrl}" class="btn">${options.backLabel ?? 'Go Back'}</a>`
    : '';

  return `
    <div class="empty-state">
      <h2>${options.title}</h2>
      <p>${options.message}</p>
      ${backLink}
    </div>
  `;
}
