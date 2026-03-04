import type { HealthDashboardData, MemorySnapshotEntry } from '../types.js';
import { renderSparkline } from '../utils/sparkline.js';

/**
 * Render the system health dashboard using safe DOM APIs (no innerHTML).
 */
export function renderHealthDashboard(container: HTMLElement, payload: unknown): void {
  const data = payload as HealthDashboardData | null;

  while (container.firstChild) {
    container.removeChild(container.firstChild);
  }

  container.appendChild(buildNav('health'));

  const dashboard = document.createElement('div');
  dashboard.className = 'dashboard';

  if (!data) {
    const empty = document.createElement('div');
    empty.className = 'empty-state';
    const h2 = document.createElement('h2');
    h2.textContent = 'Health data unavailable';
    const p = document.createElement('p');
    p.textContent = 'Could not load system health data.';
    empty.appendChild(h2);
    empty.appendChild(p);
    dashboard.appendChild(empty);
    container.appendChild(dashboard);
    return;
  }

  // Header
  const header = document.createElement('div');
  header.className = 'dashboard-header';
  const h1 = document.createElement('h1');
  h1.textContent = 'System health';
  const subtitle = document.createElement('p');
  subtitle.textContent =
    data.system.hostname + ' / ' + data.system.os + ' / PHP ' + data.system.php_version;
  header.appendChild(h1);
  header.appendChild(subtitle);
  dashboard.appendChild(header);

  // Metric cards row
  dashboard.appendChild(buildMetricsRow(data));

  // Memory trend card
  if (data.memory_snapshots.length > 0) {
    dashboard.appendChild(buildMemoryTrendCard(data.memory_snapshots, data.leak_report));
  }

  // Queue status card
  dashboard.appendChild(buildQueueCard(data));

  // Event store card
  dashboard.appendChild(buildStoreCard(data));

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

function buildMetricsRow(data: HealthDashboardData): HTMLElement {
  const row = document.createElement('div');
  row.className = 'metrics-row';

  // Memory usage
  row.appendChild(
    buildMetricCard(
      'Memory Usage',
      formatBytes(data.memory.usage_bytes),
      'Limit: ' + data.memory.limit,
    ),
  );

  // Memory peak
  row.appendChild(
    buildMetricCard('Memory Peak', formatBytes(data.memory.peak_bytes), 'Highest recorded'),
  );

  // Disk usage
  row.appendChild(
    buildMetricCard(
      'Disk Used',
      String(data.disk.used_percent) + '%',
      formatBytes(data.disk.free_bytes) + ' free',
    ),
  );

  // Queue pending
  const queueStatus = data.queue.failed > 0 ? data.queue.failed + ' failed' : 'All healthy';
  row.appendChild(buildMetricCard('Queue Pending', String(data.queue.pending), queueStatus));

  return row;
}

function buildMetricCard(label: string, value: string, sub: string): HTMLElement {
  const card = document.createElement('div');
  card.className = 'metric-card';

  const labelEl = document.createElement('div');
  labelEl.className = 'metric-label';
  labelEl.textContent = label;

  const valueEl = document.createElement('div');
  valueEl.className = 'metric-value';
  valueEl.textContent = value;

  const subEl = document.createElement('div');
  subEl.className = 'metric-subtitle';
  subEl.textContent = sub;

  card.appendChild(labelEl);
  card.appendChild(valueEl);
  card.appendChild(subEl);
  return card;
}

function buildMemoryTrendCard(
  snapshots: MemorySnapshotEntry[],
  leakReport: HealthDashboardData['leak_report'],
): HTMLElement {
  const card = document.createElement('div');
  card.className = 'card';

  const title = document.createElement('h3');
  title.textContent = 'Memory trend';
  card.appendChild(title);

  // Sparkline of memory usage
  const usageData = snapshots.map((s) => s.usage_bytes);
  const sparklineContainer = document.createElement('div');
  sparklineContainer.className = 'metric-sparkline';
  sparklineContainer.style.height = '60px';

  // Use the sparkline utility -- it returns an SVG string, insert via DOM parser
  const svgStr = renderSparkline(usageData, { width: 600, height: 60, color: 'var(--ext-accent)' });
  const parser = new DOMParser();
  const svgDoc = parser.parseFromString(svgStr, 'image/svg+xml');
  const svgEl = svgDoc.documentElement;
  svgEl.style.width = '100%';
  sparklineContainer.appendChild(document.importNode(svgEl, true));
  card.appendChild(sparklineContainer);

  // Summary row
  const summary = document.createElement('div');
  summary.style.cssText =
    'display:flex;gap:2rem;margin-top:1rem;font-size:var(--text-sm);color:var(--color-text-muted);';

  const first = snapshots[0];
  const last = snapshots[snapshots.length - 1];

  appendStat(summary, 'Start', formatBytes(first.usage_bytes));
  appendStat(summary, 'Current', formatBytes(last.usage_bytes));
  appendStat(summary, 'Samples', String(snapshots.length));

  if (last.usage_bytes > first.usage_bytes) {
    appendStat(summary, 'Growth', formatBytes(last.usage_bytes - first.usage_bytes));
  }

  card.appendChild(summary);

  // Leak warning
  if (leakReport && leakReport.suspected) {
    const warning = document.createElement('div');
    warning.className = 'error';
    warning.style.marginTop = '1rem';
    warning.textContent =
      'Potential memory leak detected: ~' +
      formatBytes(leakReport.growth_per_request_bytes) +
      '/request growth over ' +
      String(leakReport.sample_count) +
      ' samples. Total growth: ' +
      formatBytes(leakReport.total_growth_bytes);
    card.appendChild(warning);
  }

  return card;
}

function appendStat(parent: HTMLElement, label: string, value: string): void {
  const span = document.createElement('span');
  const labelEl = document.createElement('strong');
  labelEl.textContent = label + ': ';
  span.appendChild(labelEl);
  span.appendChild(document.createTextNode(value));
  parent.appendChild(span);
}

function buildQueueCard(data: HealthDashboardData): HTMLElement {
  const card = document.createElement('div');
  card.className = 'card';

  const title = document.createElement('h3');
  title.textContent = 'Queue status';
  card.appendChild(title);

  const grid = document.createElement('div');
  grid.className = 'metrics-row';

  grid.appendChild(buildMetricCard('Pending', String(data.queue.pending), 'Waiting to process'));
  grid.appendChild(
    buildMetricCard('Completed', String(data.queue.completed), 'Successfully processed'),
  );
  grid.appendChild(
    buildMetricCard(
      'Failed',
      String(data.queue.failed),
      data.queue.failed > 0 ? 'Requires attention' : 'No failures',
    ),
  );

  card.appendChild(grid);
  return card;
}

function buildStoreCard(data: HealthDashboardData): HTMLElement {
  const card = document.createElement('div');
  card.className = 'card';

  const title = document.createElement('h3');
  title.textContent = 'Event store';
  card.appendChild(title);

  const grid = document.createElement('div');
  grid.className = 'metrics-row';

  grid.appendChild(
    buildMetricCard('Total Events', formatNumber(data.event_store.total_events), 'In storage'),
  );
  grid.appendChild(
    buildMetricCard('Storage Size', formatBytes(data.event_store.size_bytes), 'On disk'),
  );

  card.appendChild(grid);
  return card;
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) return String(bytes) + ' B';
  if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
  if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' MB';
  return (bytes / 1073741824).toFixed(2) + ' GB';
}

function formatNumber(n: number): string {
  if (n < 1000) return String(n);
  if (n < 1000000) return (n / 1000).toFixed(1) + 'K';
  return (n / 1000000).toFixed(1) + 'M';
}
