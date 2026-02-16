/**
 * Dashboard widgets renderer.
 */
import type { WidgetData } from '../types.js';

export function renderDashboardWidgets(container: HTMLElement, widgets: WidgetData[]): void {
  container.innerHTML = '';

  const grid = document.createElement('div');
  grid.className = 'admin-widgets';

  for (const widget of widgets) {
    const card = document.createElement('div');
    card.className = `admin-widget admin-widget--${widget.size}`;

    const title = document.createElement('h3');
    title.className = 'admin-widget__title';
    title.textContent = widget.label;
    card.appendChild(title);

    const content = document.createElement('div');
    content.className = 'admin-widget__content';

    if (widget.id === 'resource_count') {
      renderResourceCountWidget(content, widget.data);
    } else if (widget.id === 'recent_activity') {
      renderRecentActivityWidget(content, widget.data);
    } else {
      content.textContent = JSON.stringify(widget.data);
    }

    card.appendChild(content);
    grid.appendChild(card);
  }

  container.appendChild(grid);
}

function renderResourceCountWidget(container: HTMLElement, data: Record<string, unknown>): void {
  const resources = (data.resources ?? []) as Array<{
    name: string;
    label: string;
    icon: string;
    count: number;
  }>;

  const list = document.createElement('ul');
  list.className = 'admin-widget__list';

  for (const res of resources) {
    const li = document.createElement('li');
    li.innerHTML = `<span class="admin-widget__icon">${escapeHtml(res.icon)}</span>
      <span class="admin-widget__label">${escapeHtml(res.label)}</span>
      <span class="admin-widget__count">${res.count}</span>`;
    list.appendChild(li);
  }

  container.appendChild(list);
}

function renderRecentActivityWidget(container: HTMLElement, data: Record<string, unknown>): void {
  const entries = (data.entries ?? []) as Array<{
    action: string;
    resource: string;
    actor: string;
    timestamp: number;
    success: boolean;
  }>;

  const list = document.createElement('ul');
  list.className = 'admin-widget__activity';

  for (const entry of entries.slice(0, 10)) {
    const li = document.createElement('li');
    const time = new Date(entry.timestamp * 1000).toLocaleString();
    const status = entry.success ? 'ok' : 'fail';

    const statusSpan = document.createElement('span');
    statusSpan.className = `admin-activity__status admin-activity__status--${status}`;

    const actionSpan = document.createElement('span');
    actionSpan.className = 'admin-activity__action';
    actionSpan.textContent = entry.action;

    const resourceSpan = document.createElement('span');
    resourceSpan.className = 'admin-activity__resource';
    resourceSpan.textContent = entry.resource;

    const actorSpan = document.createElement('span');
    actorSpan.className = 'admin-activity__actor';
    actorSpan.textContent = entry.actor;

    const timeEl = document.createElement('time');
    timeEl.textContent = time;

    li.append(statusSpan, actionSpan, resourceSpan, actorSpan, timeEl);
    list.appendChild(li);
  }

  container.appendChild(list);
}

function escapeHtml(text: string): string {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}
