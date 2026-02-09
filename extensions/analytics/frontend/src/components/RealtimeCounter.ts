import { fetchRealtime } from '../api';

let intervalId: ReturnType<typeof setInterval> | null = null;

export function startRealtimeCounter(siteId: string): void {
  const el = document.getElementById('realtime-counter');
  if (!el) return;

  async function update(): Promise<void> {
    try {
      const data = await fetchRealtime(siteId);
      if (el) el.textContent = String(data.current_visitors);
    } catch {
      // Silent failure for realtime polling
    }
  }

  void update();
  intervalId = setInterval(() => void update(), 15_000);
}

export function stopRealtimeCounter(): void {
  if (intervalId !== null) {
    clearInterval(intervalId);
    intervalId = null;
  }
}
