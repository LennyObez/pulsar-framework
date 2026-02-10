export function renderLiveIndicator(isLive: boolean, bufferedCount: number): string {
  if (isLive) {
    return `
      <div class="live-indicator live-active">
        <span class="live-dot"></span>
        <span>Live</span>
      </div>
    `;
  }

  const badge = bufferedCount > 0 ? ` <span class="badge">${String(bufferedCount)} new</span>` : '';

  return `
    <div class="live-indicator live-paused">
      <span>Paused</span>${badge}
    </div>
  `;
}
