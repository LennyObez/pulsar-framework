/**
 * Pulsar Turbo — stream endpoint tests for a page served over https
 *
 * jsdom fixes the document URL for the whole file and `location` cannot be
 * reassigned from inside one, so the downgrade refusal gets a file of its own
 * whose document is served over https.
 *
 * @vitest-environment jsdom
 * @vitest-environment-options { "url": "https://pulsar.test/playground" }
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { PulsarTurbo } from './pulsar-turbo.js';

class FakeWebSocket {
  static opened: string[] = [];

  constructor(readonly url: string) {
    FakeWebSocket.opened.push(url);
  }

  addEventListener(): void {}
}

describe('PulsarTurbo.connectWebSocket on an https page', () => {
  let turbo: PulsarTurbo;

  beforeEach(() => {
    FakeWebSocket.opened = [];
    vi.stubGlobal('WebSocket', FakeWebSocket);
    document.body.innerHTML = '';
    turbo = new PulsarTurbo();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('is serving from https', () => {
    expect(location.protocol).toBe('https:');
    expect(location.host).toBe('pulsar.test');
  });

  it('opens a secure socket on the document host', () => {
    turbo.connectWebSocket('wss://pulsar.test/_pulsar/stream');

    expect(FakeWebSocket.opened).toEqual(['wss://pulsar.test/_pulsar/stream']);
  });

  it('refuses to downgrade to an insecure socket', () => {
    expect(() => turbo.connectWebSocket('ws://pulsar.test/_pulsar/stream')).toThrow(
      /over wss:, not ws:/,
    );
    expect(FakeWebSocket.opened).toEqual([]);
  });
});
