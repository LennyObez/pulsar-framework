/**
 * Pulsar RUM (Real User Monitoring)
 *
 * Lightweight client-side performance monitoring that captures Core Web
 * Vitals and error telemetry. Posts batches to /_pulsar/rum/collect, the path
 * DiagnosticsWiring registers -- it previously pointed at /api/rum/collect,
 * which no router ever served, so every batch this script sent was a 404.
 *
 * Opt in by loading it from a layout: <script src="/ui/js/rum.js" defer></script>.
 * The endpoint is same-origin only and registered in debug builds alone.
 *
 * Captured metrics:
 * - LCP (Largest Contentful Paint)
 * - FID (First Input Delay) / INP (Interaction to Next Paint)
 * - CLS (Cumulative Layout Shift)
 * - Page load timing (navigationStart to loadEventEnd)
 * - Unhandled JavaScript errors
 *
 * Requires the Web Crypto API: the session identifier is drawn from
 * crypto.getRandomValues, and a browser that cannot supply one is left
 * unmonitored rather than monitored under a guessable name.
 *
 * @license MIT
 */
(function () {
  'use strict';

  var ENDPOINT = '/_pulsar/rum/collect';
  var BATCH_INTERVAL = 5000;
  var MAX_QUEUE = 50;
  var SESSION_BYTES = 16;

  /**
   * Draw a session identifier from the platform CSPRNG.
   *
   * The identifier is the only thing tying one browser's batches together on
   * the collector, so a guessable one lets a third party graft batches onto a
   * session that is not theirs and skew what the collector reports.
   * Math.random() is seeded per page and its stream is recoverable from a
   * handful of observed outputs; crypto.getRandomValues is not.
   *
   * There is deliberately no fallback. A browser with no Web Crypto API gets no
   * identifier, and the caller below leaves the page unmonitored instead of
   * monitoring it under a predictable name.
   *
   * @returns {string} `rum_` followed by 32 hex characters, or '' when the
   *     platform has no CSPRNG.
   */
  function newSessionId() {
    if (typeof crypto === 'undefined' || typeof crypto.getRandomValues !== 'function') {
      return '';
    }

    var bytes = new Uint8Array(SESSION_BYTES);
    crypto.getRandomValues(bytes);

    var id = 'rum_';
    for (var i = 0; i < bytes.length; i++) {
      id += (bytes[i] + 0x100).toString(16).slice(1);
    }

    return id;
  }

  var sessionId = newSessionId();
  if (sessionId === '') {
    return;
  }

  /** @type {Array<Object>} */
  var queue = [];

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
    } catch (_e) {
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
    } catch (_e) {
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
    } catch (_e) {
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
