import type { TimelineData, StudioEvent } from '../types.js';
import { escapeHtml } from '../utils/escapeHtml.js';
import { renderStudioNav } from './Nav.js';

export function renderTimeline(container: HTMLElement, payload: unknown): void {
  const data = payload as TimelineData | null;

  if (!data || !data.events || data.events.length === 0) {
    container.innerHTML = `
      ${renderStudioNav('requests')}
      <div class="empty-state">
        <h2>No Timeline Data</h2>
        <p>No events found for this correlation ID.</p>
        <a href="/studio/console" class="btn">Back to Console</a>
      </div>
    `;
    return;
  }

  const correlationDisplay = data.correlation_id
    ? escapeHtml(data.correlation_id.substring(0, 16)) + '...'
    : 'Unknown';

  const firstTs = data.events[0]?.timestamp_us ?? 0;
  const lastTs = data.events[data.events.length - 1]?.timestamp_us ?? firstTs;
  const totalDuration = lastTs - firstTs;

  container.innerHTML = `
    ${renderStudioNav('requests')}
    <div class="timeline-view">
      <div class="timeline-header">
        <h2>Timeline: ${correlationDisplay}</h2>
        <span class="timeline-meta">${String(data.events.length)} events, ${formatDuration(totalDuration)}</span>
      </div>
      <div class="timeline">
        ${data.events.map((event, index) => renderTimelineEntry(event, index, firstTs, totalDuration)).join('')}
      </div>
    </div>
  `;
}

function renderTimelineEntry(
  event: StudioEvent,
  index: number,
  firstTs: number,
  totalDuration: number,
): string {
  const offset = event.timestamp_us - firstTs;
  const position = totalDuration > 0 ? (offset / totalDuration) * 100 : 0;
  const time = new Date(event.timestamp_us / 1000).toLocaleTimeString(undefined, {
    hour12: false,
    fractionalSecondDigits: 3,
  });
  const details = extractTimelineDetails(event);

  return `
    <div class="timeline-entry" style="--position: ${String(position)}%">
      <div class="timeline-marker ${getTypeClass(event.event_type)}"></div>
      <div class="timeline-content">
        <div class="timeline-time">${escapeHtml(time)} (+${formatDuration(offset)})</div>
        <div class="timeline-type">
          <span class="event-type-badge">${escapeHtml(event.event_type)}</span>
          <span class="timeline-index">#${String(index + 1)}</span>
        </div>
        <div class="timeline-details">${details}</div>
      </div>
    </div>
  `;
}

function extractTimelineDetails(event: StudioEvent): string {
  try {
    const payload = JSON.parse(event.payload_json) as Record<string, unknown>;

    switch (event.event_type) {
      case 'http.request':
        return `${escapeHtml(String(payload['method'] ?? ''))} ${escapeHtml(String(payload['path'] ?? ''))}`;
      case 'http.response':
        return `Status ${escapeHtml(String(payload['status_code'] ?? ''))} in ${escapeHtml(String(payload['duration_ms'] ?? '?'))}ms`;
      case 'db.query':
        return `<code>${escapeHtml(truncate(String(payload['sql'] ?? ''), 120))}</code> in ${escapeHtml(String(payload['duration_ms'] ?? '?'))}ms`;
      case 'log.entry':
        return `[${escapeHtml(String(payload['level'] ?? ''))}] ${escapeHtml(truncate(String(payload['message'] ?? ''), 120))}`;
      case 'exception':
        return `${escapeHtml(String(payload['exception_class'] ?? ''))}: ${escapeHtml(truncate(String(payload['message'] ?? ''), 120))}`;
      case 'scheduler.run':
        return `Job: ${escapeHtml(String(payload['job_name'] ?? ''))} — ${escapeHtml(String(payload['status'] ?? ''))}`;
      case 'feature_flag.eval':
        return `Flag: ${escapeHtml(String(payload['flag_name'] ?? ''))} = ${escapeHtml(String(payload['result'] ?? ''))}`;
      default:
        return escapeHtml(event.event_type);
    }
  } catch {
    return escapeHtml(event.event_type);
  }
}

function getTypeClass(eventType: string): string {
  if (eventType.startsWith('http.')) return 'type-http';
  if (eventType.startsWith('db.')) return 'type-db';
  if (eventType === 'exception') return 'type-error';
  if (eventType === 'log.entry') return 'type-log';
  if (eventType.startsWith('scheduler.')) return 'type-scheduler';
  if (eventType.startsWith('feature_flag.')) return 'type-flag';
  return 'type-default';
}

function formatDuration(us: number): string {
  if (us < 1000) return `${String(us)}us`;
  if (us < 1_000_000) return `${(us / 1000).toFixed(1)}ms`;
  return `${(us / 1_000_000).toFixed(2)}s`;
}

function truncate(str: string, maxLen: number): string {
  if (str.length <= maxLen) return str;
  return str.substring(0, maxLen) + '...';
}
