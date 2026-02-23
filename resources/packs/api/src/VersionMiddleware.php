<?php

declare(strict_types=1);

namespace {{namespace}}\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * API versioning middleware stub.
 *
 * Extracts the API version from the request URL prefix or Accept header
 * and makes it available as a request attribute.
 */
final class VersionMiddleware implements MiddlewareInterface
{
    private const string DEFAULT_VERSION = 'v1';
    private const string VERSION_ATTRIBUTE = 'api.version';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $version = $this->resolveVersion($request);

        $request = $request->withAttribute(self::VERSION_ATTRIBUTE, $version);

        return $handler->handle($request);
    }

    /**
     * Resolve API version from the request.
     */
    private function resolveVersion(ServerRequestInterface $request): string
    {
        // Try URL prefix: /api/v1/...
        $path = $request->getUri()->getPath();

        if (preg_match('#^/api/(v\d+)/#', $path, $matches) === 1) {
            return $matches[1];
        }

        // Try Accept header: application/vnd.pulsar.v1+json
        $accept = $request->getHeaderLine('Accept');

        if (preg_match('#application/vnd\.pulsar\.(v\d+)\+json#', $accept, $matches) === 1) {
            return $matches[1];
        }

        return self::DEFAULT_VERSION;
    }
}
