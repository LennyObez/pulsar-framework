/**
 * Pulsar Hot Module Reload Client
 *
 * Connects to the Pulsar WebSocket server and listens for file change
 * notifications. Automatically reloads the page when PHP or template
 * files change, and hot-swaps CSS without a full reload.
 *
 * This script is injected automatically by HotReloadMiddleware when
 * APP_DEBUG=true. It is never included in production builds.
 *
 * @internal Dev-only -- not part of the public API
 */
(function () {
  'use strict';

  if (typeof WebSocket === 'undefined') {
    return;
  }

  var DEFAULT_PORT = 8081;
  var RECONNECT_BASE_MS = 1000;
  var RECONNECT_MAX_MS = 30000;

  var port =
    document.currentScript && document.currentScript.dataset.wsPort
      ? parseInt(document.currentScript.dataset.wsPort, 10)
      : DEFAULT_PORT;

  var wsProto = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
  var wsUrl = wsProto + '//' + window.location.hostname + ':' + port;
  var reconnectDelay = RECONNECT_BASE_MS;

  function connect() {
    var ws = new WebSocket(wsUrl);

    ws.onopen = function () {
      reconnectDelay = RECONNECT_BASE_MS;
      console.log('[Pulsar HMR] Connected to', wsUrl);
    };

    ws.onmessage = function (e) {
      try {
        var msg = JSON.parse(e.data);

        if (msg.event !== 'file-changed' || !msg.data) {
          return;
        }

        var filePath = msg.data.path || '';
        var ext = filePath.split('.').pop();

        console.log('[Pulsar HMR] Change detected:', filePath, '(' + msg.data.type + ')');

        if (ext === 'css') {
          reloadStylesheets();
        } else {
          window.location.reload();
        }
      } catch (_err) {
        // Ignore malformed messages
      }
    };

    ws.onclose = function () {
      console.log('[Pulsar HMR] Disconnected, reconnecting in ' + reconnectDelay + 'ms');
      setTimeout(connect, reconnectDelay);
      reconnectDelay = Math.min(reconnectDelay * 2, RECONNECT_MAX_MS);
    };

    ws.onerror = function () {
      ws.close();
    };
  }

  /**
   * Hot-swap stylesheets by appending a cache-busting query parameter.
   * Avoids a full page reload for CSS-only changes.
   */
  function reloadStylesheets() {
    var links = document.querySelectorAll('link[rel="stylesheet"]');
    var timestamp = Date.now().toString();

    links.forEach(function (link) {
      var href = link.getAttribute('href');
      if (!href) {
        return;
      }

      try {
        var url = new URL(href, window.location.origin);
        url.searchParams.set('_hmr', timestamp);
        link.setAttribute('href', url.toString());
      } catch (_e) {
        // Skip malformed URLs
      }
    });

    console.log('[Pulsar HMR] Stylesheets reloaded');
  }

  connect();
})();
