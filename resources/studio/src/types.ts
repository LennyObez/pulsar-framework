export interface StudioEvent {
  id: number;
  event_id: string;
  event_type: string;
  schema_version: number;
  timestamp_us: number;
  request_id: string | null;
  trace_id: string | null;
  span_id: string | null;
  job_id: string | null;
  app_env: string;
  hostname: string;
  tenant_hash: string | null;
  payload_json: string;
  payload_hash: string;
}

export interface EventsResponse {
  events: StudioEvent[];
  total: number;
  limit: number;
  offset: number;
}

export interface DashboardData {
  sections: string[];
  throughput: ThroughputData;
  latency: LatencyData;
  error_rate: ErrorRateData;
  slow_routes: SlowRouteEntry[];
  slow_queries: SlowQueryEntry[];
  event_counts: Record<string, number>;
}

export interface ThroughputData {
  total: number;
  per_minute: number;
  by_status: Record<string, number>;
}

export interface LatencyData {
  p50: number;
  p95: number;
  p99: number;
}

export interface ErrorRateData {
  total: number;
  per_minute: number;
  top_exceptions: ExceptionGroup[];
}

export interface ExceptionGroup {
  class: string;
  count: number;
  last_seen_us: number;
}

export interface SlowRouteEntry {
  route: string;
  p95_ms: number;
  count: number;
  avg_ms: number;
}

export interface SlowQueryEntry {
  sql_fingerprint: string;
  sql: string;
  p95_ms: number;
  count: number;
  avg_ms: number;
}

export interface TimelineData {
  correlation_id: string;
  events: StudioEvent[];
}

export type PageType =
  | 'console-overview'
  | 'request-explorer'
  | 'database-explorer'
  | 'log-explorer'
  | 'exception-explorer'
  | 'timeline';
