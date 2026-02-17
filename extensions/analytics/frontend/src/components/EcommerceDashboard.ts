import type { EcommerceSummary, RevenuePoint, TopProduct } from '../types';

/**
 * Renders a full e-commerce analytics dashboard with summary metrics,
 * top products table, and a revenue timeseries chart.
 */
export function renderEcommerceDashboard(
  container: HTMLElement,
  summary: EcommerceSummary,
  products: TopProduct[],
  revenue: RevenuePoint[],
): void {
  container.textContent = '';

  // Summary cards
  const cards = document.createElement('div');
  cards.className = 'ecom-cards';

  const summaryMetrics = [
    { label: 'Revenue', value: formatCurrency(summary.revenue, summary.currency) },
    { label: 'Transactions', value: summary.transactions.toLocaleString() },
    {
      label: 'Avg Order Value',
      value: formatCurrency(summary.average_order_value, summary.currency),
    },
    { label: 'Conversion Rate', value: `${summary.conversion_rate.toFixed(2)}%` },
    { label: 'Items Sold', value: summary.items_sold.toLocaleString() },
  ];

  for (const m of summaryMetrics) {
    const card = document.createElement('div');
    card.className = 'ecom-card';

    const valueEl = document.createElement('div');
    valueEl.className = 'ecom-card__value';
    valueEl.textContent = m.value;
    card.appendChild(valueEl);

    const labelEl = document.createElement('div');
    labelEl.className = 'ecom-card__label';
    labelEl.textContent = m.label;
    card.appendChild(labelEl);

    cards.appendChild(card);
  }

  container.appendChild(cards);

  // Top products table
  if (products.length > 0) {
    const section = document.createElement('div');
    section.className = 'ecom-products';

    const heading = document.createElement('h3');
    heading.className = 'ecom-section__title';
    heading.textContent = 'Top Products';
    section.appendChild(heading);

    const table = document.createElement('table');
    table.className = 'ecom-products__table';

    const thead = document.createElement('thead');
    const headerRow = document.createElement('tr');
    for (const col of ['Product', 'Revenue', 'Quantity']) {
      const th = document.createElement('th');
      th.textContent = col;
      headerRow.appendChild(th);
    }
    thead.appendChild(headerRow);
    table.appendChild(thead);

    const tbody = document.createElement('tbody');
    for (const product of products) {
      const row = document.createElement('tr');

      const nameCell = document.createElement('td');
      nameCell.textContent = product.name;
      row.appendChild(nameCell);

      const revenueCell = document.createElement('td');
      revenueCell.textContent = formatCurrency(product.revenue, summary.currency);
      row.appendChild(revenueCell);

      const qtyCell = document.createElement('td');
      qtyCell.textContent = product.quantity.toLocaleString();
      row.appendChild(qtyCell);

      tbody.appendChild(row);
    }
    table.appendChild(tbody);
    section.appendChild(table);
    container.appendChild(section);
  }

  // Revenue chart
  if (revenue.length > 0) {
    const chartSection = document.createElement('div');
    chartSection.className = 'ecom-revenue-chart';

    const chartTitle = document.createElement('h3');
    chartTitle.className = 'ecom-section__title';
    chartTitle.textContent = 'Revenue Over Time';
    chartSection.appendChild(chartTitle);

    const canvas = document.createElement('canvas');
    canvas.className = 'ecom-revenue-canvas';
    chartSection.appendChild(canvas);
    container.appendChild(chartSection);

    drawRevenueChart(canvas, revenue);
  }
}

function drawRevenueChart(canvas: HTMLCanvasElement, data: RevenuePoint[]): void {
  const parent = canvas.parentElement;
  if (!parent) return;

  const rect = parent.getBoundingClientRect();
  const dpr = window.devicePixelRatio || 1;
  const w = rect.width;
  const h = 240;

  canvas.width = w * dpr;
  canvas.height = h * dpr;
  canvas.style.width = `${w}px`;
  canvas.style.height = `${h}px`;

  const ctx = canvas.getContext('2d');
  if (!ctx) return;

  ctx.scale(dpr, dpr);

  const isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
  const pad = { top: 20, right: 20, bottom: 40, left: 70 };
  const plotW = w - pad.left - pad.right;
  const plotH = h - pad.top - pad.bottom;

  const values = data.map((d) => d.revenue);
  const maxVal = Math.max(...values, 1);

  // Grid
  const gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
  const textColor = isDark ? 'rgba(255,255,255,0.5)' : 'rgba(0,0,0,0.5)';

  ctx.strokeStyle = gridColor;
  ctx.lineWidth = 1;
  ctx.font = '11px system-ui, -apple-system, sans-serif';
  ctx.fillStyle = textColor;
  ctx.textAlign = 'right';

  for (let i = 0; i <= 4; i++) {
    const y = pad.top + plotH - (plotH * i) / 4;
    ctx.beginPath();
    ctx.moveTo(pad.left, y);
    ctx.lineTo(w - pad.right, y);
    ctx.stroke();
    ctx.fillText(formatCompact((maxVal * i) / 4), pad.left - 8, y + 4);
  }

  // Bars
  const barColor = isDark ? '#34d399' : '#10b981';
  const barWidth = Math.max(plotW / data.length - 4, 2);

  for (let i = 0; i < data.length; i++) {
    const x = pad.left + (plotW * i) / data.length + 2;
    const barH = (data[i].revenue / maxVal) * plotH;
    const y = pad.top + plotH - barH;

    ctx.fillStyle = barColor;
    ctx.fillRect(x, y, barWidth, barH);
  }

  // X labels
  ctx.fillStyle = textColor;
  ctx.textAlign = 'center';
  const labelInterval = Math.max(1, Math.floor(data.length / 7));
  for (let i = 0; i < data.length; i += labelInterval) {
    const x = pad.left + (plotW * i) / data.length + barWidth / 2;
    ctx.fillText(data[i].date.slice(5), x, h - pad.bottom + 20);
  }
}

function formatCurrency(amount: number, currency: string): string {
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(amount);
  } catch {
    return `${currency} ${amount.toFixed(2)}`;
  }
}

function formatCompact(n: number): string {
  if (n >= 1_000_000) return `${(n / 1_000_000).toFixed(1)}M`;
  if (n >= 1_000) return `${(n / 1_000).toFixed(1)}K`;
  return n.toFixed(0);
}
