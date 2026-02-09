export interface AggregateStats {
  visitors: number;
  pageviews: number;
  sessions: number;
  bounce_rate: number;
  avg_duration: number;
  events_count: number;
}

export interface TimeseriesPoint {
  date: string;
  value: number;
}

export interface BreakdownItem {
  name: string;
  visitors: number;
  pageviews: number;
}

export interface RealtimeData {
  current_visitors: number;
  active_pages: Array<{ pathname: string; visitors: number }>;
}

export interface Site {
  id: string;
  domain: string;
  name: string;
  tracking_id: string;
  timezone: string;
  created_at: string;
}

export interface Goal {
  id: string;
  site_id: string;
  name: string;
  goal_type: "page_visit" | "custom_event";
  target_value: string;
  created_at: string;
}

export interface DateRange {
  from: string;
  to: string;
}
