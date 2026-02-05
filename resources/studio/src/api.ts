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

  return response.json() as Promise<EventsResponse>;
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
