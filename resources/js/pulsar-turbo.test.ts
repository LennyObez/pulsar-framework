/**
 * Pulsar Turbo — stream endpoint tests
 *
 * A stream socket is a DOM-write channel: whatever arrives on it is parsed as
 * HTML and imported into the live document. The module header says stream
 * mutations come exclusively from the same-origin Pulsar server; these cover
 * the code that makes that true, and the shapes of endpoint a caller actually
 * writes (relative, http(s), ws(s)) that must keep working. The refusal to
 * downgrade a secure page lives in pulsar-turbo.https.test.ts, which needs a
 * document served over https to exercise it.
 *
 * @vitest-environment jsdom
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

describe('PulsarTurbo.connectWebSocket', () => {
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

  it('opens a relative endpoint on the document host', () => {
    turbo.connectWebSocket('/_pulsar/stream');

    expect(FakeWebSocket.opened).toEqual(['/_pulsar/stream']);
  });

  it('opens an absolute endpoint on the document host', () => {
    const url = `ws://${location.host}/_pulsar/stream`;

    turbo.connectWebSocket(url);

    expect(FakeWebSocket.opened).toEqual([url]);
  });

  it('accepts the http(s) form the WebSocket constructor maps for the caller', () => {
    const url = `http://${location.host}/_pulsar/stream`;

    turbo.connectWebSocket(url);

    expect(FakeWebSocket.opened).toEqual([url]);
  });

  it('refuses an endpoint on another host', () => {
    expect(() => turbo.connectWebSocket('wss://streams.evil.example/_pulsar/stream')).toThrow(
      /not the document host/,
    );
    expect(FakeWebSocket.opened).toEqual([]);
  });

  it('refuses another port on the same hostname', () => {
    expect(() => turbo.connectWebSocket(`ws://${location.hostname}:9/stream`)).toThrow(
      /not the document host/,
    );
    expect(FakeWebSocket.opened).toEqual([]);
  });

  it('refuses a scheme that is not a WebSocket scheme', () => {
    expect(() => turbo.connectWebSocket('ftp://example.invalid/stream')).toThrow(
      /speaks ws: or wss:/,
    );
    expect(FakeWebSocket.opened).toEqual([]);
  });

  it('refuses a javascript: endpoint', () => {
    expect(() => turbo.connectWebSocket('javascript:alert(1)')).toThrow(/speaks ws: or wss:/);
    expect(FakeWebSocket.opened).toEqual([]);
  });
});
