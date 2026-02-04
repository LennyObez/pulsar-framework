import type { BenchmarkDashboardData, BenchmarkProfile, BenchmarkRun } from '../types.js';
import { fetchJson, postAction } from '../api.js';
import { escapeHtml } from '../utils/escapeHtml.js';

export function renderBenchmarkDashboard(container: HTMLElement, payload: unknown): void {
  const data = payload as BenchmarkDashboardData | null;

  if (!data || (data.runs.length === 0 && data.latest_profiles.length === 0)) {
    renderEmptyState(container);
    return;
  }

  container.innerHTML = `
    ${renderNav()}
    <div class="dashboard">
      <div class="dashboard-header">
        <h1>Benchmark Dashboard</h1>
        <p>Performance benchmarks across configurations</p>
      </div>
      ${renderActionBar()}
      ${renderLatestRunSummary(data.runs[0], data.latest_profiles)}
      ${renderProfileCharts(data.latest_profiles)}
      ${renderProfileTable(data.latest_profiles, data.runs[0])}
      ${renderRunHistory(data.runs)}
    </div>
  `;

  wireEvents(container);
}

function renderNav(): string {
  return `
    <nav class="studio-nav">
      <a href="/studio" class="nav-brand">Pulsar Studio</a>
      <div class="nav-links">
        <a href="/studio/console">Overview</a>
        <a href="/studio/console/benchmarks" class="active">Benchmarks</a>
      </div>
    </nav>
  `;
}

function renderEmptyState(container: HTMLElement): void {
  container.innerHTML = `
    ${renderNav()}
    <div class="dashboard">
      ${renderActionBar()}
      <div class="empty-state">
        <h2>No Benchmark Data</h2>
        <p>No benchmark data yet. Click <strong>Run Benchmarks</strong> or run:</p>
        <pre><code>php bin/pulsar studio:console:bench</code></pre>
        <a href="/studio" class="btn">Back to Studio</a>
      </div>
    </div>
  `;

  wireEvents(container);
}

function renderActionBar(): string {
  return `
    <div class="card" style="display:flex;gap:1rem;align-items:center;padding:1rem 1.5rem;">
      <button id="bench-run" class="btn btn-primary">Run Benchmarks</button>
      <button id="bench-clear" class="btn btn-danger">Clear All History</button>
      <span id="bench-status" style="margin-left:auto;font-size:0.85rem;color:var(--studio-muted);"></span>
    </div>
  `;
}

function renderLatestRunSummary(
  run: BenchmarkRun | undefined,
  profiles: BenchmarkProfile[],
): string {
  if (!run) return '';

  return `
    <div class="metrics-row">
      <div class="metric-card">
        <div class="metric-label">Profiles</div>
        <div class="metric-value">${String(run.profile_count)}</div>
        <div class="metric-subtitle">Benchmark configurations</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">Successes</div>
        <div class="metric-value">${String(run.success_count)}</div>
        <div class="metric-subtitle">${formatRunSubtitle(run)}</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">PHP Version</div>
        <div class="metric-value">${escapeHtml(run.php_version)}</div>
        <div class="metric-subtitle">Runtime</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">Duration</div>
        <div class="metric-value">${formatDuration(run.total_duration_ms)}</div>
        <div class="metric-subtitle">Total run time</div>
      </div>
      ${profiles.length > 0 ? renderBestRps(profiles) : ''}
    </div>
  `;
}

function renderBestRps(profiles: BenchmarkProfile[]): string {
  const best = profiles.reduce((a, b) => (a.rps > b.rps ? a : b));
  return `
    <div class="metric-card">
      <div class="metric-label">Best RPS</div>
      <div class="metric-value">${best.rps.toLocaleString()}</div>
      <div class="metric-subtitle">${escapeHtml(best.profile_name)}</div>
    </div>
  `;
}

// ---------------------------------------------------------------------------
// Profile Comparison Charts
// ---------------------------------------------------------------------------

interface ChartMetric {
  label: string;
  key: string;
  extract: (p: BenchmarkProfile) => number;
  format: (v: number) => string;
  higherIsBetter: boolean;
}

