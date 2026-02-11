/**
 * Resource data table component.
 *
 * Renders a sortable, selectable table for admin resource list views.
 */
import type { FieldDefinition, SortDirection } from '../types.js';

export interface ResourceTableConfig {
  container: HTMLElement;
  fields: FieldDefinition[];
  data: Array<Record<string, unknown>>;
  primaryKey: string;
  onSort?: (field: string, direction: SortDirection) => void;
  onSelect?: (ids: string[]) => void;
  onRowClick?: (id: string) => void;
}

export function renderResourceTable(config: ResourceTableConfig): void {
  const { container, fields, data, primaryKey, onSort, onSelect, onRowClick } = config;
  const listFields = fields.filter((f) => f.visibleOnList);
  const selectedIds = new Set<string>();

  const table = document.createElement('table');
  table.className = 'admin-table';

  const thead = document.createElement('thead');
  const headerRow = document.createElement('tr');

  // Checkbox header
  const checkAllTh = document.createElement('th');
  const checkAll = document.createElement('input');
  checkAll.type = 'checkbox';
  checkAll.addEventListener('change', () => {
    const checkboxes = table.querySelectorAll<HTMLInputElement>('tbody input[type="checkbox"]');
    checkboxes.forEach((cb) => {
      cb.checked = checkAll.checked;
      const id = cb.dataset.id ?? '';
      if (checkAll.checked) {
        selectedIds.add(id);
      } else {
        selectedIds.delete(id);
      }
    });
    onSelect?.(Array.from(selectedIds));
  });
  checkAllTh.appendChild(checkAll);
  headerRow.appendChild(checkAllTh);

  for (const field of listFields) {
    const th = document.createElement('th');
    th.textContent = field.label;
    if (field.sortable && onSort) {
      th.classList.add('sortable');
      th.style.cursor = 'pointer';
      th.addEventListener('click', () => {
        const currentDir = th.dataset.sortDir === 'asc' ? 'desc' : 'asc';
        th.dataset.sortDir = currentDir;
        onSort(field.name, currentDir as SortDirection);
      });
    }
    headerRow.appendChild(th);
  }

  const actionsTh = document.createElement('th');
  actionsTh.textContent = 'Actions';
  headerRow.appendChild(actionsTh);

  thead.appendChild(headerRow);
  table.appendChild(thead);

  const tbody = document.createElement('tbody');
  for (const row of data) {
    const tr = document.createElement('tr');
    const id = String(row[primaryKey] ?? '');

    // Checkbox
    const checkTd = document.createElement('td');
    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.dataset.id = id;
    checkbox.addEventListener('change', () => {
      if (checkbox.checked) {
        selectedIds.add(id);
      } else {
        selectedIds.delete(id);
      }
      onSelect?.(Array.from(selectedIds));
    });
    checkTd.appendChild(checkbox);
    tr.appendChild(checkTd);

    for (const field of listFields) {
      const td = document.createElement('td');
      const value = row[field.name];
      if (field.redacted) {
        td.innerHTML =
          '<span class="admin-redacted">&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;</span>';
      } else {
        td.textContent = value != null ? String(value) : '';
      }
      tr.appendChild(td);
    }

    // Actions
    const actionsTd = document.createElement('td');
    actionsTd.className = 'admin-table__actions';
    const viewLink = document.createElement('a');
    viewLink.textContent = 'View';
    viewLink.href = '#';
    viewLink.addEventListener('click', (e) => {
      e.preventDefault();
      onRowClick?.(id);
    });
    actionsTd.appendChild(viewLink);
    tr.appendChild(actionsTd);

    tbody.appendChild(tr);
  }
  table.appendChild(tbody);

  container.innerHTML = '';
  container.appendChild(table);
}
