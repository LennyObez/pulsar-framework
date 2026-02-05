export interface SparklineOptions {
  width?: number;
  height?: number;
  color?: string;
  barGap?: number;
}

/**
 * Render an inline SVG sparkline bar chart.
 */
export function renderSparkline(data: number[], options?: SparklineOptions): string {
  const width = options?.width ?? 100;
  const height = options?.height ?? 32;
  const color = options?.color ?? 'var(--studio-accent)';
  const barGap = options?.barGap ?? 2;

  if (data.length === 0) {
    return `<svg width="${String(width)}" height="${String(height)}" viewBox="0 0 ${String(width)} ${String(height)}" fill="none" xmlns="http://www.w3.org/2000/svg"></svg>`;
  }

  const maxVal = Math.max(...data, 1);
  const barCount = data.length;
  const totalGap = barGap * (barCount - 1);
  const barWidth = Math.max(2, (width - totalGap) / barCount);
  const cornerRadius = Math.min(2, barWidth / 2);

  const bars = data
    .map((value, index) => {
      const barHeight = Math.max(2, (value / maxVal) * height);
      const x = index * (barWidth + barGap);
      const y = height - barHeight;
      return `<rect x="${String(x)}" y="${String(y)}" width="${String(barWidth)}" height="${String(barHeight)}" rx="${String(cornerRadius)}" fill="${color}" opacity="0.7"/>`;
    })
    .join('');

  return `<svg width="${String(width)}" height="${String(height)}" viewBox="0 0 ${String(width)} ${String(height)}" fill="none" xmlns="http://www.w3.org/2000/svg">${bars}</svg>`;
}
