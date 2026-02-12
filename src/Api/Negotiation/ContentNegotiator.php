<?php

declare(strict_types=1);

namespace Pulsar\Api\Negotiation;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Api\Format\HalRenderer;
use Pulsar\Api\Format\JsonApiRenderer;
use Pulsar\Api\Format\JsonRenderer;
use Pulsar\Api\Format\ResponseRendererInterface;
use Pulsar\Http\Middleware\MiddlewareInterface;

use function str_contains;

/**
 * Middleware that reads the Accept header and selects the correct renderer.
 *
 * Maps media types:
 * - application/json → JsonRenderer (default)
 * - application/vnd.api+json → JsonApiRenderer
 * - application/hal+json → HalRenderer
 *
 * Falls back to the configured default format when no match is found.
 * Sets the resolved renderer on the request attribute 'pulsar.api.renderer'.
 */
#[Api(since: '1.0.0')]
final readonly class ContentNegotiator implements MiddlewareInterface
{
    /**
     * Request attribute key for the resolved renderer.
     */
    public const string RENDERER_ATTRIBUTE = 'pulsar.api.renderer';

    /**
     * @param ResponseRendererInterface $defaultRenderer Fallback renderer when no match
     * @param bool $jsonApiEnabled Whether JSON:API format is available
     * @param bool $halEnabled Whether HAL format is available
     */
    public function __construct(
        private ResponseRendererInterface $defaultRenderer = new JsonRenderer(),
        private bool $jsonApiEnabled = false,
        private bool $halEnabled = false,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $accept = $request->getHeaderLine('Accept');
        $renderer = $this->resolve($accept);

        $request = $request->withAttribute(self::RENDERER_ATTRIBUTE, $renderer);

        $response = $handler->handle($request);

        return $response->withHeader('Content-Type', $renderer->contentType());
    }

    /**
     * Resolve the renderer from the Accept header value.
     */
    private function resolve(string $accept): ResponseRendererInterface
    {
        if ($accept === '' || $accept === '*/*') {
            return $this->defaultRenderer;
        }

        // JSON:API takes priority when explicitly requested
        if ($this->jsonApiEnabled && str_contains($accept, 'application/vnd.api+json')) {
            return new JsonApiRenderer();
        }

        // HAL
        if ($this->halEnabled && str_contains($accept, 'application/hal+json')) {
            return new HalRenderer();
        }

        // Standard JSON
        if (str_contains($accept, 'application/json')) {
            return new JsonRenderer();
        }

        // Fallback to default
        return $this->defaultRenderer;
    }
}
