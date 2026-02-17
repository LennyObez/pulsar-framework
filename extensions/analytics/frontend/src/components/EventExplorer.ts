import type { DateRange, EventNameEntry, EventProperty } from '../types';
import { fetchEventProperties, fetchEventTimeseries } from '../api';

/**
 * Interactive custom event explorer.
 *
 * Shows event names with counts, and expands to show properties
 * and a timeseries sparkline when an event is selected.
 */
export function renderEventExplorer(
  container: HTMLElement,
  events: EventNameEntry[],
  siteId: string,
  range: DateRange,
): void {
  container.textContent = '';

  if (events.length === 0) {
    const empty = document.createElement('p');
    empty.className = 'events-empty';
    empty.textContent = 'No custom events recorded in this period';
    container.appendChild(empty);
    return;
  }

  const list = document.createElement('div');
  list.className = 'event-explorer';

  for (const event of events) {
    const item = document.createElement('div');
    item.className = 'event-explorer__item';

    // Header row (clickable)
    const header = document.createElement('button');
    header.className = 'event-explorer__header';
    header.type = 'button';

    const nameEl = document.createElement('span');
    nameEl.className = 'event-explorer__name';
    nameEl.textContent = event.event_name;
    header.appendChild(nameEl);

    const statsEl = document.createElement('span');
    statsEl.className = 'event-explorer__stats';
    statsEl.textContent = `${event.count.toLocaleString()} events \u00B7 ${event.visitors.toLocaleString()} visitors`;
    header.appendChild(statsEl);

    const chevron = document.createElement('span');
    chevron.className = 'event-explorer__chevron';
    chevron.textContent = '\u25B6';
    header.appendChild(chevron);

    item.appendChild(header);

    // Detail panel (initially hidden)
    const detail = document.createElement('div');
    detail.className = 'event-explorer__detail';
    detail.hidden = true;
    item.appendChild(detail);

    // Toggle behavior
    let loaded = false;
    header.addEventListener('click', async () => {
      const isOpen = !detail.hidden;
      detail.hidden = isOpen;
      chevron.textContent = isOpen ? '\u25B6' : '\u25BC';
      item.classList.toggle('event-explorer__item--open', !isOpen);

      if (!isOpen && !loaded) {
        loaded = true;
        detail.textContent = '';

        const loading = document.createElement('span');
        loading.className = 'event-explorer__loading';
        loading.textContent = 'Loading\u2026';
        detail.appendChild(loading);

        try {
          const [propsRes, tsRes] = await Promise.all([
            fetchEventProperties(siteId, range, event.event_name),
            fetchEventTimeseries(siteId, range, event.event_name),
          ]);

          detail.textContent = '';

          // Properties table
          if (propsRes.data.length > 0) {
            renderPropertiesTable(detail, propsRes.data);
          }

          // Sparkline
          if (tsRes.data.length > 0) {
            renderSparkline(detail, tsRes.data);
          }
        } catch {
          detail.textContent = '';
          const err = document.createElement('span');
          err.className = 'event-explorer__error';
          err.textContent = 'Failed to load event details';
          detail.appendChild(err);
        }
      }
    });

    list.appendChild(item);
  }

  container.appendChild(list);
}

function renderPropertiesTable(parent: HTMLElement, properties: EventProperty[]): void {
  const section = document.createElement('div');
  section.className = 'event-props';

  const heading = document.createElement('h4');
  heading.className = 'event-props__title';
  heading.textContent = 'Properties';
  section.appendChild(heading);

  const table = document.createElement('table');
  table.className = 'event-props__table';

  const thead = document.createElement('thead');
  const headerRow = document.createElement('tr');
  for (const col of ['Property', 'Value', 'Count']) {
    const th = document.createElement('th');
    th.textContent = col;
    headerRow.appendChild(th);
  }
  thead.appendChild(headerRow);
  table.appendChild(thead);

  const tbody = document.createElement('tbody');
  for (const prop of properties.slice(0, 20)) {
    const row = document.createElement('tr');

    const propCell = document.createElement('td');
    propCell.textContent = prop.property;
    row.appendChild(propCell);

    const valCell = document.createElement('td');
    valCell.textContent = prop.value;
    row.appendChild(valCell);

    const countCell = document.createElement('td');
    countCell.textContent = prop.count.toLocaleString();
    row.appendChild(countCell);

    tbody.appendChild(row);
  }
  table.appendChild(tbody);
  section.appendChild(table);
  parent.appendChild(section);
}

function renderSparkline(parent: HTMLElement, data: Array<{ date: string; count: number }>): void {
  const canvas = document.createElement('canvas');
  canvas.className = 'event-sparkline';
  parent.appendChild(canvas);

  const dpr = window.devicePixelRatio || 1;
  const w = 300;
  const h = 60;

  canvas.width = w * dpr;
  canvas.height = h * dpr;
  canvas.style.width = `${w}px`;
  canvas.style.height = `${h}px`;

  const ctx = canvas.getContext('2d');
  if (!ctx) return;

  ctx.scale(dpr, dpr);

  const isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
  const values = data.map((d) => d.count);
  const maxVal = Math.max(...values, 1);
  const pad = 4;

  ctx.beginPath();
  ctx.strokeStyle = isDark ? '#818cf8' : '#6366f1';
  ctx.lineWidth = 1.5;

  for (let i = 0; i < data.length; i++) {
    const x = pad + ((w - pad * 2) * i) / (data.length - 1 || 1);
    const y = pad + (h - pad * 2) * (1 - values[i] / maxVal);
    if (i === 0) ctx.moveTo(x, y);
    else ctx.lineTo(x, y);
  }

  ctx.stroke();
}
