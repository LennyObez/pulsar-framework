import type { MemorySnapshotEntry, LeakReportData } from '../types.js';
import { renderSparkline } from '../utils/sparkline.js';

/**
 * Build a memory profiler panel element using safe DOM APIs.
 *
 * Designed for embedding inside other dashboard views (e.g., Health Dashboard).
 * Shows memory usage trend, peak tracking, and leak detection status.
 */
export function buildMemoryProfilerPanel(
  snapshots: MemorySnapshotEntry[],
  leakReport: LeakReportData | null,
): HTMLElement {
  const panel = document.createElement('div');
  panel.className = 'card';

  const title = document.createElement('h3');
  title.textContent = 'Memory profiler';
  panel.appendChild(title);

  if (snapshots.length === 0) {
    const empty = document.createElement('p');
    empty.textContent =
      'No memory snapshots available. Snapshots are recorded in persistent runtimes (RoadRunner, FrankenPHP).';
    empty.style.cssText = 'color:var(--color-text-muted);font-size:var(--text-sm);';
    panel.appendChild(empty);
    return panel;
  }

  // Usage sparkline
  const sparklineSection = document.createElement('div');
  sparklineSection.style.marginBottom = '1rem';

  const sparklineLabel = document.createElement('div');
  sparklineLabel.className = 'metric-label';
  sparklineLabel.textContent = 'Memory usage over time';
  sparklineSection.appendChild(sparklineLabel);

  const sparklineWrap = document.createElement('div');
  sparklineWrap.className = 'metric-sparkline';
  sparklineWrap.style.height = '48px';

  const usageData = snapshots.map((s) => s.usage_bytes);
  const svgStr = renderSparkline(usageData, { width: 500, height: 48, color: 'var(--ext-accent)' });
  const parser = new DOMParser();
  const svgDoc = parser.parseFromString(svgStr, 'image/svg+xml');
  const svgEl = svgDoc.documentElement;
  svgEl.style.width = '100%';
  sparklineWrap.appendChild(document.importNode(svgEl, true));
  sparklineSection.appendChild(sparklineWrap);
  panel.appendChild(sparklineSection);

  // Peak sparkline
  const peakSection = document.createElement('div');
  peakSection.style.marginBottom = '1rem';

  const peakLabel = document.createElement('div');
  peakLabel.className = 'metric-label';
  peakLabel.textContent = 'Peak memory over time';
  peakSection.appendChild(peakLabel);

  const peakWrap = document.createElement('div');
  peakWrap.className = 'metric-sparkline';
  peakWrap.style.height = '48px';

  const peakData = snapshots.map((s) => s.peak_bytes);
  const peakSvgStr = renderSparkline(peakData, {
    width: 500,
    height: 48,
    color: 'var(--studio-type-scheduler)',
  });
  const peakSvgDoc = parser.parseFromString(peakSvgStr, 'image/svg+xml');
  const peakSvgEl = peakSvgDoc.documentElement;
  peakSvgEl.style.width = '100%';
  peakWrap.appendChild(document.importNode(peakSvgEl, true));
  peakSection.appendChild(peakWrap);
  panel.appendChild(peakSection);

  // Summary stats
  const statsRow = document.createElement('div');
  statsRow.className = 'metrics-row';

  const first = snapshots[0];
  const last = snapshots[snapshots.length - 1];
  const growth = last.usage_bytes - first.usage_bytes;

  statsRow.appendChild(buildMiniStat('Current', formatBytes(last.usage_bytes)));
  statsRow.appendChild(buildMiniStat('Peak', formatBytes(last.peak_bytes)));
  statsRow.appendChild(buildMiniStat('Samples', String(snapshots.length)));
  statsRow.appendChild(buildMiniStat('Growth', (growth >= 0 ? '+' : '') + formatBytes(growth)));

  panel.appendChild(statsRow);

  // Leak detection
  if (leakReport) {
    panel.appendChild(buildLeakStatus(leakReport));
  }

  return panel;
}

function buildMiniStat(label: string, value: string): HTMLElement {
  const card = document.createElement('div');
  card.className = 'metric-card';
  card.style.padding = 'var(--space-4)';

  const labelEl = document.createElement('div');
  labelEl.className = 'metric-label';
  labelEl.textContent = label;

  const valueEl = document.createElement('div');
  valueEl.className = 'metric-value';
  valueEl.style.fontSize = 'var(--text-xl)';
  valueEl.textContent = value;

  card.appendChild(labelEl);
  card.appendChild(valueEl);
  return card;
}

function buildLeakStatus(report: LeakReportData): HTMLElement {
  const section = document.createElement('div');
  section.style.marginTop = '1rem';

  if (report.suspected) {
    const warning = document.createElement('div');
    warning.className = 'error';
    warning.textContent =
      'Potential memory leak detected: approximately ' +
      formatBytes(report.growth_per_request_bytes) +
      ' growth per request over ' +
      String(report.sample_count) +
      ' samples. Total growth: ' +
      formatBytes(report.total_growth_bytes) +
      '.';
    section.appendChild(warning);
  } else {
    const ok = document.createElement('div');
    ok.style.cssText =
      'padding:var(--space-4);background:rgb(34 197 94 / 0.1);border:1px solid rgb(34 197 94 / 0.3);border-radius:var(--radius-md);color:var(--color-success-400);font-size:var(--text-sm);';
    ok.textContent = 'No memory leak pattern detected. Memory usage is stable.';
    section.appendChild(ok);
  }

  return section;
}

function formatBytes(bytes: number): string {
  const abs = Math.abs(bytes);
  const sign = bytes < 0 ? '-' : '';
  if (abs < 1024) return sign + String(abs) + ' B';
  if (abs < 1048576) return sign + (abs / 1024).toFixed(1) + ' KB';
  if (abs < 1073741824) return sign + (abs / 1048576).toFixed(1) + ' MB';
  return sign + (abs / 1073741824).toFixed(2) + ' GB';
}