const CHART_METRICS: ChartMetric[] = [
  {
    label: 'Cold Boot',
    key: 'boot',
    extract: (p) => p.boot_us,
    format: formatUs,
    higherIsBetter: false,
  },
  {
    label: 'Warm Boot',
    key: 'warm',
    extract: (p) => p.warm_boot_us,
    format: formatUs,
    higherIsBetter: false,
  },
  {
    label: 'p50 Latency',
    key: 'p50',
    extract: (p) => p.p50_us,
    format: formatUs,
    higherIsBetter: false,
  },
  {
    label: 'p95 Latency',
    key: 'p95',
    extract: (p) => p.p95_us,
    format: formatUs,
    higherIsBetter: false,
  },
  {
    label: 'Requests / sec',
    key: 'rps',
    extract: (p) => p.rps,
    format: (v) => v.toLocaleString(),
    higherIsBetter: true,
  },
  {
    label: 'Memory (RSS)',
    key: 'rss',
    extract: (p) => p.peak_rss_kb,
    format: formatKb,
    higherIsBetter: false,
  },
];

/** Color palette for profile bars — alternates distinct hues. */
const BAR_COLORS = [
  'var(--studio-accent)',
  'var(--studio-green)',
  'var(--studio-purple)',
  'var(--studio-orange)',
  'var(--studio-cyan)',
  'var(--studio-pink)',
  'var(--studio-yellow)',
  'var(--studio-blue)',
  'var(--studio-red)',
  '#6ee7b7',
  '#fbbf24',
  '#c084fc',
];

function renderProfileCharts(profiles: BenchmarkProfile[]): string {
  if (profiles.length < 2) return '';

  const legend = profiles
    .map((p, i) => {
      const color = BAR_COLORS[i % BAR_COLORS.length];
      const opt = p.optimize_enabled ? ' \u2713' : '';
      return `<span class="chart-legend-item"><span class="chart-legend-dot" style="background:${color};"></span><code>${escapeHtml(p.profile_name)}${opt}</code></span>`;
    })
    .join('');

  const charts = CHART_METRICS.map((m) => renderMetricChart(profiles, m)).join('');

  return `
    <div class="card">
      <h3>Profile Comparison</h3>
      <div class="chart-legend">${legend}</div>
      <div class="chart-grid">${charts}</div>
    </div>
  `;
}

function renderMetricChart(profiles: BenchmarkProfile[], metric: ChartMetric): string {
  const values = profiles.map((p) => metric.extract(p));
  const maxVal = Math.max(...values, 1);

  const bars = profiles
    .map((p, i) => {
      const val = metric.extract(p);
      const pct = (val / maxVal) * 100;
      const color = BAR_COLORS[i % BAR_COLORS.length];
      const isBest = metric.higherIsBetter
        ? val === Math.max(...values)
        : val === Math.min(...values);
      const rowClass = isBest ? 'chart-bar-row chart-bar-best' : 'chart-bar-row';

      return `
      <div class="${rowClass}">
        <span class="chart-bar-label" title="${escapeHtml(p.profile_name)}">${escapeHtml(truncateProfile(p.profile_name))}</span>
        <div class="chart-bar-track">
          <div class="chart-bar-fill" style="width:${String(Math.max(2, pct))}%;background:${color};"></div>
        </div>
        <span class="chart-bar-value">${metric.format(val)}</span>
      </div>`;
    })
    .join('');

  return `
    <div class="chart-metric">
      <div class="chart-metric-title">${metric.label}${metric.higherIsBetter ? ' \u2191' : ' \u2193'}</div>
      ${bars}
    </div>
  `;
}

function truncateProfile(name: string): string {
  return name.length > 18 ? name.slice(0, 16) + '\u2026' : name;
}

function renderProfileTable(profiles: BenchmarkProfile[], run: BenchmarkRun | undefined): string {
  if (profiles.length === 0) return '';

  const platformNote =
    run && run.skipped_count > 0
      ? `<p style="margin-top:0.75rem;font-size:0.85rem;color:var(--studio-amber);">${String(run.skipped_count)} profile${run.skipped_count > 1 ? 's' : ''} skipped &mdash; OPcache preloading requires Linux/macOS.</p>`
      : '';

  return `
    <div class="card">
      <h3>Latest Profile Comparison</h3>
      <table class="data-table">
        <thead>
          <tr>
            <th>Profile</th>
            <th>Opt</th>
            <th>Boot</th>
            <th>Warm</th>
            <th>p50</th>
            <th>p95</th>
            <th>RPS</th>
            <th>Alloc</th>
            <th>RSS</th>
            <th>OPC</th>
          </tr>
        </thead>
        <tbody>
          ${profiles.map((p) => renderProfileRow(p)).join('')}
        </tbody>
      </table>
      ${platformNote}
    </div>
  `;
}

