<?php

declare(strict_types=1);

namespace Pulsar\WebSocket\Routing;

use Pulsar\Api\Api;
use Pulsar\WebSocket\Exception\WebSocketException;
use Pulsar\WebSocket\InboundMiddlewareInterface;
use Pulsar\WebSocket\MessageHandlerInterface;

use function array_key_exists;
use function array_values;

/**
 * In-memory registry of WebSocket routes.
 *
 * Routes are static: registration happens at boot time via service providers
 * or `Core\Wiring` wire-up hooks. Registration is idempotent by path — a
 * duplicate registration throws `WebSocketException::duplicateRoute()` so
 * silent shadowing cannot happen.
 *
 * The table is exact-match on path (no parameter extraction yet — see TD-036).
 * Globs and path parameters will land alongside the HTTP router's trie-based
 * match when `Routing\Router` gains WebSocket awareness.
 */
#[Api(since: '1.0.0')]
final class RouteTable
{
    /** @var array<string, Route> */
    private array $routes = [];

    /**
     * Register a route.
     *
     * @param class-string<MessageHandlerInterface> $handler
     * @param list<class-string<InboundMiddlewareInterface>> $middleware
     *
     * @throws WebSocketException if the path is already registered.
     */
    public function register(string $path, string $handler, array $middleware = []): void
    {
        if (array_key_exists($path, $this->routes)) {
            throw WebSocketException::duplicateRoute($path);
        }

        $this->routes[$path] = new Route($path, $handler, $middleware);
    }

    /**
     * Look up a route. Returns `null` if none matches.
     */
    public function match(string $path): ?Route
    {
        return $this->routes[$path] ?? null;
    }

    /**
     * Whether a given path has a registered route.
     */
    public function has(string $path): bool
    {
        return array_key_exists($path, $this->routes);
    }

    /**
     * All currently registered routes. Order reflects registration order.
     *
     * @return list<Route>
     */
    public function all(): array
    {
        return array_values($this->routes);
    }
}
