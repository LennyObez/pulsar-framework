import type { PageType, StudioEvent } from '../types.js';
import { LiveManager } from '../live.js';

interface ExplorerPayload {
  events: StudioEvent[];
}

const PAGE_TITLES: Record<string, string> = {
  'request-explorer': 'HTTP Requests',
  'database-explorer': 'Database Queries',
  'log-explorer': 'Logs',
  'exception-explorer': 'Exceptions',
};

const PAGE_TYPES: Record<string, string[]> = {
  'request-explorer': ['http.request', 'http.response'],
  'database-explorer': ['db.query'],
  'log-explorer': ['log.entry'],
  'exception-explorer': ['exception'],
};

export function renderEventTable(container: HTMLElement, page: PageType, payload: unknown): void {
  const data = payload as ExplorerPayload | null;
  const events = data?.events ?? [];
  const title = PAGE_TITLES[page] ?? 'Events';
  const eventTypes = PAGE_TYPES[page] ?? [];

  const liveEvents: StudioEvent[] = [...events];
  let live: LiveManager | null = null;
  let isLive = false;

  function render(): void {
    container.innerHTML = `
      <nav class="studio-nav">
        <a href="/studio" class="nav-brand">Pulsar Studio</a>
        <div class="nav-links">
          <a href="/studio/console">Overview</a>
          <a href="/studio/console/requests" ${page === 'request-explorer' ? 'class="active"' : ''}>Requests</a>
          <a href="/studio/console/database" ${page === 'database-explorer' ? 'class="active"' : ''}>Database</a>
          <a href="/studio/console/logs" ${page === 'log-explorer' ? 'class="active"' : ''}>Logs</a>
          <a href="/studio/console/exceptions" ${page === 'exception-explorer' ? 'class="active"' : ''}>Exceptions</a>
        </div>
      </nav>
      <div class="explorer">
        <div class="explorer-header">
          <h2>${title}</h2>
          <div class="live-controls">
            <button id="live-toggle" class="btn ${isLive ? 'btn-active' : ''}">${isLive ? 'Pause' : 'Live'}</button>
            ${live && live.isPaused() ? `<span class="badge">${String(live.bufferedCount())} buffered</span>` : ''}
          </div>
        </div>
        <table class="data-table">
          <thead>
            <tr>
              <th>Time</th>
              <th>Type</th>
              <th>Request ID</th>
              <th>Details</th>
            </tr>
          </thead>
          <tbody>
            ${liveEvents.map((e) => renderEventRow(e)).join('')}
          </tbody>
        </table>
        ${liveEvents.length === 0 ? '<p class="empty-state">No events recorded yet.</p>' : ''}
      </div>
    `;

    const toggleBtn = document.getElementById('live-toggle');
    toggleBtn?.addEventListener('click', toggleLive);
  }

  function toggleLive(): void {
    if (!live) {
      live = new LiveManager({
        types: eventTypes,
        onEvent: (event: StudioEvent) => {
          liveEvents.unshift(event);
          if (liveEvents.length > 200) {
            liveEvents.pop();
          }
          render();
        },
      });
    }

    if (isLive) {
      live.pause();
      isLive = false;
    } else {
      if (live.isPaused()) {
        live.resume();
      } else {
        live.start();
      }
      isLive = true;
    }
    render();
  }

  render();
}

function renderEventRow(event: StudioEvent): string {
  const time = new Date(event.timestamp_us / 1000).toLocaleTimeString();
  const requestId = event.request_id ? event.request_id.substring(0, 12) + '...' : '-';
  const details = extractDetails(event);

  return `
    <tr>
      <td class="time-cell">${time}</td>
      <td><span class="event-type-badge">${event.event_type}</span></td>
      <td>
        ${event.request_id ? `<a href="/studio/console/timeline/${event.request_id}" class="correlation-link">${requestId}</a>` : '-'}
      </td>
      <td class="details-cell">${details}</td>
    </tr>
  `;
}

function extractDetails(event: StudioEvent): string {
  try {
    const payload = JSON.parse(event.payload_json) as Record<string, unknown>;

    switch (event.event_type) {
      case 'http.request':
        return `${String(payload['method'] ?? '')} ${String(payload['path'] ?? '')}`;
      case 'http.response':
        return `${String(payload['status_code'] ?? '')} (${String(payload['duration_ms'] ?? '?')}ms)`;
      case 'db.query':
        return `<code>${truncate(String(payload['sql'] ?? ''), 80)}</code> (${String(payload['duration_ms'] ?? '?')}ms)`;
      case 'log.entry':
        return `[${String(payload['level'] ?? '')}] ${truncate(String(payload['message'] ?? ''), 100)}`;
      case 'exception':
        return `${String(payload['class'] ?? '')} — ${truncate(String(payload['message'] ?? ''), 80)}`;
      default:
        return event.event_type;
    }
  } catch {
    return event.event_type;
  }
}

function truncate(str: string, maxLen: number): string {
  if (str.length <= maxLen) return str;
  return str.substring(0, maxLen) + '...';
}
