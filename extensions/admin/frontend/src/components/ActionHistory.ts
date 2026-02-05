/**
 * Action history component.
 */
import { api } from '../api.js';

export interface ActionHistoryConfig {
  container: HTMLElement;
  resource?: string;
  limit?: number;
}

export async function renderActionHistory(config: ActionHistoryConfig): Promise<void> {
  const { container, resource, limit = 50 } = config;

  const data = resource
    ? await api.resourceHistory(resource, limit)
    : await api.actionHistory(limit);

  const entries = data.entries;

  const wrapper = document.createElement('div');
  wrapper.className = 'admin-action-history';

  if (entries.length === 0) {
    const empty = document.createElement('p');
    empty.textContent = 'No activity recorded yet';
    wrapper.appendChild(empty);
    container.innerHTML = '';
    container.appendChild(wrapper);
    return;
  }

  const table = document.createElement('table');
  table.className = 'admin-table admin-table--compact';

  const thead = document.createElement('thead');
  thead.innerHTML = `<tr>
    <th>Time</th>
    <th>Action</th>
    <th>Resource</th>
    <th>Record</th>
    <th>Actor</th>
    <th>Status</th>
  </tr>`;
  table.appendChild(thead);

  const tbody = document.createElement('tbody');
  for (const entry of entries) {
    const tr = document.createElement('tr');
    const time = new Date(entry.timestamp * 1000).toLocaleString();
    const status = entry.success ? 'Success' : 'Failed';
    const statusClass = entry.success ? 'admin-status--ok' : 'admin-status--fail';
    tr.innerHTML = `
      <td>${escapeHtml(time)}</td>
      <td>${escapeHtml(entry.action)}</td>
      <td>${escapeHtml(entry.resource)}</td>
      <td>${entry.record_id ? escapeHtml(entry.record_id) : '-'}</td>
      <td>${escapeHtml(entry.actor)}</td>
      <td><span class="${statusClass}">${escapeHtml(status)}</span></td>
    `;
    tbody.appendChild(tr);
  }
  table.appendChild(tbody);

  wrapper.appendChild(table);
  container.innerHTML = '';
  container.appendChild(wrapper);
}

function escapeHtml(text: string): string {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}
