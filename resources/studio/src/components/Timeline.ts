import type { TimelineData, StudioEvent } from '../types.js';

export function renderTimeline(container: HTMLElement, payload: unknown): void {
  const data = payload as TimelineData | null;

  if (!data || !data.events || data.events.length === 0) {
    container.innerHTML = `
      <nav class="studio-nav">
        <a href="/studio" class="nav-brand">Pulsar Studio</a>
        <div class="nav-links">
          <a href="/studio/console">Overview</a>
        </div>
      </nav>
      <div class="empty-state">
        <h2>No Timeline Data</h2>
        <p>No events found for this correlation ID.</p>
        <a href="/studio/console">Back to Console</a>
      </div>
    `;
    return;
  }

  const firstTs = data.events[0]?.timestamp_us ?? 0;
  const lastTs = data.events[data.events.length - 1]?.timestamp_us ?? firstTs;
  const totalDuration = lastTs - firstTs;

  container.innerHTML = `
    <nav class="studio-nav">
      <a href="/studio" class="nav-brand">Pulsar Studio</a>
      <div class="nav-links">
        <a href="/studio/console">Overview</a>
      </div>
    </nav>
    <div class="timeline-view">
      <div class="timeline-header">
        <h2>Timeline: ${data.correlation_id.substring(0, 16)}...</h2>
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
        <div class="timeline-time">${time} (+${formatDuration(offset)})</div>
        <div class="timeline-type">
          <span class="event-type-badge">${event.event_type}</span>
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
        return `${String(payload['method'] ?? '')} ${String(payload['path'] ?? '')}`;
      case 'http.response':
        return `Status ${String(payload['status_code'] ?? '')} in ${String(payload['duration_ms'] ?? '?')}ms`;
      case 'db.query':
        return `<code>${truncate(String(payload['sql'] ?? ''), 120)}</code> in ${String(payload['duration_ms'] ?? '?')}ms`;
      case 'log.entry':
        return `[${String(payload['level'] ?? '')}] ${truncate(String(payload['message'] ?? ''), 120)}`;
      case 'exception':
        return `${String(payload['class'] ?? '')}: ${truncate(String(payload['message'] ?? ''), 120)}`;
      case 'scheduler.run':
        return `Job: ${String(payload['job_name'] ?? '')} — ${String(payload['outcome'] ?? '')}`;
      case 'feature_flag.eval':
        return `Flag: ${String(payload['flag_name'] ?? '')} = ${String(payload['value'] ?? '')}`;
      default:
        return event.event_type;
    }
  } catch {
    return event.event_type;
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
  if (us < 1000) return `${String(us)}μs`;
  if (us < 1_000_000) return `${(us / 1000).toFixed(1)}ms`;
  return `${(us / 1_000_000).toFixed(2)}s`;
}

function truncate(str: string, maxLen: number): string {
  if (str.length <= maxLen) return str;
  return str.substring(0, maxLen) + '...';
}