function renderProfileRow(p: BenchmarkProfile): string {
  return `
    <tr>
      <td><code>${escapeHtml(p.profile_name)}</code></td>
      <td>${p.optimize_enabled ? '\u2713' : '-'}</td>
      <td>${formatUs(p.boot_us)}</td>
      <td>${formatUs(p.warm_boot_us)}</td>
      <td>${formatUs(p.p50_us)}</td>
      <td>${formatUs(p.p95_us)}</td>
      <td>${p.rps.toLocaleString()}</td>
      <td>${formatKb(p.memory_usage_kb)}</td>
      <td>${formatKb(p.peak_rss_kb)}</td>
      <td>${p.opcache_memory_kb !== null ? formatKb(p.opcache_memory_kb) : '-'}</td>
    </tr>
  `;
}

function renderRunHistory(runs: BenchmarkRun[]): string {
  if (runs.length === 0) return '';

  return `
    <div class="card">
      <h3>Run History</h3>
      <table class="data-table">
        <thead>
          <tr>
            <th><input type="checkbox" id="bench-select-all"></th>
            <th>Run ID</th>
            <th>Profiles</th>
            <th>Result</th>
            <th>Duration</th>
            <th>PHP</th>
            <th>Timestamp</th>
          </tr>
        </thead>
        <tbody>
          ${runs.map((r) => renderRunRow(r)).join('')}
        </tbody>
      </table>
      <div style="margin-top:0.75rem;">
        <button id="bench-delete-selected" class="btn btn-danger" disabled>Delete Selected</button>
      </div>
    </div>
  `;
}

function renderRunRow(r: BenchmarkRun): string {
  const truncated = r.run_id.length > 12 ? r.run_id.slice(0, 12) + '\u2026' : r.run_id;
  const result = `${String(r.success_count)}/${String(r.profile_count)}`;
  const ts = new Date(r.timestamp_us / 1000).toLocaleString();

  let resultSuffix = '';
  if (r.skipped_count > 0 && r.failure_count > 0) {
    resultSuffix = ` <span style="color:var(--studio-amber);">(${String(r.skipped_count)} skipped, ${String(r.failure_count)} failed)</span>`;
  } else if (r.skipped_count > 0) {
    resultSuffix = ` <span style="color:var(--studio-amber);">(${String(r.skipped_count)} skipped)</span>`;
  } else if (r.failure_count > 0) {
    resultSuffix = ` <span style="color:var(--studio-red);">(${String(r.failure_count)} failed)</span>`;
  }

  return `
    <tr data-run-id="${escapeHtml(r.run_id)}">
      <td><input type="checkbox" class="bench-run-check" value="${escapeHtml(r.run_id)}"></td>
      <td><code title="${escapeHtml(r.run_id)}">${escapeHtml(truncated)}</code></td>
      <td>${String(r.profile_count)}</td>
      <td>${escapeHtml(result)}${resultSuffix}</td>
      <td>${formatDuration(r.total_duration_ms)}</td>
      <td>${escapeHtml(r.php_version)}</td>
      <td>${escapeHtml(ts)}</td>
    </tr>
  `;
}

