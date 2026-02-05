/**
 * Bulk action bar component.
 */
import { api } from '../api.js';

export interface BulkActionDefinition {
  name: string;
  label: string;
  destructive: boolean;
  requireConfirmation: boolean;
}

export interface BulkActionBarConfig {
  container: HTMLElement;
  resource: string;
  actions: BulkActionDefinition[];
  selectedIds: string[];
  onComplete?: () => void;
  onError?: (message: string) => void;
}

export function renderBulkActionBar(config: BulkActionBarConfig): void {
  const { container, resource, actions, selectedIds, onComplete, onError } = config;

  if (selectedIds.length === 0 || actions.length === 0) {
    container.innerHTML = '';
    return;
  }

  const bar = document.createElement('div');
  bar.className = 'admin-bulk-action-bar';

  const info = document.createElement('span');
  info.className = 'admin-bulk-action-bar__info';
  info.textContent = `${selectedIds.length} item(s) selected`;
  bar.appendChild(info);

  for (const action of actions) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = action.destructive
      ? 'admin-btn admin-btn--danger'
      : 'admin-btn admin-btn--secondary';
    btn.textContent = action.label;
    btn.addEventListener('click', async () => {
      if (action.requireConfirmation) {
        const confirmed = confirm(
          `Are you sure you want to ${action.label.toLowerCase()} ${selectedIds.length} item(s)?`,
        );
        if (!confirmed) return;
      }
      try {
        await api.bulkAction(resource, action.name, selectedIds);
        onComplete?.();
      } catch (err) {
        onError?.(err instanceof Error ? err.message : 'Bulk action failed');
      }
    });
    bar.appendChild(btn);
  }

  container.innerHTML = '';
  container.appendChild(bar);
}
