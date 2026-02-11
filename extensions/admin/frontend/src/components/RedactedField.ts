/**
 * Redacted field display component.
 */
export function renderRedactedField(container: HTMLElement): void {
  const span = document.createElement('span');
  span.className = 'admin-redacted';
  span.title = 'This field is redacted';
  span.setAttribute('aria-label', 'Redacted');
  span.textContent = '\u2022\u2022\u2022\u2022\u2022\u2022';
  container.appendChild(span);
}
