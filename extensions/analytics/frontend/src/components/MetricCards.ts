import type { AggregateStats } from '../types';

export function renderMetricCards(container: HTMLElement, stats: AggregateStats): void {
  const metrics = [
    { label: 'Unique Visitors', value: formatNumber(stats.visitors), key: 'visitors' },
    { label: 'Total Pageviews', value: formatNumber(stats.pageviews), key: 'pageviews' },
    { label: 'Bounce Rate', value: `${stats.bounce_rate.toFixed(1)}%`, key: 'bounce_rate' },
    { label: 'Avg. Duration', value: formatDuration(stats.avg_duration), key: 'avg_duration' },
    { label: 'Sessions', value: formatNumber(stats.sessions), key: 'sessions' },
    { label: 'Events', value: formatNumber(stats.events_count), key: 'events_count' },
  ];

  container.textContent = '';

  for (const m of metrics) {
    const card = document.createElement('div');
    card.className = 'metric-card';
    card.dataset.metric = m.key;

    const valueEl = document.createElement('div');
    valueEl.className = 'metric-card__value';
    valueEl.textContent = m.value;
    card.appendChild(valueEl);

    const labelEl = document.createElement('div');
    labelEl.className = 'metric-card__label';
    labelEl.textContent = m.label;
    card.appendChild(labelEl);

    container.appendChild(card);
  }
}

function formatNumber(n: number): string {
  if (n >= 1_000_000) return `${(n / 1_000_000).toFixed(1)}M`;
  if (n >= 1_000) return `${(n / 1_000).toFixed(1)}K`;
  return String(n);
}

function formatDuration(seconds: number): string {
  const m = Math.floor(seconds / 60);
  const s = Math.round(seconds % 60);
  return m > 0 ? `${m}m ${s}s` : `${s}s`;
}
