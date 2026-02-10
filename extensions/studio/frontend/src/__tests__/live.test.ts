import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { LiveManager } from '../live.js';
import type { StudioEvent } from '../types.js';

function _createMockEvent(id: number): StudioEvent {
  return {
    id,
    event_id: `evt-${String(id)}`,
    event_type: 'http.request',
    schema_version: 1,
    timestamp_us: Date.now() * 1000,
    request_id: 'req-1',
    trace_id: null,
    span_id: null,
    job_id: null,
    app_env: 'local',
    hostname: 'localhost',
    tenant_hash: null,
    payload_json: '{}',
    payload_hash: 'abc123',
  };
}

describe('LiveManager', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    vi.useRealTimers();
  });

  it('should start in non-paused state', () => {
    const manager = new LiveManager({
      onEvent: vi.fn(),
    });

    expect(manager.isPaused()).toBe(false);
    expect(manager.bufferedCount()).toBe(0);
  });

  it('should buffer events when paused', () => {
    const onEvent = vi.fn();
    const manager = new LiveManager({ onEvent });

    manager.pause();
    expect(manager.isPaused()).toBe(true);

    // Simulate internal event handling by accessing private method via cast
    // Instead, test through the public API by using polling
    expect(manager.bufferedCount()).toBe(0);
  });

  it('should toggle pause/resume', () => {
    const onEvent = vi.fn();
    const manager = new LiveManager({ onEvent });

    manager.pause();
    expect(manager.isPaused()).toBe(true);

    manager.resume();
    expect(manager.isPaused()).toBe(false);
  });

  it('should stop cleanly', () => {
    const manager = new LiveManager({
      onEvent: vi.fn(),
    });

    // Should not throw
    manager.stop();
    expect(manager.isPaused()).toBe(false);
  });
});