function wireEvents(container: HTMLElement): void {
  const runBtn = container.querySelector<HTMLButtonElement>('#bench-run');
  const clearBtn = container.querySelector<HTMLButtonElement>('#bench-clear');
  const deleteBtn = container.querySelector<HTMLButtonElement>('#bench-delete-selected');
  const selectAll = container.querySelector<HTMLInputElement>('#bench-select-all');
  const statusEl = container.querySelector<HTMLSpanElement>('#bench-status');

  runBtn?.addEventListener('click', async () => {
    runBtn.disabled = true;
    setStatus(statusEl, 'Starting benchmarks\u2026');

    try {
      await postAction<{ started: boolean }>('/benchmark/run');
      pollBenchmarkStatus(runBtn, statusEl);
    } catch (e) {
      setStatus(statusEl, `Error: ${e instanceof Error ? e.message : 'Unknown error'}`);
      runBtn.disabled = false;
    }
  });

  clearBtn?.addEventListener('click', async () => {
    if (!confirm('Delete all benchmark history? This cannot be undone.')) return;

    clearBtn.disabled = true;
    setStatus(statusEl, 'Clearing history\u2026');

    try {
      await postAction<{ deleted: number }>('/benchmark/clear');
      window.location.reload();
    } catch (e) {
      setStatus(statusEl, `Error: ${e instanceof Error ? e.message : 'Unknown error'}`);
      clearBtn.disabled = false;
    }
  });

  selectAll?.addEventListener('change', () => {
    const checks = container.querySelectorAll<HTMLInputElement>('.bench-run-check');
    for (const cb of checks) {
      cb.checked = selectAll.checked;
    }
    updateDeleteButton(container, deleteBtn);
  });

  container.addEventListener('change', (e) => {
    if ((e.target as HTMLElement).classList.contains('bench-run-check')) {
      updateDeleteButton(container, deleteBtn);
    }
  });

  deleteBtn?.addEventListener('click', async () => {
    const checked = container.querySelectorAll<HTMLInputElement>('.bench-run-check:checked');
    const runIds = Array.from(checked).map((cb) => cb.value);

    if (runIds.length === 0) return;

    deleteBtn.disabled = true;
    setStatus(statusEl, 'Deleting runs\u2026');

    try {
      await postAction<{ deleted: number }>('/benchmark/delete', { run_ids: runIds });

      for (const id of runIds) {
        const row = container.querySelector(`tr[data-run-id="${id}"]`);
        row?.remove();
      }

      setStatus(statusEl, 'Deleted successfully.');
      updateDeleteButton(container, deleteBtn);
    } catch (e) {
      setStatus(statusEl, `Error: ${e instanceof Error ? e.message : 'Unknown error'}`);
      deleteBtn.disabled = false;
    }
  });
}

function updateDeleteButton(
  container: HTMLElement,
  btn: HTMLButtonElement | null | undefined,
): void {
  if (!btn) return;
  const checked = container.querySelectorAll<HTMLInputElement>('.bench-run-check:checked');
  btn.disabled = checked.length === 0;
}

function setStatus(el: HTMLSpanElement | null | undefined, msg: string): void {
  if (el) el.textContent = msg;
}

function formatRunSubtitle(run: BenchmarkRun): string {
  const parts: string[] = [];
  if (run.skipped_count > 0) parts.push(`${String(run.skipped_count)} skipped`);
  if (run.failure_count > 0) parts.push(`${String(run.failure_count)} failed`);
  return parts.length > 0 ? parts.join(', ') : 'All passed';
}

interface BenchmarkStatus {
  running: boolean;
  completed: boolean;
  success?: boolean;
  exit_code?: number;
  output?: string;
}

function pollBenchmarkStatus(
  runBtn: HTMLButtonElement,
  statusEl: HTMLSpanElement | null | undefined,
): void {
  let elapsed = 0;
  const interval = 2000; // poll every 2 seconds

  const timer = setInterval(async () => {
    elapsed += interval;
    const seconds = Math.floor(elapsed / 1000);
    setStatus(statusEl, `Running benchmarks\u2026 (${String(seconds)}s)`);

    try {
      const status = await fetchJson<BenchmarkStatus>('/benchmark/status');

      if (status.completed) {
        clearInterval(timer);
        if (status.success) {
          setStatus(statusEl, 'Benchmarks complete. Reloading\u2026');
          window.location.reload();
        } else {
          setStatus(statusEl, `Benchmark failed (exit code ${String(status.exit_code ?? '?')})`);
          runBtn.disabled = false;
        }
      }

      // If not running and not completed, something went wrong
      if (!status.running && !status.completed) {
        clearInterval(timer);
        setStatus(statusEl, 'Benchmark process ended unexpectedly.');
        runBtn.disabled = false;
      }
    } catch {
      // Network error during poll — keep trying
    }
  }, interval);
}

function formatUs(us: number): string {
  if (us < 1000) return `${String(us)}\u00B5s`;
  if (us < 1_000_000) return `${(us / 1000).toFixed(1)}ms`;
  return `${(us / 1_000_000).toFixed(2)}s`;
}

function formatKb(kb: number): string {
  if (kb < 1024) return `${String(kb)} KB`;
  return `${(kb / 1024).toFixed(1)} MB`;
}

function formatDuration(ms: number): string {
  if (ms < 1000) return `${ms.toFixed(0)}ms`;
  return `${(ms / 1000).toFixed(1)}s`;
}
