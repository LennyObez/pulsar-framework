<?php

declare(strict_types=1);

namespace Pulsar\Dev\HotReload;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Middleware\MiddlewareInterface;

use function str_contains;
use function str_replace;

/**
 * Injects the hot-reload client script into HTML responses when debug mode is active.
 *
 * The script connects to a WebSocket server and listens for file change
 * notifications, triggering a page reload when PHP or template files change.
 * Only active when `$debugMode` is true; production responses are never modified.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final readonly class HotReloadMiddleware implements MiddlewareInterface
{
    private const int DEFAULT_WS_PORT = 8081;

    /**
     * @param bool $debugMode Whether debug/dev mode is active
     * @param int $wsPort WebSocket server port for the hot-reload connection
     */
    public function __construct(
        private bool $debugMode = false,
        private int $wsPort = self::DEFAULT_WS_PORT,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (!$this->debugMode) {
            return $response;
        }

        $contentType = $response->getHeaderLine('Content-Type');
        if (!str_contains($contentType, 'text/html')) {
            return $response;
        }

        $body = (string) $response->getBody();
        if (!str_contains($body, '</body>')) {
            return $response;
        }

        $script = $this->buildScript();
        $injectedBody = str_replace('</body>', $script . '</body>', $body);

        return $response
            ->withBody(new \Pulsar\Http\Message\StringStream($injectedBody))
            ->withHeader('X-Pulsar-HotReload', 'active');
    }

    /**
     * Build the hot-reload client script tag.
     */
    public function buildScript(): string
    {
        $port = $this->wsPort;

        return <<<SCRIPT
            <script data-pulsar-hot-reload>
            (function() {
                'use strict';
                if (typeof WebSocket === 'undefined') return;

                var wsProto = (window.location.protocol === 'https:') ? 'wss:' : 'ws:';
                var wsUrl = wsProto + '//' + window.location.hostname + ':{$port}';
                var reconnectDelay = 1000;
                var maxReconnectDelay = 30000;

                function connect() {
                    var ws = new WebSocket(wsUrl);

                    ws.onopen = function() {
                        reconnectDelay = 1000;
                        console.log('[Pulsar HMR] Connected');
                    };

                    ws.onmessage = function(e) {
                        try {
                            var msg = JSON.parse(e.data);
                            if (msg.event === 'file-changed') {
                                console.log('[Pulsar HMR] File changed:', msg.data.path);
                                var ext = msg.data.path.split('.').pop();
                                if (ext === 'css') {
                                    reloadStylesheets();
                                } else {
                                    window.location.reload();
                                }
                            }
                        } catch (err) {
                            // Ignore malformed messages
                        }
                    };

                    ws.onclose = function() {
                        console.log('[Pulsar HMR] Disconnected, reconnecting in ' + reconnectDelay + 'ms');
                        setTimeout(connect, reconnectDelay);
                        reconnectDelay = Math.min(reconnectDelay * 2, maxReconnectDelay);
                    };

                    ws.onerror = function() {
                        ws.close();
                    };
                }

                function reloadStylesheets() {
                    var links = document.querySelectorAll('link[rel="stylesheet"]');
                    links.forEach(function(link) {
                        var href = link.getAttribute('href');
                        if (href) {
                            var url = new URL(href, window.location.origin);
                            url.searchParams.set('_hmr', Date.now().toString());
                            link.setAttribute('href', url.toString());
                        }
                    });
                }

                connect();
            })();
            </script>

            SCRIPT;
    }
}
