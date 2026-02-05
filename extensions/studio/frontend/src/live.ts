import type { StudioEvent } from './types.js';
import { createEventSource, fetchEvents } from './api.js';

export interface LiveManagerOptions {
  types?: string[];
  onEvent: (event: StudioEvent) => void;
  onError?: (error: Error) => void;
  pollIntervalMs?: number;
}

export class LiveManager {
  private eventSource: EventSource | null = null;
  private pollTimer: ReturnType<typeof setInterval> | null = null;
  private paused = false;
  private polling = false;
  private buffer: StudioEvent[] = [];
  private lastEventId = 0;
  private readonly options: Required<LiveManagerOptions>;
  private useSse = true;

  constructor(options: LiveManagerOptions) {
    this.options = {
      types: options.types ?? [],
      onEvent: options.onEvent,
      onError: options.onError ?? (() => {}),
      pollIntervalMs: options.pollIntervalMs ?? 2000,
    };
  }

  start(): void {
    if (typeof EventSource !== 'undefined') {
      this.startSse();
    } else {
      this.useSse = false;
      this.startPolling();
    }
  }

  stop(): void {
    if (this.eventSource) {
      this.eventSource.close();
      this.eventSource = null;
    }

    if (this.pollTimer !== null) {
      clearInterval(this.pollTimer);
      this.pollTimer = null;
    }
  }

  pause(): void {
    this.paused = true;
  }

  resume(): void {
    this.paused = false;
    const buffered = this.buffer.splice(0);
    for (const event of buffered) {
      this.options.onEvent(event);
    }
  }

  isPaused(): boolean {
    return this.paused;
  }

  bufferedCount(): number {
    return this.buffer.length;
  }

  private startSse(): void {
    this.eventSource = createEventSource(
      this.options.types.length > 0 ? this.options.types : undefined,
      this.lastEventId !== 0 ? String(this.lastEventId) : undefined,
    );

    this.eventSource.onmessage = (event: MessageEvent<string>) => {
      try {
        const data = JSON.parse(event.data) as StudioEvent;
        if (event.lastEventId) {
          const parsed = Number(event.lastEventId);
          if (!Number.isNaN(parsed)) {
            this.lastEventId = parsed;
          }
        }
        this.handleEvent(data);
      } catch {
        // Ignore malformed events
      }
    };

    this.eventSource.onerror = () => {
      this.eventSource?.close();
      this.eventSource = null;
      this.useSse = false;
      this.startPolling();
    };
  }

  private startPolling(): void {
    const poll = async (): Promise<void> => {
      // Guard against overlapping polls
      if (this.polling) return;
      this.polling = true;

      try {
        const params: Record<string, string> = {};

        if (this.options.types.length > 0) {
          params['types'] = this.options.types.join(',');
        }

        if (this.lastEventId !== 0) {
          params['since_id'] = String(this.lastEventId);
        }

        const response = await fetchEvents(params);

        for (const event of response.events) {
          const eventId = event.id;
          if (eventId > this.lastEventId) {
            this.lastEventId = eventId;
          }
          this.handleEvent(event);
        }
      } catch (error) {
        this.options.onError(error instanceof Error ? error : new Error(String(error)));
      } finally {
        this.polling = false;
      }
    };

    void poll();
    this.pollTimer = setInterval(() => void poll(), this.options.pollIntervalMs);
  }

  private handleEvent(event: StudioEvent): void {
    if (this.paused) {
      this.buffer.push(event);
    } else {
      this.options.onEvent(event);
    }
  }
}
