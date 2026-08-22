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
  goal_type: 'page_visit' | 'custom_event';
  target_value: string;
  created_at: string;
}

export interface DateRange {
  from: string;
  to: string;
}

export interface FlowStep {
  source: string;
  target: string;
  visitors: number;
  depth: number;
}

export interface FunnelDefinition {
  id: string;
  site_id: string;
  name: string;
  steps: Array<{
    position: number;
    name: string;
    type: 'page_visit' | 'custom_event';
    value: string;
  }>;
  created_at: string;
}

export interface FunnelResult {
  funnel_id: string;
  overall_conversion_rate: number;
  steps: Array<{
    position: number;
    name: string;
    visitors: number;
    drop_off_rate: number;
    conversion_rate: number;
  }>;
}

export interface EcommerceSummary {
  revenue: number;
  transactions: number;
  average_order_value: number;
  conversion_rate: number;
  items_sold: number;
  currency: string;
}

export interface TopProduct {
  product_id: string;
  name: string;
  revenue: number;
  quantity: number;
}

export interface RevenuePoint {
  date: string;
  revenue: number;
  transactions: number;
}

export interface EventNameEntry {
  event_name: string;
  count: number;
  visitors: number;
}

export interface EventProperty {
  property: string;
  value: string;
  count: number;
}

export interface SegmentDefinition {
  id: string;
  site_id: string;
  name: string;
  filters: Array<{
    dimension: string;
    operator: string;
    value: string;
  }>;
  created_at: string;
}

export interface AttributionEntry {
  source: string;
  conversions: number;
  revenue: number;
  weight: number;
}

export interface SearchQueryEntry {
  query: string;
  count: number;
  result_count?: number;
  click_through_rate?: number;
}

export interface SearchOverview {
  total_searches: number;
  unique_queries: number;
  zero_result_rate: number;
  avg_click_through_rate: number;
}
