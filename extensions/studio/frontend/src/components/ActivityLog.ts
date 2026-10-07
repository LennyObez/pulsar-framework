import type { ActivityLogData, StudioEvent } from '../types.js';

/**
 * Render the activity log page using safe DOM APIs (no innerHTML).
 *
 * All text content uses textContent for XSS safety.
 */
export function renderActivityLog(container: HTMLElement, payload: unknown): void {
  const data = payload as ActivityLogData | null;

  // Clear container safely
  while (container.firstChild) {
    container.removeChild(container.firstChild);
  }

  // Nav
  container.appendChild(buildNav('activity'));

  const dashboard = document.createElement('div');
  dashboard.className = 'dashboard';

  if (!data || data.events.length === 0) {
    dashboard.appendChild(buildEmptyState(data));
    container.appendChild(dashboard);
    return;
  }

  // Header
  const header = document.createElement('div');
  header.className = 'dashboard-header';
  const h1 = document.createElement('h1');
  h1.textContent = 'Activity log';
  const subtitle = document.createElement('p');
  subtitle.textContent = 'System activity across all event types';
  header.appendChild(h1);
  header.appendChild(subtitle);
  dashboard.appendChild(header);

  // Filter bar
  dashboard.appendChild(buildFilterBar(data));

  // Events card
  const card = document.createElement('div');
  card.className = 'card';

  const cardTitle = document.createElement('h3');
  cardTitle.textContent = 'Events (' + String(data.total) + ' total)';
  card.appendChild(cardTitle);

  // Table
  const table = document.createElement('table');
  table.className = 'data-table';

  const thead = document.createElement('thead');
  const headerRow = document.createElement('tr');
  for (const label of ['Time', 'Type', 'Details', 'Request ID']) {
    const th = document.createElement('th');
    th.textContent = label;
    headerRow.appendChild(th);
  }
  thead.appendChild(headerRow);
  table.appendChild(thead);

  const tbody = document.createElement('tbody');
  for (const event of data.events) {
    tbody.appendChild(buildEventRow(event));
  }
  table.appendChild(tbody);
  card.appendChild(table);

  // Pagination
  const pagination = buildPagination(data);
  if (pagination) {
    card.appendChild(pagination);
  }

  dashboard.appendChild(card);
  container.appendChild(dashboard);
}

function buildNav(activePage: string): HTMLElement {
  const nav = document.createElement('nav');
  nav.className = 'studio-nav';

  const brand = document.createElement('a');
  brand.href = '/studio';
  brand.className = 'nav-brand';
  brand.textContent = 'Pulsar Studio';
  nav.appendChild(brand);

  const links = document.createElement('div');
  links.className = 'nav-links';

  const navItems = [
    { href: '/studio/console', label: 'Overview', id: 'overview' },
    { href: '/studio/console/requests', label: 'Requests', id: 'requests' },
    { href: '/studio/console/database', label: 'Database', id: 'database' },
    { href: '/studio/console/logs', label: 'Logs', id: 'logs' },
    { href: '/studio/console/exceptions', label: 'Exceptions', id: 'exceptions' },
    { href: '/studio/console/benchmarks', label: 'Benchmarks', id: 'benchmarks' },
    { href: '/studio/console/activity', label: 'Activity', id: 'activity' },
    { href: '/studio/console/health', label: 'Health', id: 'health' },
    { href: '/studio/console/deployments', label: 'Deploys', id: 'deployments' },
  ];

  for (const item of navItems) {
    const a = document.createElement('a');
    a.href = item.href;
    a.textContent = item.label;
    if (item.id === activePage) {
      a.className = 'active';
    }
    links.appendChild(a);
  }

  nav.appendChild(links);
  return nav;
}

function buildEmptyState(data: ActivityLogData | null): HTMLElement {
  const wrapper = document.createElement('div');

  if (data) {
    wrapper.appendChild(buildFilterBar(data));
  }

  const empty = document.createElement('div');
  empty.className = 'empty-state';

  const h2 = document.createElement('h2');
  h2.textContent = 'No activity events';
  empty.appendChild(h2);

  const p = document.createElement('p');
  if (data && data.type_filter !== '' && data.type_filter !== 'all') {
    p.textContent = 'No events found for type "' + data.type_filter + '". Try clearing the filter.';
  } else {
    p.textContent =
      'No activity events have been recorded yet. Events will appear here as the application processes requests.';
  }
  empty.appendChild(p);

  const link = document.createElement('a');
  link.href = '/studio';
  link.className = 'btn';
  link.textContent = 'Back to Studio';
  empty.appendChild(link);

  wrapper.appendChild(empty);
  return wrapper;
}

