import type { DashboardData } from '../types.js';
import { escapeHtml } from '../utils/escapeHtml.js';
import { renderStudioNav } from './Nav.js';
import { renderSparkline } from '../utils/sparkline.js';

export function renderConsoleOverview(container: HTMLElement, payload: unknown): void {
  const data = payload as DashboardData | null;

  if (!data || !data.sections) {
    container.innerHTML = `
      ${renderStudioNav('overview')}
      <div class="empty-state">
        <h2>No Dashboard Data</h2>
        <p>No observability data available yet. Start your application to begin collecting events.</p>
        <a href="/studio" class="btn">Back to Studio</a>
      </div>
    `;
    return;
  }

  container.innerHTML = `
    ${renderStudioNav('overview')}
    <div class="dashboard">
      <div class="dashboard-header">
        <h1>Console overview</h1>
        <p>See your observability data in one place</p>
      </div>
      <div class="metrics-row">
        ${renderMetricCard('Throughput', formatValue(data.throughput?.per_minute), '/min', 'Total: ' + String(data.throughput?.total ?? 0), renderSparkline(data.throughput_series ?? [], { color: 'var(--studio-accent)' }))}
        ${renderMetricCard('P50 Latency', formatMs(data.latency?.p50), 'ms', '', '')}
        ${renderMetricCard('P95 Latency', formatMs(data.latency?.p95), 'ms', '', '')}
        ${renderMetricCard('P99 Latency', formatMs(data.latency?.p99), 'ms', '', '')}
        ${renderMetricCard('Errors', formatValue(data.error_rate?.per_minute), '/min', 'Total: ' + String(data.error_rate?.total ?? 0), renderSparkline(data.error_series ?? [], { color: 'var(--studio-red)' }))}
      </div>
      ${renderStatusBreakdown(data.throughput?.by_status)}
      ${renderSlowRoutes(data.slow_routes)}
      ${data.sections.includes('slow_queries') ? renderSlowQueries(data.slow_queries) : ''}
      ${renderEventCounts(data.event_counts)}
      ${renderTopExceptions(data.error_rate?.top_exceptions)}
    </div>
  `;
}

function renderMetricCard(
  label: string,
  value: string,
  unit: string,
  subtitle: string,
  sparkline: string,
): string {
  return `
    <div class="metric-card">
      <div class="metric-label">${escapeHtml(label)}</div>
      <div class="metric-value">${escapeHtml(value)}<span class="metric-unit">${escapeHtml(unit)}</span></div>
      ${sparkline ? `<div class="metric-sparkline">${sparkline}</div>` : ''}
      ${subtitle ? `<div class="metric-subtitle">${escapeHtml(subtitle)}</div>` : ''}
    </div>
  `;
}

function renderStatusBreakdown(byStatus: Record<string, number> | undefined): string {
  if (!byStatus) return '';

  const entries = Object.entries(byStatus);
  if (entries.length === 0) return '';

  const total = entries.reduce((sum, [, count]) => sum + count, 0);
  if (total === 0) return '';

  const statusOrder = ['2xx', '3xx', '4xx', '5xx'];
  const sortedEntries = statusOrder
    .filter((status) => byStatus[status] !== undefined && byStatus[status] > 0)
    .map((status) => [status, byStatus[status]] as [string, number]);

  return `
    <div class="card">
      <h3>Status breakdown</h3>
      <div class="status-bar-container">
        <div class="status-bar-row">
          ${sortedEntries
            .map(
              ([status, count]) =>
                `<div class="status-bar-segment status-${escapeHtml(status)}" style="flex: ${String(count)}"></div>`,
            )
            .join('')}
        </div>
        <div class="status-legend">
          ${sortedEntries
            .map(
              ([status, count]) => `
            <div class="status-legend-item">
              <div class="status-legend-dot status-${escapeHtml(status)}"></div>
              <span>${escapeHtml(status)}: ${String(count)}</span>
            </div>
          `,
            )
            .join('')}
        </div>
      </div>
    </div>
  `;
}

