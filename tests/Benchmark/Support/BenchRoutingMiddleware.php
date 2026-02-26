<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;

/**
 * Benchmark routing middleware.
 *
 * Attaches a pre-configured matched route to the request attributes.
 */
final class BenchRoutingMiddleware implements MiddlewareInterface
{
    private readonly MatchedRoute $matchedRoute;

    /**
     * @param array<string, mixed> $routeAttributes
     */
    public function __construct(array $routeAttributes = [])
    {
        $this->matchedRoute = new MatchedRoute(
            route: new Route(
                methods: [Method::GET],
                path: '/bench/api/resource',
                handler: static fn(): string => 'benchmark',
                name: 'bench.resource',
                attributes: $routeAttributes,
            ),
        );
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $request = $request->withAttribute('_route', $this->matchedRoute);

        return $handler->handle($request);
    }
}
