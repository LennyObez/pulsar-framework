/**
 * Saved views component.
 */
import { api } from '../api.js';
import type { SavedView } from '../types.js';

export interface SavedViewsConfig {
  container: HTMLElement;
  resource: string;
  onApply: (view: SavedView) => void;
}

export async function renderSavedViews(config: SavedViewsConfig): Promise<void> {
  const { container, resource, onApply } = config;

  const data = await api.savedViews(resource);
  const views = data.views;

  const wrapper = document.createElement('div');
  wrapper.className = 'admin-saved-views';

  const heading = document.createElement('h4');
  heading.textContent = 'Saved Views';
  wrapper.appendChild(heading);

  if (views.length === 0) {
    const empty = document.createElement('p');
    empty.className = 'admin-saved-views__empty';
    empty.textContent = 'No saved views yet';
    wrapper.appendChild(empty);
  } else {
    const list = document.createElement('ul');
    list.className = 'admin-saved-views__list';

    for (const view of views) {
      const li = document.createElement('li');

      const applyBtn = document.createElement('button');
      applyBtn.type = 'button';
      applyBtn.className = 'admin-btn admin-btn--ghost';
      applyBtn.textContent = view.label;
      if (view.is_default) {
        applyBtn.textContent += ' (default)';
      }
      applyBtn.addEventListener('click', () => onApply(view));
      li.appendChild(applyBtn);

      const deleteBtn = document.createElement('button');
      deleteBtn.type = 'button';
      deleteBtn.className = 'admin-btn admin-btn--ghost admin-btn--danger-text';
      deleteBtn.textContent = 'Delete';
      deleteBtn.addEventListener('click', async () => {
        if (confirm(`Delete saved view "${view.label}"?`)) {
          await api.deleteSavedView(resource, view.id);
          li.remove();
        }
      });
      li.appendChild(deleteBtn);

      list.appendChild(li);
    }
    wrapper.appendChild(list);
  }

  container.innerHTML = '';
  container.appendChild(wrapper);
}
