/**
 * Filter bar component for resource list views.
 */
import type { FieldDefinition } from '../types.js';

export interface FilterBarConfig {
  container: HTMLElement;
  fields: FieldDefinition[];
  currentFilters: Record<string, unknown>;
  onChange: (filters: Record<string, unknown>) => void;
}

export function renderFilterBar(config: FilterBarConfig): void {
  const { container, fields, currentFilters, onChange } = config;
  const filterableFields = fields.filter((f) => f.filterable);

  if (filterableFields.length === 0) {
    container.innerHTML = '';
    return;
  }

  const bar = document.createElement('div');
  bar.className = 'admin-filter-bar';

  for (const field of filterableFields) {
    const group = document.createElement('div');
    group.className = 'admin-filter-bar__group';

    const label = document.createElement('label');
    label.textContent = field.label;
    label.htmlFor = `filter-${field.name}`;
    group.appendChild(label);

    const input = document.createElement('input');
    input.type = 'text';
    input.id = `filter-${field.name}`;
    input.name = field.name;
    input.className = 'admin-filter-bar__input';
    input.value = currentFilters[field.name] != null ? String(currentFilters[field.name]) : '';
    input.placeholder = `Filter by ${field.label}`;
    group.appendChild(input);

    bar.appendChild(group);
  }

  const applyBtn = document.createElement('button');
  applyBtn.type = 'button';
  applyBtn.className = 'admin-btn admin-btn--secondary';
  applyBtn.textContent = 'Apply Filters';
  applyBtn.addEventListener('click', () => {
    const newFilters: Record<string, unknown> = {};
    for (const field of filterableFields) {
      const input = bar.querySelector<HTMLInputElement>(`#filter-${field.name}`);
      if (input && input.value.trim() !== '') {
        newFilters[field.name] = input.value.trim();
      }
    }
    onChange(newFilters);
  });
  bar.appendChild(applyBtn);

  const clearBtn = document.createElement('button');
  clearBtn.type = 'button';
  clearBtn.className = 'admin-btn admin-btn--ghost';
  clearBtn.textContent = 'Clear';
  clearBtn.addEventListener('click', () => {
    onChange({});
  });
  bar.appendChild(clearBtn);

  container.innerHTML = '';
  container.appendChild(bar);
}
