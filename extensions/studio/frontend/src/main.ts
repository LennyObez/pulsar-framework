import type { PageType } from './types.js';
import { renderBenchmarkDashboard } from './components/BenchmarkDashboard.js';
import { renderConsoleOverview } from './components/ConsoleOverview.js';
import { renderEventTable } from './components/EventTable.js';
import { renderTimeline } from './components/Timeline.js';

function init(): void {
  const app = document.getElementById('app');
  if (!app) return;

  const page = app.dataset['page'] as PageType | undefined;
  const payloadStr = app.dataset['payload'];

  if (!page) return;

  let payload: unknown = null;
  if (payloadStr) {
    try {
      payload = JSON.parse(payloadStr);
    } catch {
      app.innerHTML = '<p class="error">Failed to parse page data.</p>';
      return;
    }
  }

  switch (page) {
    case 'console-overview':
      renderConsoleOverview(app, payload);
      break;
    case 'request-explorer':
    case 'database-explorer':
    case 'log-explorer':
    case 'exception-explorer':
      renderEventTable(app, page, payload);
      break;
    case 'timeline':
      renderTimeline(app, payload);
      break;
    case 'benchmark-dashboard':
      renderBenchmarkDashboard(app, payload);
      break;
    default:
      app.innerHTML = '<p class="error">Unknown page type.</p>';
  }
}

document.addEventListener('DOMContentLoaded', init);
