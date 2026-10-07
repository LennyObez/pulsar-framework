// @vitest-environment jsdom
/**
 * Pulsar Managed Challenge — Widget refresh tests
 *
 * Exercises the silent-refresh behaviour of the widget under jsdom with a
 * synchronous fake Worker (deterministic, no microtask races) and a mocked
 * fetch: the initial proof-of-work is solved into the hidden field, a fresh
 * challenge is re-minted and re-solved at ~80% of the TTL, and a failed refresh
 * keeps the last valid token.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

/** Synchronous fake Worker: replies with a deterministic solution on postMessage. */
class FakeWorker {
  private listeners: Array<(e: { data: unknown }) => void> = [];

  constructor(
    public url: string,
    public opts: unknown,
  ) {}

  addEventListener(type: string, cb: (e: { data: unknown }) => void): void {
    if (type === 'message') {
      this.listeners.push(cb);
    }
  }

  postMessage(msg: { id: string; bits: number }): void {
    for (const cb of this.listeners) {
      cb({ data: { solution: 'sol-' + msg.id } });
    }
  }

  terminate(): void {}
}

function widgetHtml(refresh: string, ttl: number): string {
  return (
    '<form>' +
    '<div class="pulsar-managed-challenge" data-pmc-challenge="CH0" data-pmc-id="id0" data-pmc-bits="4"' +
    ` data-pmc-worker="/w.js" data-pmc-refresh="${refresh}" data-pmc-ttl="${ttl}">` +
    '<input type="hidden" name="pulsar-challenge-response" value="">' +
    '</div></form>'
  );
}

async function loadWidget(): Promise<void> {
  vi.resetModules();
  // Side-effect-only widget bootstrap (a plain-JS IIFE with no exported API or
  // type declarations); imported solely to run its DOM initialisation.
  // @ts-expect-error -- no declaration file for the untyped widget module
  await import('./managed-challenge.js');
}

describe('managed-challenge widget silent refresh', () => {
  beforeEach(() => {
    vi.stubGlobal('Worker', FakeWorker as unknown as typeof Worker);
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it('solves the initial challenge into the hidden field', async () => {
    document.body.innerHTML = widgetHtml('/refresh', 100);
    await loadWidget();

    const input = document.querySelector<HTMLInputElement>(
      'input[name="pulsar-challenge-response"]',
    )!;
    expect(input.value).toBe('CH0.sol-id0');
  });

  it('re-mints and re-solves a fresh challenge at ~80% of the TTL', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => ({
        ok: true,
        json: async () => ({ challenge: 'CH1', id: 'id1', bits: 4 }),
      })),
    );

    document.body.innerHTML = widgetHtml('/refresh', 100);
    await loadWidget();

    const input = document.querySelector<HTMLInputElement>(
      'input[name="pulsar-challenge-response"]',
    )!;
    expect(input.value).toBe('CH0.sol-id0');

    // Advance to 80% of the 100s TTL; the refresh timer fires, fetches, re-solves.
    await vi.advanceTimersByTimeAsync(80_000);

    expect(fetch).toHaveBeenCalledWith(
      '/refresh',
      expect.objectContaining({ credentials: 'same-origin' }),
    );
    expect(input.value).toBe('CH1.sol-id1');
  });

  it('keeps the last valid token when a refresh fails', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => ({ ok: false, json: async () => ({}) })),
    );

    document.body.innerHTML = widgetHtml('/refresh', 100);
    await loadWidget();

    const input = document.querySelector<HTMLInputElement>(
      'input[name="pulsar-challenge-response"]',
    )!;
    expect(input.value).toBe('CH0.sol-id0');

    await vi.advanceTimersByTimeAsync(80_000);

    // Refresh failed: the original solved token is retained, user not blocked.
    expect(input.value).toBe('CH0.sol-id0');
  });

  it('does not schedule a refresh when no endpoint is configured', async () => {
    vi.stubGlobal('fetch', vi.fn());

    document.body.innerHTML = widgetHtml('', 0);
    await loadWidget();

    await vi.advanceTimersByTimeAsync(600_000);

    expect(fetch).not.toHaveBeenCalled();
  });

  it('announces the localized status from data-pmc-msg-* attributes', async () => {
    document.body.innerHTML =
      '<form>' +
      '<div class="pulsar-managed-challenge" data-pmc-challenge="CH0" data-pmc-id="id0" data-pmc-bits="4"' +
      ' data-pmc-worker="/w.js" data-pmc-refresh="" data-pmc-ttl="0"' +
      ' data-pmc-msg-solving="Vérification en cours…" data-pmc-msg-solved="Vérification terminée."' +
      ' data-pmc-msg-error="Échec de la vérification." role="status" aria-live="polite">' +
      '<input type="hidden" name="pulsar-challenge-response" value="">' +
      '</div></form>';
    await loadWidget();

    // The synchronous FakeWorker drives the widget to the solved state on load,
    // so the live region announces the localized "solved" message.
    const status = document.querySelector('.pulsar-managed-challenge-status');
    expect(status).not.toBeNull();
    expect(status!.textContent).toBe('Vérification terminée.');
  });

  it('falls back to the English status when no data-pmc-msg-* attributes are present', async () => {
    document.body.innerHTML = widgetHtml('', 0);
    await loadWidget();

    const status = document.querySelector('.pulsar-managed-challenge-status');
    expect(status).not.toBeNull();
    expect(status!.textContent).toBe('Security check complete.');
  });
});
