/**
 * Field value renderer.
 *
 * Renders field values with appropriate formatting based on field type.
 */
import type { FieldDefinition } from '../types.js';

export function renderFieldValue(
  container: HTMLElement,
  field: FieldDefinition,
  value: unknown,
): void {
  if (field.redacted) {
    const span = document.createElement('span');
    span.className = 'admin-redacted';
    span.textContent = '\u2022\u2022\u2022\u2022\u2022\u2022';
    container.appendChild(span);
    return;
  }

  if (value === null || value === undefined) {
    const span = document.createElement('span');
    span.className = 'admin-field--null';
    span.textContent = '-';
    container.appendChild(span);
    return;
  }

  switch (field.type) {
    case 'boolean': {
      const span = document.createElement('span');
      span.className = value ? 'admin-field--bool-true' : 'admin-field--bool-false';
      span.textContent = value ? 'Yes' : 'No';
      container.appendChild(span);
      break;
    }

    case 'email': {
      const link = document.createElement('a');
      link.href = `mailto:${String(value)}`;
      link.textContent = String(value);
      container.appendChild(link);
      break;
    }

    case 'url': {
      const link = document.createElement('a');
      link.href = String(value);
      link.textContent = String(value);
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      container.appendChild(link);
      break;
    }

    case 'datetime':
    case 'date': {
      const time = document.createElement('time');
      const dateStr = String(value);
      time.dateTime = dateStr;
      time.textContent =
        field.type === 'date'
          ? new Date(dateStr).toLocaleDateString()
          : new Date(dateStr).toLocaleString();
      container.appendChild(time);
      break;
    }

    case 'json': {
      const pre = document.createElement('pre');
      pre.className = 'admin-field--json';
      pre.textContent = typeof value === 'string' ? value : JSON.stringify(value, null, 2);
      container.appendChild(pre);
      break;
    }

    default: {
      container.textContent = String(value);
      break;
    }
  }
}
