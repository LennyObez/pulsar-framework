/**
 * Pulsar RUM — session identifier tests
 *
 * The session identifier is the only thing tying a browser's metric batches
 * together on the collector, so it is drawn from the platform CSPRNG. These
 * cover its shape, its uniqueness across page loads, and the deliberate absence
 * of a fallback: a browser with no Web Crypto API is left unmonitored rather
 * than monitored under a guessable name.
 *
 * @vitest-environment jsdom
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

interface RumApi {
  record: (name: string, value: number, tags?: Record<string, unknown>) => void;
  flush: () => void;
}

interface RumMetric {
  name: string;
  session: string;
}

function rum(): RumApi | undefined {
  return (globalThis as unknown as { __pulsarRum?: RumApi }).__pulsarRum;
}

async function loadRum(): Promise<void> {
  delete (globalThis as unknown as { __pulsarRum?: RumApi }).__pulsarRum;
  vi.resetModules();
  // Side-effect-only monitor (untyped plain-JS module); imported to run its
  // page instrumentation.
  // @ts-expect-error -- no declaration file for the untyped monitor module
  await import('./rum.js');
}

/** Record one metric and read back the batch the monitor beacons out. */
async function beaconedMetrics(beacon: ReturnType<typeof vi.fn>): Promise<RumMetric[]> {
  rum()!.record('lcp', 1234);
  rum()!.flush();

  const body = beacon.mock.calls[0]![1] as Blob;
  const payload = JSON.parse(await body.text()) as { metrics: RumMetric[] };

  return payload.metrics;
}

describe('rum session identifier', () => {
  let beacon: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    beacon = vi.fn();
    Object.defineProperty(navigator, 'sendBeacon', {
      value: beacon,
      configurable: true,
      writable: true,
    });
    // The monitor arms a periodic flush on load; the tests drive flush()
    // directly and must not leave a timer running behind them.
    vi.stubGlobal('setInterval', () => 0);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('draws 128 bits from the CSPRNG', async () => {
    await loadRum();

    const metrics = await beaconedMetrics(beacon);

    expect(metrics[0]!.session).toMatch(/^rum_[0-9a-f]{32}$/);
  });

  it('does not repeat an identifier across page loads', async () => {
    await loadRum();
    const first = await beaconedMetrics(beacon);

    beacon.mockClear();
    await loadRum();
    const second = await beaconedMetrics(beacon);

    expect(second[0]!.session).not.toBe(first[0]!.session);
  });

  it('leaves the page unmonitored when the platform has no CSPRNG', async () => {
    vi.stubGlobal('crypto', undefined);

    await loadRum();

    expect(rum()).toBeUndefined();
    expect(beacon).not.toHaveBeenCalled();
  });

  it('leaves the page unmonitored when getRandomValues is missing', async () => {
    vi.stubGlobal('crypto', {});

    await loadRum();

    expect(rum()).toBeUndefined();
  });
});