function renderSlowRoutes(routes: DashboardData['slow_routes'] | undefined): string {
  if (!routes || routes.length === 0) return '';

  const maxP95 = Math.max(...routes.map((r) => r.p95_ms ?? 0), 1);

  return `
    <div class="card">
      <h3>Slow routes (P95)</h3>
      <table class="data-table">
        <thead>
          <tr><th>Route</th><th>P95</th><th>Avg</th><th>Count</th></tr>
        </thead>
        <tbody>
          ${routes
            .map((r) => {
              const p95 = r.p95_ms ?? 0;
              const percentage = (p95 / maxP95) * 100;
              return `
              <tr>
                <td><code>${escapeHtml(r.route ?? '')}</code></td>
                <td class="bar-cell">
                  <div class="inline-bar" style="width: ${String(percentage)}%"></div>
                  <span>${formatMs(p95)}ms</span>
                </td>
                <td>${formatMs(r.avg_ms)}ms</td>
                <td>${String(r.count ?? 0)}</td>
              </tr>
            `;
            })
            .join('')}
        </tbody>
      </table>
    </div>
  `;
}

function renderSlowQueries(queries: DashboardData['slow_queries'] | undefined): string {
  if (!queries || queries.length === 0) return '';

  const maxP95 = Math.max(...queries.map((q) => q.p95_ms ?? 0), 1);

  return `
    <div class="card">
      <h3>Slow queries (P95)</h3>
      <table class="data-table">
        <thead>
          <tr><th>SQL</th><th>P95</th><th>Avg</th><th>Count</th></tr>
        </thead>
        <tbody>
          ${queries
            .map((q) => {
              const p95 = q.p95_ms ?? 0;
              const percentage = (p95 / maxP95) * 100;
              return `
              <tr>
                <td><code class="sql">${escapeHtml(q.sql ?? '')}</code></td>
                <td class="bar-cell">
                  <div class="inline-bar" style="width: ${String(percentage)}%"></div>
                  <span>${formatMs(p95)}ms</span>
                </td>
                <td>${formatMs(q.avg_ms)}ms</td>
                <td>${String(q.count ?? 0)}</td>
              </tr>
            `;
            })
            .join('')}
        </tbody>
      </table>
    </div>
  `;
}

function renderEventCounts(counts: Record<string, number> | undefined): string {
  if (!counts) return '';

  const entries = Object.entries(counts);
  if (entries.length === 0) return '';

  const maxCount = Math.max(...entries.map(([, count]) => count), 1);

  return `
    <div class="card">
      <h3>Event counts</h3>
      <div class="event-bar-chart">
        ${entries
          .map(([type, count]) => {
            const percentage = (count / maxCount) * 100;
            return `
            <div class="event-bar-row">
              <span class="event-type-badge">${escapeHtml(type)}</span>
              <div class="event-bar" style="width: ${String(percentage)}%"></div>
              <span class="event-bar-value">${String(count)}</span>
            </div>
          `;
          })
          .join('')}
      </div>
    </div>
  `;
}

function renderTopExceptions(
  exceptions: DashboardData['error_rate']['top_exceptions'] | undefined,
): string {
  if (!exceptions || exceptions.length === 0) return '';

  return `
    <div class="card">
      <h3>Top exceptions</h3>
      <table class="data-table">
        <thead>
          <tr><th>Class</th><th>Count</th><th>Last Seen</th></tr>
        </thead>
        <tbody>
          ${exceptions
            .map(
              (e) => `
            <tr>
              <td><code>${escapeHtml(e.class ?? '')}</code></td>
              <td>${String(e.count ?? 0)}</td>
              <td>${formatTimestamp(e.last_seen_us)}</td>
            </tr>
          `,
            )
            .join('')}
        </tbody>
      </table>
    </div>
  `;
}

function formatTimestamp(us: number | undefined): string {
  if (us === undefined || us === 0) return '-';
  return new Date(us / 1000).toLocaleString();
}

function formatValue(value: number | undefined): string {
  if (value === undefined || value === null) return '0';
  return value.toFixed(1);
}

function formatMs(value: number | undefined): string {
  if (value === undefined || value === null) return '0';
  return value.toFixed(0);
}
