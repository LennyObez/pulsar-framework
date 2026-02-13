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
     */
    public function get(string $path, mixed $handler, ?string $name = null): self;

    /**
     * Register a POST route.
     */
    public function post(string $path, mixed $handler, ?string $name = null): self;

    /**
     * Register a PUT route.
     */
    public function put(string $path, mixed $handler, ?string $name = null): self;

    /**
     * Register a PATCH route.
     */
    public function patch(string $path, mixed $handler, ?string $name = null): self;

    /**
     * Register a DELETE route.
     */
    public function delete(string $path, mixed $handler, ?string $name = null): self;

    /**
     * Register a route matching any method.
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
     * Register an explicit parameter-to-model binding.
     *
     * @param class-string $modelClass
     * @param class-string|null $resolverClass
     */
    public function model(string $parameter, string $modelClass, ?string $resolverClass = null): self;

    /**
     * Register a full resource route set (7 routes: index, create, store, show, edit, update, destroy).
     *
     * @param string $name Resource name (e.g. 'photos')
     * @param string $controller Controller class
     * @param list<string> $middleware Middleware for all routes
     */
    public function resource(string $name, string $controller, array $middleware = []): self;

    /**
     * Register an API resource route set (5 routes: index, store, show, update, destroy).
     *
     * @param string $name Resource name (e.g. 'photos')
     * @param string $controller Controller class
     * @param list<string> $middleware Middleware for all routes
     */
    public function apiResource(string $name, string $controller, array $middleware = []): self;

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
