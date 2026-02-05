/**
 * Admin panel main entry point.
 *
 * Initializes the admin SPA-like interactivity layer on top of
 * the server-rendered HTML pages.
 */
import { api } from './api.js';
import { renderGlobalSearch } from './components/GlobalSearch.js';
import { initializeSchemaBuilder } from './components/SchemaBuilder.js';
import { initializeSchemaView } from './components/SchemaTableView.js';

document.addEventListener('DOMContentLoaded', () => {
  initializeGlobalSearch();
  initializeForms();
  initializeDeleteButtons();
  initializeWidgets();
  initializeSchemaBuilder();
  initializeSchemaView();
});

function initializeGlobalSearch(): void {
  const searchContainer = document.querySelector<HTMLElement>('.admin-nav__search');
  if (!searchContainer) return;

  renderGlobalSearch({
    container: searchContainer,
    onResultClick: (resource: string, id: string) => {
      window.location.href = `/admin/resources/${resource}/${id}`;
    },
  });
}

function initializeForms(): void {
  const forms = document.querySelectorAll<HTMLFormElement>('.admin-form');
  forms.forEach((form) => {
    const action = form.dataset.action;
    const method = form.dataset.method;
    if (!action || !method) return;

    form.addEventListener('submit', async (e) => {
      e.preventDefault();

      const formData = new FormData(form);
      const payload: Record<string, unknown> = {};
      formData.forEach((value, key) => {
        payload[key] = value;
      });

      try {
        const response = await fetch(action, {
          method,
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-Token':
              document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
          },
          body: JSON.stringify(payload),
        });

        const newToken = response.headers.get('X-CSRF-Token');
        if (newToken) {
          const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
          if (meta) meta.content = newToken;
        }

        const result = (await response.json()) as {
          success: boolean;
          message: string;
        };
        if (result.success) {
          const parts = action.split('/');
          const resource = parts[parts.indexOf('resources') + 1];
          window.location.href = `/admin/resources/${resource}`;
        } else {
          showNotification(result.message, 'error');
        }
      } catch (err) {
        showNotification(err instanceof Error ? err.message : 'Request failed', 'error');
      }
    });
  });
}

function initializeDeleteButtons(): void {
  document.querySelectorAll<HTMLElement>('[data-delete]').forEach((btn) => {
    btn.addEventListener('click', async (e) => {
      e.preventDefault();
      const resource = btn.dataset.resource ?? '';
      const id = btn.dataset.id ?? '';
      if (!confirm(`Are you sure you want to delete this ${resource}?`)) return;

      try {
        const result = await api.deleteRecord(resource, id);
        if (result.success) {
          window.location.href = `/admin/resources/${resource}`;
        } else {
          showNotification(result.message, 'error');
        }
      } catch (err) {
        showNotification(err instanceof Error ? err.message : 'Delete failed', 'error');
      }
    });
  });
}

function initializeWidgets(): void {
  const widgetElements = document.querySelectorAll<HTMLElement>('[data-widget-data]');
  widgetElements.forEach((el) => {
    const rawData = el.dataset.widgetData;
    if (!rawData) return;
    try {
      const data = JSON.parse(rawData) as Record<string, unknown>;
      renderWidgetContent(el, data);
    } catch {
      // Widget data parse error, leave as-is
    }
  });
}

function renderWidgetContent(container: HTMLElement, data: Record<string, unknown>): void {
  const resources = (data.resources ?? []) as Array<{
    label: string;
    count: number;
  }>;
  if (resources.length > 0) {
    const list = document.createElement('ul');
    for (const res of resources) {
      const li = document.createElement('li');
      li.textContent = `${res.label}: ${res.count}`;
      list.appendChild(li);
    }
    container.appendChild(list);
  }
}

function showNotification(message: string, type: 'success' | 'error' = 'success'): void {
  const notification = document.createElement('div');
  notification.className = `admin-notification admin-notification--${type}`;
  notification.textContent = message;
  notification.setAttribute('role', 'alert');
  document.body.appendChild(notification);

  setTimeout(() => {
    notification.classList.add('admin-notification--fade');
    setTimeout(() => notification.remove(), 300);
  }, 4000);
}
