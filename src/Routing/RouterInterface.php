<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use Pulsar\Api\Api;
use Pulsar\Http\Method;
use Pulsar\Routing\Binding\BindingScope;

/**
 * Route registration and query contract.
 *
 * Provides the HTTP verb convenience methods that extensions use
 * to register routes during the boot phase, plus introspection
 * methods needed by framework commands and the kernel itself.
 *
 * Internal-only methods (lock, loadRoutes, etc.) are intentionally
 * excluded to keep the public surface focused.
 * @api
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
     * `$scope` overrides what the route path says about containment, and its
     * default overrides nothing. See {@see \Pulsar\Routing\Binding\BindingScope}.
     *
     * @param class-string $modelClass
     * @param class-string|null $resolverClass
     * @param string|null $parentRelation Relation to resolve through; required by, and exclusive to, BindingScope::Contained
     */
    public function model(
        string $parameter,
        string $modelClass,
        ?string $resolverClass = null,
        BindingScope $scope = BindingScope::Path,
        ?string $parentRelation = null,
    ): self;

    /**
     * Register a full resource route set (7 routes: index, create, store, show, edit, update, destroy).
     *
     * Passing `['auth']` guards nothing on its own: the generated routes carry
     * no `permissions` attribute, and
     * {@see \Pulsar\Auth\Middleware\AuthorizationMiddleware} reads an empty
     * permission list as deny-everyone. To guard a resource set, register its
     * routes through {@see RouteAccessRegistrar::authenticated()} and name the
     * permission each one requires.
     *
     * @param string $name Resource name (e.g. 'photos')
     * @param string $controller Controller class
     * @param list<string> $middleware Middleware for all routes
     */
    public function resource(string $name, string $controller, array $middleware = []): self;

    /**
     * Register an API resource route set (5 routes: index, store, show, update, destroy).
     *
     * The caveat on {@see RouterInterface::resource()} applies here too: `auth`
     * without a declared permission denies every caller.
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
