import type { EventsResponse } from './types.js';

const BASE_URL = '/studio/api';

export async function fetchEvents(params: Record<string, string> = {}): Promise<EventsResponse> {
  const url = new URL(`${BASE_URL}/events`, window.location.origin);
  for (const [key, value] of Object.entries(params)) {
    url.searchParams.set(key, value);
  }

  const response = await fetch(url.toString());

  if (!response.ok) {
    throw new Error(`API error: ${response.status} ${response.statusText}`);
  }

  return (await response.json()) as EventsResponse;
}

export async function postAction<T>(path: string, body: Record<string, unknown> = {}): Promise<T> {
  const response = await fetch(new URL(`${BASE_URL}${path}`, window.location.origin).toString(), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });

  if (!response.ok) {
    const err = await response.json().catch(() => ({}));
    throw new Error((err as { error?: string }).error ?? `API error: ${response.status}`);
  }

  return (await response.json()) as T;
}

export async function fetchJson<T>(path: string): Promise<T> {
  const response = await fetch(new URL(`${BASE_URL}${path}`, window.location.origin).toString());

  if (!response.ok) {
    throw new Error(`API error: ${response.status} ${response.statusText}`);
  }

  return (await response.json()) as T;
}

export function createEventSource(types?: string[], lastEventId?: string): EventSource {
  const url = new URL(`${BASE_URL}/live`, window.location.origin);

  if (types && types.length > 0) {
    url.searchParams.set('types', types.join(','));
  }

  if (lastEventId) {
    url.searchParams.set('lastEventId', lastEventId);
  }

  return new EventSource(url.toString());
}
