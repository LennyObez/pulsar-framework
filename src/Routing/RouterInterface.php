<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use Pulsar\Api\Api;
use Pulsar\Http\Method;

/**
 * Route registration and query contract.
 *
 * Provides the HTTP verb convenience methods that extensions use
 * to register routes during the boot phase, plus introspection
 * methods needed by framework commands and the kernel itself.
 *
 * Internal-only methods (lock, loadRoutes, etc.) are intentionally
 * excluded to keep the public surface focused.
 */
#[Api(since: '1.0.0')]
interface RouterInterface
{
    /**
     * Add a route to the router.
     */
    public function add(Route $route): self;

    /**
     * Register a GET route.
     *
     * @param callable|class-string|array{0: class-string, 1: string} $handler
     */
    public function get(string $path, mixed $handler, ?string $name = null): self;

    /**
     * Register a POST route.
     *
     * @param callable|class-string|array{0: class-string, 1: string} $handler
     */
    public function post(string $path, mixed $handler, ?string $name = null): self;

    /**
     * Register a PUT route.
     *
     * @param callable|class-string|array{0: class-string, 1: string} $handler
     */
    public function put(string $path, mixed $handler, ?string $name = null): self;

    /**
     * Register a PATCH route.
     *
     * @param callable|class-string|array{0: class-string, 1: string} $handler
     */
    public function patch(string $path, mixed $handler, ?string $name = null): self;

    /**
     * Register a DELETE route.
     *
     * @param callable|class-string|array{0: class-string, 1: string} $handler
     */
    public function delete(string $path, mixed $handler, ?string $name = null): self;

    /**
     * Register a route matching any method.
     *
     * @param callable|class-string|array{0: class-string, 1: string} $handler
     */
    public function any(string $path, mixed $handler, ?string $name = null): self;

    /**
     * Register a group of routes under a common prefix.
     *
     * @param string   $prefix   The URL prefix for all routes in the group.
     * @param callable $callback Receives a RouterInterface to register grouped routes.
     */
    public function group(string $prefix, callable $callback): self;

    /**
     * Match a request path and method to a route.
     *
     * @param Method $method The HTTP method
     * @param string $path The request path
     * @param string|null $host The request Host header (for host-based routing)
     * @throws RoutingException When no route matches or method is not allowed
     */
    public function match(Method $method, string $path, ?string $host = null): MatchedRoute;

    /**
     * Get all registered routes.
     *
     * @return list<Route>
     */
    public function routes(): array;

    /**
     * Get the number of registered routes.
     */
    public function count(): int;
}
