import type {
  AggregateStats,
  BreakdownItem,
  DateRange,
  Goal,
  RealtimeData,
  Site,
  TimeseriesPoint,
} from './types';

const BASE = '/plsr/api/v1';

async function get<T>(path: string, params: Record<string, string> = {}): Promise<T> {
  const url = new URL(path, window.location.origin);
  for (const [k, v] of Object.entries(params)) {
    if (v) url.searchParams.set(k, v);
  }
  const res = await fetch(url.toString());
  if (!res.ok) throw new Error(`API error: ${res.status}`);
  return (await res.json()) as T;
}

async function post<T>(path: string, body: Record<string, unknown>): Promise<T> {
  const res = await fetch(path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  if (!res.ok) throw new Error(`API error: ${res.status}`);
  return (await res.json()) as T;
}

async function put<T>(path: string, body: Record<string, unknown>): Promise<T> {
  const res = await fetch(path, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  if (!res.ok) throw new Error(`API error: ${res.status}`);
  return (await res.json()) as T;
}

async function del(path: string): Promise<void> {
  const res = await fetch(path, { method: 'DELETE' });
  if (!res.ok) throw new Error(`API error: ${res.status}`);
}

export function fetchAggregate(
  siteId: string,
  range: DateRange,
  filters: Record<string, string> = {},
): Promise<AggregateStats> {
  return get<AggregateStats>(`${BASE}/stats/aggregate`, {
    site_id: siteId,
    from: range.from,
    to: range.to,
    ...Object.fromEntries(Object.entries(filters).map(([k, v]) => [`filters[${k}]`, v])),
  });
}

export function fetchTimeseries(
  siteId: string,
  range: DateRange,
  metric = 'visitors',
  interval = 'day',
): Promise<{ data: TimeseriesPoint[] }> {
  return get(`${BASE}/stats/timeseries`, {
    site_id: siteId,
    from: range.from,
    to: range.to,
    metric,
    interval,
  });
}

export function fetchBreakdown(
  siteId: string,
  range: DateRange,
  dimension: string,
  limit = 10,
): Promise<{ data: BreakdownItem[] }> {
  return get(`${BASE}/stats/breakdown`, {
    site_id: siteId,
    from: range.from,
    to: range.to,
    dimension,
    limit: String(limit),
  });
}

export function fetchRealtime(siteId: string): Promise<RealtimeData> {
  return get<RealtimeData>(`${BASE}/stats/realtime`, { site_id: siteId });
}

export function fetchSites(): Promise<{ data: Site[] }> {
  return get(`${BASE}/sites`);
}

export function createSite(data: Omit<Site, 'id' | 'tracking_id' | 'created_at'>): Promise<Site> {
  return post(`${BASE}/sites`, data as Record<string, unknown>);
}

export function updateSite(id: string, data: Partial<Site>): Promise<Site> {
  return put(`${BASE}/sites/${id}`, data as Record<string, unknown>);
}

export function deleteSite(id: string): Promise<void> {
  return del(`${BASE}/sites/${id}`);
}

export function fetchGoals(siteId: string): Promise<{ data: Goal[] }> {
  return get(`${BASE}/goals`, { site_id: siteId });
}

export function createGoal(data: Omit<Goal, 'id' | 'created_at'>): Promise<Goal> {
  return post(`${BASE}/goals`, data as Record<string, unknown>);
}

export function updateGoal(
  id: string,
  data: Pick<Goal, 'name' | 'goal_type' | 'target_value'>,
): Promise<Goal> {
  return put(`${BASE}/goals/${id}`, data as Record<string, unknown>);
}

export function deleteGoal(id: string): Promise<void> {
  return del(`${BASE}/goals/${id}`);
}