function buildFilterBar(data: ActivityLogData): HTMLElement {
  const bar = document.createElement('div');
  bar.className = 'card';
  bar.style.cssText = 'display:flex;gap:1rem;align-items:center;padding:1rem 1.5rem;';

  const label = document.createElement('label');
  label.htmlFor = 'activity-type-filter';
  label.textContent = 'Filter by type:';
  label.style.cssText = 'font-size:var(--text-sm);color:var(--color-text-muted);';
  bar.appendChild(label);

  const select = document.createElement('select');
  select.id = 'activity-type-filter';
  select.className = 'btn';
  select.style.cssText = 'appearance:auto;padding:0.25rem 0.75rem;';

  const allOption = document.createElement('option');
  allOption.value = 'all';
  allOption.textContent = 'All types';
  if (data.type_filter === '' || data.type_filter === 'all') {
    allOption.selected = true;
  }
  select.appendChild(allOption);

  for (const t of data.available_types) {
    const opt = document.createElement('option');
    opt.value = t;
    opt.textContent = formatEventType(t);
    if (t === data.type_filter) {
      opt.selected = true;
    }
    select.appendChild(opt);
  }

  select.addEventListener('change', () => {
    const value = select.value;
    const params = value === 'all' ? '' : '?type=' + encodeURIComponent(value);
    window.location.href = '/studio/console/activity' + params;
  });

  bar.appendChild(select);

  const totalPages = Math.max(1, Math.ceil(data.total / data.limit));
  const pageInfo = document.createElement('span');
  pageInfo.textContent = 'Page ' + String(data.page) + ' of ' + String(totalPages);
  pageInfo.style.cssText = 'margin-left:auto;font-size:0.85rem;color:var(--color-text-disabled);';
  bar.appendChild(pageInfo);

  return bar;
}

function buildEventRow(event: StudioEvent): HTMLTableRowElement {
  const tr = document.createElement('tr');

  // Time cell
  const timeTd = document.createElement('td');
  timeTd.className = 'time-cell';
  timeTd.textContent = new Date(event.timestamp_us / 1000).toLocaleString();
  tr.appendChild(timeTd);

  // Type cell
  const typeTd = document.createElement('td');
  const badge = document.createElement('span');
  badge.className = 'event-type-badge';
  badge.dataset['type'] = event.event_type;
  badge.textContent = event.event_type;
  typeTd.appendChild(badge);
  tr.appendChild(typeTd);

  // Details cell
  const detailsTd = document.createElement('td');
  detailsTd.className = 'details-cell';
  try {
    const payload = JSON.parse(event.payload_json) as Record<string, unknown>;
    populateDetails(detailsTd, event.event_type, payload);
  } catch {
    detailsTd.textContent = '-';
  }
  tr.appendChild(detailsTd);

  // Request ID cell
  const reqTd = document.createElement('td');
  if (event.request_id) {
    const code = document.createElement('code');
    code.textContent = event.request_id.slice(0, 12);
    code.title = event.request_id;
    reqTd.appendChild(code);
  } else {
    const span = document.createElement('span');
    span.style.color = 'var(--color-text-disabled)';
    span.textContent = '-';
    reqTd.appendChild(span);
  }
  tr.appendChild(reqTd);

  return tr;
}

function populateDetails(
  td: HTMLTableCellElement,
  eventType: string,
  payload: Record<string, unknown>,
): void {
  switch (eventType) {
    case 'http.request':
    case 'http.response': {
      const code = document.createElement('code');
      const method = String(payload['method'] ?? payload['request_method'] ?? '');
      const path = String(payload['path'] ?? payload['uri'] ?? '');
      const status = payload['status_code'] ? ' [' + String(payload['status_code']) + ']' : '';
      code.textContent = method + ' ' + path + status;
      td.appendChild(code);
      break;
    }
    case 'db.query': {
      const code = document.createElement('code');
      code.className = 'sql';
      const sql = String(payload['sql'] ?? '');
      code.textContent = sql.slice(0, 80);
      td.appendChild(code);
      if (payload['duration_ms']) {
        const dur = document.createTextNode(
          ' (' + Number(payload['duration_ms']).toFixed(1) + 'ms)',
        );
        td.appendChild(dur);
      }
      break;
    }
    case 'exception': {
      const code = document.createElement('code');
      code.style.color = 'var(--color-danger-400)';
      code.textContent = String(payload['exception_class'] ?? 'Unknown');
      td.appendChild(code);
      break;
    }
    case 'log.entry': {
      const level = String(payload['level'] ?? '');
      const msg = String(payload['message'] ?? '');
      td.textContent = '[' + level.toUpperCase() + '] ' + msg.slice(0, 60);
      break;
    }
    case 'job.queued':
    case 'job.completed':
    case 'job.failed':
      td.textContent = String(payload['job_class'] ?? payload['job_name'] ?? 'Unknown');
      break;
    default:
      td.textContent = '-';
  }
}

function buildPagination(data: ActivityLogData): HTMLElement | null {
  const totalPages = Math.max(1, Math.ceil(data.total / data.limit));

  if (totalPages <= 1) return null;

  const div = document.createElement('div');
  div.className = 'pagination';

  const typeParam =
    data.type_filter && data.type_filter !== 'all'
      ? '&type=' + encodeURIComponent(data.type_filter)
      : '';

  const prev = document.createElement('a');
  prev.className = 'page-link';
  prev.textContent = 'Prev';
  if (data.page <= 1) {
    prev.setAttribute('disabled', '');
  } else {
    prev.href = '?page=' + String(data.page - 1) + typeParam;
  }
  div.appendChild(prev);

  const info = document.createElement('span');
  info.className = 'page-info';
  info.textContent = String(data.page) + ' / ' + String(totalPages);
  div.appendChild(info);

  const next = document.createElement('a');
  next.className = 'page-link';
  next.textContent = 'Next';
  if (data.page >= totalPages) {
    next.setAttribute('disabled', '');
  } else {
    next.href = '?page=' + String(data.page + 1) + typeParam;
  }
  div.appendChild(next);

  return div;
}

function formatEventType(type: string): string {
  return type.replace(/[._]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}
