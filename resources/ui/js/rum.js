/**
 * Pulsar RUM (Real User Monitoring)
 *
 * Lightweight client-side performance monitoring that captures Core Web
 * Vitals and error telemetry. Sends data to the /api/rum/collect endpoint.
 *
 * Captured metrics:
 * - LCP (Largest Contentful Paint)
 * - FID (First Input Delay) / INP (Interaction to Next Paint)
 * - CLS (Cumulative Layout Shift)
 * - Page load timing (navigationStart to loadEventEnd)
 * - Unhandled JavaScript errors
 *
 * @license MIT
 */
(function () {
  'use strict';

  var ENDPOINT = '/api/rum/collect';
  var BATCH_INTERVAL = 5000;
  var MAX_QUEUE = 50;

  /** @type {Array<Object>} */
  var queue = [];
  var sessionId = 'rum_' + Math.random().toString(36).substring(2, 10) + Date.now().toString(36);

  /**
   * Enqueue a metric for sending.
   * @param {string} name
   * @param {number} value
   * @param {Object} [tags]
   */
  function record(name, value, tags) {
    queue.push({
      name: name,
      value: Math.round(value * 1000) / 1000,
      url: location.pathname,
      session: sessionId,
      timestamp: Date.now(),
      tags: tags || {},
    });

    if (queue.length >= MAX_QUEUE) {
      flush();
    }
  }

  /**
   * Send queued metrics to the collection endpoint.
   */
  function flush() {
    if (queue.length === 0) return;

    var payload = queue.splice(0, MAX_QUEUE);

    if (navigator.sendBeacon) {
      navigator.sendBeacon(
        ENDPOINT,
        new Blob([JSON.stringify({ metrics: payload })], {
          type: 'application/json',
        }),
      );
    } else {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', ENDPOINT, true);
      xhr.setRequestHeader('Content-Type', 'application/json');
      xhr.send(JSON.stringify({ metrics: payload }));
    }
  }

  // --- Core Web Vitals via PerformanceObserver ---

  if (typeof PerformanceObserver !== 'undefined') {
    // LCP (Largest Contentful Paint)
    try {
      new PerformanceObserver(function (list) {
        var entries = list.getEntries();
        if (entries.length > 0) {
          var last = entries[entries.length - 1];
          record('lcp', last.startTime, { element: last.element?.tagName });
        }
      }).observe({ type: 'largest-contentful-paint', buffered: true });
    } catch (e) {
      /* Observer not supported */
    }

    // FID (First Input Delay)
    try {
      new PerformanceObserver(function (list) {
        var entries = list.getEntries();
        if (entries.length > 0) {
          record('fid', entries[0].processingStart - entries[0].startTime);
        }
      }).observe({ type: 'first-input', buffered: true });
    } catch (e) {
      /* Observer not supported */
    }

    // CLS (Cumulative Layout Shift)
    try {
      var clsValue = 0;
      new PerformanceObserver(function (list) {
        var entries = list.getEntries();
        for (var i = 0; i < entries.length; i++) {
          if (!entries[i].hadRecentInput) {
            clsValue += entries[i].value;
          }
        }
      }).observe({ type: 'layout-shift', buffered: true });

      // Report CLS at page hide
      document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
          record('cls', clsValue);
        }
      });
    } catch (e) {
      /* Observer not supported */
    }
  }

  // --- Page Load Timing ---

  window.addEventListener('load', function () {
    setTimeout(function () {
      var timing = performance.timing || {};
      if (timing.loadEventEnd && timing.navigationStart) {
        record('page_load', timing.loadEventEnd - timing.navigationStart);
      }
      if (timing.domContentLoadedEventEnd && timing.navigationStart) {
        record('dom_content_loaded', timing.domContentLoadedEventEnd - timing.navigationStart);
      }
      if (timing.responseStart && timing.requestStart) {
        record('ttfb', timing.responseStart - timing.requestStart);
      }
    }, 0);
  });

  // --- JavaScript Error Tracking ---

  window.addEventListener('error', function (event) {
    record('js_error', 1, {
      message: (event.message || '').substring(0, 200),
      source: (event.filename || '').substring(0, 200),
      line: event.lineno,
      col: event.colno,
    });
  });

  window.addEventListener('unhandledrejection', function (event) {
    var reason = event.reason instanceof Error ? event.reason.message : String(event.reason);
    record('unhandled_rejection', 1, {
      message: reason.substring(0, 200),
    });
  });

  // --- Periodic Flush ---

  setInterval(flush, BATCH_INTERVAL);

  // Flush on page unload
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') {
      flush();
    }
  });

  // Public API
  window.__pulsarRum = {
    record: record,
    flush: flush,
  };
})();
