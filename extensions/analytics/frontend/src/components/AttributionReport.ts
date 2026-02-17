import type { AttributionEntry } from '../types';

/**
 * Renders an attribution report showing traffic source contributions
 * across different attribution models, with comparison support.
 */
export function renderAttributionReport(
  container: HTMLElement,
  model: string,
  data: AttributionEntry[],
): void {
  container.textContent = '';

  if (data.length === 0) {
    const empty = document.createElement('p');
    empty.className = 'attribution-empty';
    empty.textContent = 'No attribution data for this period';
    container.appendChild(empty);
    return;
  }

  const wrapper = document.createElement('div');
  wrapper.className = 'attribution-report';

  // Model label
  const header = document.createElement('div');
  header.className = 'attribution-header';

  const modelLabel = document.createElement('span');
  modelLabel.className = 'attribution-header__model';
  modelLabel.textContent = `Model: ${formatModelName(model)}`;
  header.appendChild(modelLabel);

  wrapper.appendChild(header);

  // Summary bar chart
  const maxConversions = Math.max(...data.map((d) => d.conversions), 1);
  const chartSection = document.createElement('div');
  chartSection.className = 'attribution-chart';

  for (const entry of data) {
    const row = document.createElement('div');
    row.className = 'attribution-bar-row';

    const label = document.createElement('span');
    label.className = 'attribution-bar__label';
    label.textContent = entry.source;
    row.appendChild(label);

    const barWrap = document.createElement('div');
    barWrap.className = 'attribution-bar__wrap';

    const bar = document.createElement('div');
    bar.className = 'attribution-bar__fill';
    bar.style.width = `${((entry.conversions / maxConversions) * 100).toFixed(1)}%`;
    barWrap.appendChild(bar);

    row.appendChild(barWrap);

    const value = document.createElement('span');
    value.className = 'attribution-bar__value';
    value.textContent = entry.conversions.toLocaleString();
    row.appendChild(value);

    chartSection.appendChild(row);
  }

  wrapper.appendChild(chartSection);

  // Detail table
  const table = document.createElement('table');
  table.className = 'attribution-table';

  const thead = document.createElement('thead');
  const headerRow = document.createElement('tr');
  for (const col of ['Source', 'Conversions', 'Revenue', 'Weight']) {
    const th = document.createElement('th');
    th.textContent = col;
    headerRow.appendChild(th);
  }
  thead.appendChild(headerRow);
  table.appendChild(thead);

  const tbody = document.createElement('tbody');
  const totalConversions = data.reduce((a, d) => a + d.conversions, 0);

  for (const entry of data) {
    const row = document.createElement('tr');

    const sourceCell = document.createElement('td');
    sourceCell.textContent = entry.source;
    row.appendChild(sourceCell);

    const convCell = document.createElement('td');
    convCell.textContent = entry.conversions.toLocaleString();
    row.appendChild(convCell);

    const revCell = document.createElement('td');
    revCell.textContent = formatRevenue(entry.revenue);
    row.appendChild(revCell);

    const weightCell = document.createElement('td');
    weightCell.textContent = `${(entry.weight * 100).toFixed(1)}%`;
    row.appendChild(weightCell);

    tbody.appendChild(row);
  }

  // Total row
  const totalRow = document.createElement('tr');
  totalRow.className = 'attribution-table__total';

  const totalLabel = document.createElement('td');
  totalLabel.textContent = 'Total';
  totalRow.appendChild(totalLabel);

  const totalConv = document.createElement('td');
  totalConv.textContent = totalConversions.toLocaleString();
  totalRow.appendChild(totalConv);

  const totalRev = document.createElement('td');
  totalRev.textContent = formatRevenue(data.reduce((a, d) => a + d.revenue, 0));
  totalRow.appendChild(totalRev);

  const totalWeight = document.createElement('td');
  totalWeight.textContent = '100.0%';
  totalRow.appendChild(totalWeight);

  tbody.appendChild(totalRow);
  table.appendChild(tbody);
  wrapper.appendChild(table);

  container.appendChild(wrapper);
}

/**
 * Renders a comparison of multiple attribution models side-by-side.
 */
export function renderAttributionComparison(
  container: HTMLElement,
  data: Record<string, AttributionEntry[]>,
): void {
  container.textContent = '';

  const models = Object.keys(data);
  if (models.length === 0) {
    const empty = document.createElement('p');
    empty.className = 'attribution-empty';
    empty.textContent = 'No attribution data for comparison';
    container.appendChild(empty);
    return;
  }

  const wrapper = document.createElement('div');
  wrapper.className = 'attribution-comparison';

  const heading = document.createElement('h3');
  heading.className = 'attribution-comparison__title';
  heading.textContent = 'Model Comparison';
  wrapper.appendChild(heading);

  // Collect all sources across models
  const allSources = new Set<string>();
  for (const entries of Object.values(data)) {
    for (const entry of entries) {
      allSources.add(entry.source);
    }
  }

  // Comparison table
  const table = document.createElement('table');
  table.className = 'attribution-comparison__table';

  const thead = document.createElement('thead');
  const headerRow = document.createElement('tr');

  const sourceHeader = document.createElement('th');
  sourceHeader.textContent = 'Source';
  headerRow.appendChild(sourceHeader);

  for (const model of models) {
    const th = document.createElement('th');
    th.textContent = formatModelName(model);
    headerRow.appendChild(th);
  }
  thead.appendChild(headerRow);
  table.appendChild(thead);

  const tbody = document.createElement('tbody');
  for (const source of allSources) {
    const row = document.createElement('tr');

    const srcCell = document.createElement('td');
    srcCell.textContent = source;
    row.appendChild(srcCell);

    for (const model of models) {
      const entry = data[model]?.find((e) => e.source === source);
      const cell = document.createElement('td');
      cell.textContent = entry ? entry.conversions.toLocaleString() : '0';
      row.appendChild(cell);
    }

    tbody.appendChild(row);
  }

  table.appendChild(tbody);
  wrapper.appendChild(table);
  container.appendChild(wrapper);
}

function formatModelName(model: string): string {
  return model
    .split('_')
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
    .join(' ');
}

function formatRevenue(amount: number): string {
  if (amount >= 1_000_000) return `$${(amount / 1_000_000).toFixed(1)}M`;
  if (amount >= 1_000) return `$${(amount / 1_000).toFixed(1)}K`;
  return `$${amount.toFixed(2)}`;
}
