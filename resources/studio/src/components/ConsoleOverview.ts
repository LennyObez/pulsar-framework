import type { DashboardData } from '../types.js';

export function renderConsoleOverview(container: HTMLElement, payload: unknown): void {
  const data = payload as DashboardData;

  if (!data || !data.sections) {
    container.innerHTML = '<p class="empty-state">No dashboard data available.</p>';
    return;
  }

  const html = `
    <nav class="studio-nav">
      <a href="/studio" class="nav-brand">Pulsar Studio</a>
      <div class="nav-links">
        <a href="/studio/console" class="active">Overview</a>
        ${data.sections.includes('http.request') ? '<a href="/studio/console/requests">Requests</a>' : ''}
        ${data.sections.includes('db.query') ? '<a href="/studio/console/database">Database</a>' : ''}
        ${data.sections.includes('log.entry') ? '<a href="/studio/console/logs">Logs</a>' : ''}
        ${data.sections.includes('exception') ? '<a href="/studio/console/exceptions">Exceptions</a>' : ''}
      </div>
    </nav>
    <div class="dashboard">
      <div class="metrics-row">
        ${renderMetricCard('Throughput', `${data.throughput.per_minute.toFixed(1)}/min`, 'Total: ' + String(data.throughput.total))}
        ${renderMetricCard('P50 Latency', `${data.latency.p50.toFixed(0)}ms`, '')}
        ${renderMetricCard('P95 Latency', `${data.latency.p95.toFixed(0)}ms`, '')}
        ${renderMetricCard('P99 Latency', `${data.latency.p99.toFixed(0)}ms`, '')}
        ${renderMetricCard('Errors', `${data.error_rate.per_minute.toFixed(1)}/min`, 'Total: ' + String(data.error_rate.total))}
      </div>
      ${renderStatusBreakdown(data.throughput.by_status)}
      ${renderSlowRoutes(data.slow_routes)}
      ${data.sections.includes('db.query') ? renderSlowQueries(data.slow_queries) : ''}
      ${renderEventCounts(data.event_counts)}
      ${renderTopExceptions(data.error_rate.top_exceptions)}
    </div>
  `;

  container.innerHTML = html;
}

function renderMetricCard(label: string, value: string, subtitle: string): string {
  return `
    <div class="metric-card">
      <div class="metric-label">${label}</div>
      <div class="metric-value">${value}</div>
      ${subtitle ? `<div class="metric-subtitle">${subtitle}</div>` : ''}
    </div>
  `;
}

function renderStatusBreakdown(byStatus: Record<string, number>): string {
  const entries = Object.entries(byStatus);
  if (entries.length === 0) return '';

  return `
    <div class="card">
      <h3>Status Breakdown</h3>
      <div class="status-grid">
        ${entries
          .map(
            ([status, count]) => `
          <div class="status-item status-${status}">
            <span class="status-code">${status}</span>
            <span class="status-count">${String(count)}</span>
          </div>
        `,
          )
          .join('')}
      </div>
    </div>
  `;
}

function renderSlowRoutes(routes: DashboardData['slow_routes']): string {
  if (routes.length === 0) return '';

  return `
    <div class="card">
      <h3>Slow Routes (P95)</h3>
      <table class="data-table">
        <thead>
          <tr><th>Route</th><th>P95</th><th>Avg</th><th>Count</th></tr>
        </thead>
        <tbody>
          ${routes
            .map(
              (r) => `
            <tr>
              <td><code>${r.route}</code></td>
              <td>${r.p95_ms.toFixed(0)}ms</td>
              <td>${r.avg_ms.toFixed(0)}ms</td>
              <td>${String(r.count)}</td>
            </tr>
          `,
            )
            .join('')}
        </tbody>
      </table>
    </div>
  `;
}

function renderSlowQueries(queries: DashboardData['slow_queries']): string {
  if (queries.length === 0) return '';

  return `
    <div class="card">
      <h3>Slow Queries (P95)</h3>
      <table class="data-table">
        <thead>
          <tr><th>SQL</th><th>P95</th><th>Avg</th><th>Count</th></tr>
        </thead>
        <tbody>
          ${queries
            .map(
              (q) => `
            <tr>
              <td><code class="sql">${q.sql}</code></td>
              <td>${q.p95_ms.toFixed(0)}ms</td>
              <td>${q.avg_ms.toFixed(0)}ms</td>
              <td>${String(q.count)}</td>
            </tr>
          `,
            )
            .join('')}
        </tbody>
      </table>
    </div>
  `;
}

function renderEventCounts(counts: Record<string, number>): string {
  const entries = Object.entries(counts);
  if (entries.length === 0) return '';

  return `
    <div class="card">
      <h3>Event Counts</h3>
      <div class="event-counts">
        ${entries
          .map(
            ([type, count]) => `
          <div class="event-count-item">
            <span class="event-type-badge">${type}</span>
            <span class="event-count">${String(count)}</span>
          </div>
        `,
          )
          .join('')}
      </div>
    </div>
  `;
}

function renderTopExceptions(exceptions: DashboardData['error_rate']['top_exceptions']): string {
  if (exceptions.length === 0) return '';

  return `
    <div class="card">
      <h3>Top Exceptions</h3>
      <table class="data-table">
        <thead>
          <tr><th>Class</th><th>Count</th><th>Last Seen</th></tr>
        </thead>
        <tbody>
          ${exceptions
            .map(
              (e) => `
            <tr>
              <td><code>${e.class}</code></td>
              <td>${String(e.count)}</td>
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

function formatTimestamp(us: number): string {
  return new Date(us / 1000).toLocaleString();
}
