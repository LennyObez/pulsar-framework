import { fetchAggregate, fetchBreakdown, fetchTimeseries } from './api';
import { renderBreakdownTable } from './components/BreakdownTable';
import { renderDatePicker } from './components/DatePicker';
import { renderMetricCards } from './components/MetricCards';
import { startRealtimeCounter } from './components/RealtimeCounter';
import { renderTimeseriesChart } from './components/TimeseriesChart';
import type { DateRange } from './types';

let currentSiteId = '';
let currentRange: DateRange = { from: '', to: '' };

async function loadDashboard(siteId: string, range: DateRange): Promise<void> {
  try {
    const [aggregate, timeseries] = await Promise.all([
      fetchAggregate(siteId, range),
      fetchTimeseries(siteId, range, 'visitors', 'day'),
    ]);

    const cardsEl = document.getElementById('metric-cards');
    if (cardsEl) renderMetricCards(cardsEl, aggregate);

    const chartEl = document.getElementById('timeseries-chart');
    if (chartEl) renderTimeseriesChart(chartEl, timeseries.data);

    const dimensions = ['page', 'referrer', 'country', 'device', 'browser', 'os'] as const;
    const breakdownEls = dimensions.map((d) => ({
      dimension: d,
      el: document.querySelector<HTMLElement>(`[data-dimension="${d}"]`),
    }));

    const breakdowns = await Promise.all(
      dimensions.map((d) => fetchBreakdown(siteId, range, d, 10)),
    );

    breakdownEls.forEach((b, i) => {
      if (b.el) renderBreakdownTable(b.el, breakdowns[i].data);
    });
  } catch (err) {
    console.error('Analytics dashboard error:', err);
  }
}

function init(): void {
  // Get site ID from URL params or use first available site
  const params = new URLSearchParams(window.location.search);
  currentSiteId = params.get('site_id') || '';

  const datePickerEl = document.getElementById('date-picker');
  if (datePickerEl) {
    renderDatePicker(datePickerEl, (range) => {
      currentRange = range;
      if (currentSiteId) {
        void loadDashboard(currentSiteId, range);
      }
    });
  }

  if (currentSiteId) {
    startRealtimeCounter(currentSiteId);
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
