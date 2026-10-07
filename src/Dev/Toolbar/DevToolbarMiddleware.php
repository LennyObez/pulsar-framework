<?php

declare(strict_types=1);

namespace Pulsar\Dev\Toolbar;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Message\StringStream;
use Pulsar\Http\Middleware\MiddlewareInterface;

use function microtime;
use function str_contains;
use function str_replace;

/**
 * Injects the developer toolbar into HTML responses when debug mode is active.
 *
 * Measures request processing time, collects runtime metrics, and injects
 * the toolbar HTML before the closing `</body>` tag. Non-HTML responses
 * and production requests are passed through untouched.
 */
#[Internal]
final readonly class DevToolbarMiddleware implements MiddlewareInterface
{
    /**
     * @param bool $debugMode Whether dev mode is active (toolbar is only injected when true)
     * @param DevToolbar $toolbar The toolbar renderer
     * @param ToolbarDataCollector $collector Collects runtime metrics during the request
     */
    public function __construct(
        private bool $debugMode,
        private DevToolbar $toolbar,
        private ToolbarDataCollector $collector,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->debugMode) {
            return $handler->handle($request);
        }

        $startTime = microtime(true);
        $response = $handler->handle($request);

        $contentType = $response->getHeaderLine('Content-Type');
        if (!str_contains($contentType, 'text/html')) {
            return $response;
        }

        $body = (string) $response->getBody();
        if (!str_contains($body, '</body>')) {
            return $response;
        }

        $elapsedMs = (microtime(true) - $startTime) * 1000.0;

        $data = $this->collector->collect($request, $elapsedMs);
        $toolbarHtml = $this->toolbar->render($data);

        $injectedBody = str_replace('</body>', $toolbarHtml . '</body>', $body);

        return $response->withBody(new StringStream($injectedBody));
    }
}
