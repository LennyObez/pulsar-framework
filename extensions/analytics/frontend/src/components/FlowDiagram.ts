import type { FlowStep } from '../types';

/**
 * Renders a Sankey-style user flow diagram on a canvas element.
 *
 * Shows navigation paths from entry pages through intermediate pages,
 * with band widths proportional to visitor counts.
 */
export function renderFlowDiagram(container: HTMLElement, steps: FlowStep[]): void {
  container.textContent = '';

  if (steps.length === 0) {
    const empty = document.createElement('p');
    empty.className = 'flow-empty';
    empty.textContent = 'No flow data for this period';
    container.appendChild(empty);
    return;
  }

  const canvas = document.createElement('canvas');
  canvas.className = 'flow-canvas';
  container.appendChild(canvas);

  const rect = container.getBoundingClientRect();
  const dpr = window.devicePixelRatio || 1;
  const w = rect.width;
  const h = 400;

  canvas.width = w * dpr;
  canvas.height = h * dpr;
  canvas.style.width = `${w}px`;
  canvas.style.height = `${h}px`;

  const ctx = canvas.getContext('2d');
  if (!ctx) return;

  ctx.scale(dpr, dpr);

  const isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

  // Group steps by depth to form columns
  const depthMap = new Map<number, FlowStep[]>();
  for (const step of steps) {
    const list = depthMap.get(step.depth) ?? [];
    list.push(step);
    depthMap.set(step.depth, list);
  }

  const maxDepth = Math.max(...depthMap.keys(), 0);
  const colWidth = w / (maxDepth + 2);
  const nodeWidth = 24;

  // Collect unique page names per column
  const columns: Map<string, number>[] = [];
  for (let d = 0; d <= maxDepth; d++) {
    const col = new Map<string, number>();
    const dSteps = depthMap.get(d) ?? [];
    for (const s of dSteps) {
      col.set(s.source, (col.get(s.source) ?? 0) + s.visitors);
      col.set(s.target, (col.get(s.target) ?? 0) + s.visitors);
    }
    columns.push(col);
  }

  // Build node positions
  const nodePositions = new Map<string, { x: number; y: number; h: number }>();
  const maxVisitors = Math.max(...steps.map((s) => s.visitors), 1);
  const pad = 40;

  for (let d = 0; d <= maxDepth; d++) {
    const dSteps = depthMap.get(d) ?? [];
    // Unique sources at this depth
    const sources = [...new Set(dSteps.map((s) => s.source))];
    const x = pad + d * colWidth;
    const availH = h - pad * 2;
    const gap = 8;
    const totalGap = gap * (sources.length - 1);
    const scale = (availH - totalGap) / Math.max(maxVisitors, 1);

    let yOffset = pad;
    for (const src of sources) {
      const total = dSteps.filter((s) => s.source === src).reduce((a, s) => a + s.visitors, 0);
      const nodeH = Math.max(total * scale, 4);
      if (!nodePositions.has(`${d}:${src}`)) {
        nodePositions.set(`${d}:${src}`, { x, y: yOffset, h: nodeH });
      }
      yOffset += nodeH + gap;
    }

    // Targets at depth d become sources at depth d+1
    const targets = [...new Set(dSteps.map((s) => s.target))];
    const tx = pad + (d + 1) * colWidth;
    let tyOffset = pad;
    for (const tgt of targets) {
      const total = dSteps.filter((s) => s.target === tgt).reduce((a, s) => a + s.visitors, 0);
      const nodeH = Math.max(total * scale, 4);
      if (!nodePositions.has(`${d + 1}:${tgt}`)) {
        nodePositions.set(`${d + 1}:${tgt}`, { x: tx, y: tyOffset, h: nodeH });
      }
      tyOffset += nodeH + gap;
    }
  }

  // Draw flow bands
  const bandColors = isDark
    ? ['rgba(129,140,248,0.3)', 'rgba(52,211,153,0.3)', 'rgba(251,146,60,0.3)']
    : ['rgba(99,102,241,0.25)', 'rgba(16,185,129,0.25)', 'rgba(249,115,22,0.25)'];

  for (const step of steps) {
    const from = nodePositions.get(`${step.depth}:${step.source}`);
    const to = nodePositions.get(`${step.depth + 1}:${step.target}`);
    if (!from || !to) continue;

    const bandH = Math.max((step.visitors / maxVisitors) * (h - pad * 2) * 0.3, 2);
    ctx.fillStyle = bandColors[step.depth % bandColors.length];

    ctx.beginPath();
    const x0 = from.x + nodeWidth;
    const x1 = to.x;
    const cp = (x1 - x0) / 2;

    ctx.moveTo(x0, from.y);
    ctx.bezierCurveTo(x0 + cp, from.y, x1 - cp, to.y, x1, to.y);
    ctx.lineTo(x1, to.y + bandH);
    ctx.bezierCurveTo(x1 - cp, to.y + bandH, x0 + cp, from.y + bandH, x0, from.y + bandH);
    ctx.closePath();
    ctx.fill();
  }

  // Draw nodes
  const nodeColor = isDark ? '#818cf8' : '#6366f1';
  const textColor = isDark ? 'rgba(255,255,255,0.85)' : 'rgba(0,0,0,0.85)';

  ctx.font = '11px system-ui, -apple-system, sans-serif';
  ctx.textBaseline = 'middle';

  for (const [key, pos] of nodePositions) {
    const name = key.split(':').slice(1).join(':');

    ctx.fillStyle = nodeColor;
    ctx.fillRect(pos.x, pos.y, nodeWidth, pos.h);

    ctx.fillStyle = textColor;
    ctx.textAlign = 'left';
    ctx.fillText(truncateLabel(name, 20), pos.x + nodeWidth + 6, pos.y + pos.h / 2);
  }

  // Render legend below canvas
  const legend = document.createElement('div');
  legend.className = 'flow-legend';

  for (const step of steps.slice(0, 8)) {
    const item = document.createElement('span');
    item.className = 'flow-legend__item';
    item.textContent = `${step.source} → ${step.target} (${step.visitors.toLocaleString()})`;
    legend.appendChild(item);
  }

  container.appendChild(legend);
}

function truncateLabel(str: string, max: number): string {
  return str.length > max ? str.slice(0, max - 1) + '\u2026' : str;
}
