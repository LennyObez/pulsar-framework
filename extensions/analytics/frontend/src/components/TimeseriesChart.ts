import type { TimeseriesPoint } from "../types";

/**
 * Lightweight canvas-based time-series chart.
 *
 * Uses native Canvas API instead of uPlot for zero-dependency builds.
 * For production with uPlot, replace this with the uPlot wrapper.
 */
export function renderTimeseriesChart(container: HTMLElement, data: TimeseriesPoint[]): void {
  container.innerHTML = "";

  if (data.length === 0) {
    container.innerHTML = '<p class="chart-empty">No data for this period</p>';
    return;
  }

  const canvas = document.createElement("canvas");
  canvas.className = "timeseries-canvas";
  container.appendChild(canvas);

  const rect = container.getBoundingClientRect();
  const dpr = window.devicePixelRatio || 1;
  const w = rect.width;
  const h = 280;

  canvas.width = w * dpr;
  canvas.height = h * dpr;
  canvas.style.width = `${w}px`;
  canvas.style.height = `${h}px`;

  const ctx = canvas.getContext("2d");
  if (!ctx) return;

  ctx.scale(dpr, dpr);

  const pad = { top: 20, right: 20, bottom: 40, left: 60 };
  const plotW = w - pad.left - pad.right;
  const plotH = h - pad.top - pad.bottom;

  const values = data.map((d) => d.value);
  const maxVal = Math.max(...values, 1);

  // Grid lines
  const isDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
  const gridColor = isDark ? "rgba(255,255,255,0.08)" : "rgba(0,0,0,0.06)";
  const textColor = isDark ? "rgba(255,255,255,0.5)" : "rgba(0,0,0,0.5)";
  const lineColor = isDark ? "#818cf8" : "#6366f1";
  const fillColor = isDark ? "rgba(129,140,248,0.15)" : "rgba(99,102,241,0.1)";

  ctx.strokeStyle = gridColor;
  ctx.lineWidth = 1;
  ctx.font = "11px system-ui, -apple-system, sans-serif";
  ctx.fillStyle = textColor;
  ctx.textAlign = "right";

  for (let i = 0; i <= 4; i++) {
    const y = pad.top + plotH - (plotH * i) / 4;
    ctx.beginPath();
    ctx.moveTo(pad.left, y);
    ctx.lineTo(w - pad.right, y);
    ctx.stroke();
    ctx.fillText(String(Math.round((maxVal * i) / 4)), pad.left - 8, y + 4);
  }

  // X-axis labels
  ctx.textAlign = "center";
  const labelInterval = Math.max(1, Math.floor(data.length / 7));
  for (let i = 0; i < data.length; i += labelInterval) {
    const x = pad.left + (plotW * i) / (data.length - 1 || 1);
    const label = data[i].date.slice(5);
    ctx.fillText(label, x, h - pad.bottom + 20);
  }

  // Area fill
  ctx.beginPath();
  for (let i = 0; i < data.length; i++) {
    const x = pad.left + (plotW * i) / (data.length - 1 || 1);
    const y = pad.top + plotH - (plotH * data[i].value) / maxVal;
    if (i === 0) ctx.moveTo(x, y);
    else ctx.lineTo(x, y);
  }
  ctx.lineTo(pad.left + plotW, pad.top + plotH);
  ctx.lineTo(pad.left, pad.top + plotH);
  ctx.closePath();
  ctx.fillStyle = fillColor;
  ctx.fill();

  // Line
  ctx.beginPath();
  ctx.strokeStyle = lineColor;
  ctx.lineWidth = 2;
  ctx.lineJoin = "round";
  for (let i = 0; i < data.length; i++) {
    const x = pad.left + (plotW * i) / (data.length - 1 || 1);
    const y = pad.top + plotH - (plotH * data[i].value) / maxVal;
    if (i === 0) ctx.moveTo(x, y);
    else ctx.lineTo(x, y);
  }
  ctx.stroke();
}
