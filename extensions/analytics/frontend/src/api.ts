import type {
  AggregateStats,
  AttributionEntry,
  BreakdownItem,
  DateRange,
  EcommerceSummary,
  EventNameEntry,
  EventProperty,
  FlowStep,
  FunnelDefinition,
  FunnelResult,
  Goal,
  RealtimeData,
  RevenuePoint,
  SearchOverview,
  SearchQueryEntry,
  SegmentDefinition,
  Site,
  TimeseriesPoint,
  TopProduct,
} from './types';

const BASE = '/plsr/api/v1';

function getCsrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

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
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': getCsrfToken(),
    },
    body: JSON.stringify(body),
  });
  if (!res.ok) throw new Error(`API error: ${res.status}`);
  return (await res.json()) as T;
}

async function put<T>(path: string, body: Record<string, unknown>): Promise<T> {
  const res = await fetch(path, {
    method: 'PUT',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': getCsrfToken(),
    },
    body: JSON.stringify(body),
  });
  if (!res.ok) throw new Error(`API error: ${res.status}`);
  return (await res.json()) as T;
}

async function del(path: string): Promise<void> {
  const res = await fetch(path, {
    method: 'DELETE',
    headers: {
      'X-CSRF-Token': getCsrfToken(),
    },
  });
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

// Flow / Behavior Flow
export function fetchFlow(
  siteId: string,
  range: DateRange,
  entryPage = '/',
  depth = 3,
): Promise<{ data: FlowStep[] }> {
  return get(`${BASE}/flow`, {
    site_id: siteId,
    from: range.from,
    to: range.to,
    entry_page: entryPage,
    depth: String(depth),
  });
}

export function fetchExitPages(
  siteId: string,
  range: DateRange,
): Promise<{ data: Array<{ pathname: string; exits: number; exit_rate: number }> }> {
  return get(`${BASE}/flow/exits`, {
    site_id: siteId,
    from: range.from,
    to: range.to,
  });
}

// Funnels
export function fetchFunnels(siteId: string): Promise<{ data: FunnelDefinition[] }> {
  return get(`${BASE}/funnels`, { site_id: siteId });
}

export function createFunnel(
  data: Omit<FunnelDefinition, 'id' | 'created_at'>,
): Promise<FunnelDefinition> {
  return post(`${BASE}/funnels`, data as Record<string, unknown>);
}

export function evaluateFunnel(id: string, range: DateRange): Promise<FunnelResult> {
  return get(`${BASE}/funnels/${id}/evaluate`, {
    from: range.from,
    to: range.to,
  });
}

export function deleteFunnel(id: string): Promise<void> {
  return del(`${BASE}/funnels/${id}`);
}

// E-commerce
export function fetchEcommerceSummary(siteId: string, range: DateRange): Promise<EcommerceSummary> {
  return get(`${BASE}/ecommerce/summary`, {
    site_id: siteId,
    from: range.from,
    to: range.to,
  });
}

export function fetchTopProducts(
  siteId: string,
  range: DateRange,
  limit = 10,
): Promise<{ data: TopProduct[] }> {
  return get(`${BASE}/ecommerce/products`, {
    site_id: siteId,
    from: range.from,
    to: range.to,
    limit: String(limit),
  });
}

export function fetchRevenueTimeseries(
  siteId: string,
  range: DateRange,
): Promise<{ data: RevenuePoint[] }> {
  return get(`${BASE}/ecommerce/revenue`, {
    site_id: siteId,
    from: range.from,
    to: range.to,
  });
}

// Custom Events
export function fetchEventNames(
  siteId: string,
  range: DateRange,
): Promise<{ data: EventNameEntry[] }> {
  return get(`${BASE}/events/names`, {
    site_id: siteId,
    from: range.from,
    to: range.to,
  });
}

export function fetchEventProperties(
  siteId: string,
  range: DateRange,
  eventName: string,
): Promise<{ data: EventProperty[] }> {
  return get(`${BASE}/events/properties`, {
    site_id: siteId,
    from: range.from,
    to: range.to,
    event_name: eventName,
  });
}

export function fetchEventTimeseries(
  siteId: string,
  range: DateRange,
  eventName: string,
): Promise<{ data: Array<{ date: string; count: number }> }> {
  return get(`${BASE}/events/timeseries`, {
    site_id: siteId,
    from: range.from,
    to: range.to,
    event_name: eventName,
  });
}

// Segments
export function fetchSegments(siteId: string): Promise<{ data: SegmentDefinition[] }> {
  return get(`${BASE}/segments`, { site_id: siteId });
}

export function createSegment(
  data: Omit<SegmentDefinition, 'id' | 'created_at'>,
): Promise<SegmentDefinition> {
  return post(`${BASE}/segments`, data as Record<string, unknown>);
}

export function countSegmentVisitors(
  id: string,
  range: DateRange,
): Promise<{ segment_id: string; visitors: number }> {
  return get(`${BASE}/segments/${id}/count`, {
    from: range.from,
    to: range.to,
  });
}

export function deleteSegment(id: string): Promise<void> {
  return del(`${BASE}/segments/${id}`);
}

// Attribution
export function fetchAttribution(
  siteId: string,
  range: DateRange,
  model = 'last_touch',
): Promise<{ model: string; data: AttributionEntry[] }> {
  return get(`${BASE}/attribution`, {
    site_id: siteId,
    from: range.from,
    to: range.to,
    model,
  });
}

export function fetchAttributionComparison(
  siteId: string,
  range: DateRange,
): Promise<{ data: Record<string, AttributionEntry[]> }> {
  return get(`${BASE}/attribution/compare`, {
    site_id: siteId,
    from: range.from,
    to: range.to,
  });
}

// Search Analytics
export function fetchSearchOverview(siteId: string, range: DateRange): Promise<SearchOverview> {
  return get(`${BASE}/search/overview`, {
    site_id: siteId,
    from: range.from,
    to: range.to,
  });
}

export function fetchTopSearchQueries(
  siteId: string,
  range: DateRange,
): Promise<{ data: SearchQueryEntry[] }> {
  return get(`${BASE}/search/queries`, {
    site_id: siteId,
    from: range.from,
    to: range.to,
  });
}

export function fetchZeroResultQueries(
  siteId: string,
  range: DateRange,
): Promise<{ data: SearchQueryEntry[] }> {
  return get(`${BASE}/search/zero-results`, {
    site_id: siteId,
    from: range.from,
    to: range.to,
  });
}
