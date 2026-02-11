import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { fetchEvents } from '../api.js';

describe('fetchEvents', () => {
  const mockFetch = vi.fn();

  beforeEach(() => {
    vi.stubGlobal('fetch', mockFetch);
    vi.stubGlobal('window', {
      location: {
        origin: 'http://localhost:8080',
      },
    });
  });

  afterEach(() => {
    mockFetch.mockClear();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  it('should fetch events from the API', async () => {
    const mockResponse = {
      events: [],
      total: 0,
      limit: 50,
      offset: 0,
    };

    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve(mockResponse),
    });

    const result = await fetchEvents();

    expect(mockFetch).toHaveBeenCalledOnce();
    expect(result).toEqual(mockResponse);
  });

  it('should pass query parameters', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({ events: [], total: 0, limit: 50, offset: 0 }),
    });

    await fetchEvents({ types: 'http.request', limit: '10' });

    const calledUrl = mockFetch.mock.calls[0]?.[0] as string;
    expect(calledUrl).toContain('types=http.request');
    expect(calledUrl).toContain('limit=10');
  });

  it('should throw on non-OK response', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: false,
      status: 500,
      statusText: 'Internal Server Error',
    });

    await expect(fetchEvents()).rejects.toThrow('API error: 500 Internal Server Error');
  });
});
