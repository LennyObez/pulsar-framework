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
  throughput_series: number[];
  error_series: number[];
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

export interface BenchmarkRun {
  run_id: string;
  profile_count: number;
  success_count: number;
  failure_count: number;
  skipped_count: number;
  total_duration_ms: number;
  php_version: string;
  timestamp_us: number;
}

export interface BenchmarkProfile {
  profile_name: string;
  boot_us: number;
  warm_boot_us: number;
  p50_us: number;
  p95_us: number;
  rps: number;
  peak_rss_kb: number;
  memory_usage_kb: number;
  opcache_memory_kb: number | null;
  optimize_enabled: boolean;
}

export interface BenchmarkDashboardData {
  runs: BenchmarkRun[];
  latest_profiles: BenchmarkProfile[];
}

export interface ActivityLogData {
  events: StudioEvent[];
  total: number;
  page: number;
  limit: number;
  type_filter: string;
  available_types: string[];
}

export interface HealthDashboardData {
  system: {
    php_version: string;
    os: string;
    architecture: string;
    hostname: string;
    server_time: number;
  };
  memory: {
    usage_bytes: number;
    peak_bytes: number;
    limit: string;
  };
  disk: {
    free_bytes: number;
    total_bytes: number;
    used_percent: number;
  };
  queue: {
    pending: number;
    failed: number;
    completed: number;
  };
  memory_snapshots: MemorySnapshotEntry[];
  leak_report: LeakReportData | null;
  event_store: {
    total_events: number;
    size_bytes: number;
  };
}

export interface MemorySnapshotEntry {
  usage_bytes: number;
  peak_bytes: number;
  request_number: number;
  timestamp: number;
}

export interface LeakReportData {
  suspected: boolean;
  growth_per_request_bytes: number;
  total_growth_bytes: number;
  sample_count: number;
  first_usage_bytes: number;
  last_usage_bytes: number;
  peak_bytes: number;
}

export interface DeploymentViewerData {
  deployments: DeploymentEntry[];
  current_ref: string;
  tag_count: number;
}

export interface DeploymentEntry {
  tag: string;
  previous_tag: string | null;
  commits: DeploymentCommit[];
  stats: DeploymentStats | null;
}

export interface DeploymentCommit {
  hash: string;
  short_hash: string;
  subject: string;
  author: string;
  date: string;
}

export interface DeploymentStats {
  files_changed: number;
  insertions: number;
  deletions: number;
}

export type PageType =
  | 'console-overview'
  | 'request-explorer'
  | 'database-explorer'
  | 'log-explorer'
  | 'exception-explorer'
  | 'timeline'
  | 'benchmark-dashboard'
  | 'activity-log'
  | 'health-dashboard'
  | 'deployment-viewer';
