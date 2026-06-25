<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use InvalidArgumentException;
use Pulsar\Api\Api;
use Pulsar\Config\DomainConfig;
use Pulsar\Http\Method;
use Pulsar\Routing\Binding\ExplicitBinding;

use function count;
use function sprintf;
use function trim;

/**
 * HTTP router for route registration and matching.
 *
 * A thin facade over three focused collaborators: {@see RouteIndex} owns the
 * fast-lookup tables and matching, {@see RouteUrlGenerator} builds URLs, and
 * {@see ResourceRegistrar} scaffolds RESTful resource route sets.
 * @api
 */
#[Api(since: '1.0.0')]
final class Router implements RouterInterface
{
    /**
     * @var list<Route>
     */
    public private(set) array $routes = [];

    /**
     * @var array<string, Route>
     */
    public private(set) array $namedRoutes = [];

    /**
     * Explicit parameter-to-model bindings registered via model().
     *
     * @var list<ExplicitBinding>
     */
    public private(set) array $explicitBindings = [];

    /**
     * Whether the router is locked (strict cache mode).
     * When locked, addRoute() throws RoutingException::routerLocked().
     */
    public private(set) bool $locked = false;

    /**
     * Fast-lookup index + matcher. Populated as routes are registered.
     */
    private readonly RouteIndex $index;

    public function __construct()
    {
        $this->index = new RouteIndex();
    }

    /**
     * Add a route to the router.
     *
     * @throws RoutingException If the router is locked in strict cache mode
     */
    public function add(Route $route): self
    {
        if ($this->locked) {
            throw RoutingException::routerLocked();
        }

        $this->routes[] = $route;

        if ($route->name !== null) {
            $this->namedRoutes[$route->name] = $route;
        }

        $this->index->index($route);

        return $this;
    }

    /**
     * Add multiple routes from a group.
     *
     * @throws RoutingException If the router is locked in strict cache mode
     */
    public function addGroup(RouteGroup $group): self
    {
        foreach ($group->flatten() as $route) {
            $this->add($route);
        }

        return $this;
    }

    /**
     * Register a GET route.
     *
     * @throws RoutingException If the router is locked in strict cache mode
     */
    public function get(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(Route::get($path, $handler, $name));
    }

    /**
     * Register a POST route.
     *
     * @throws RoutingException If the router is locked in strict cache mode
     */
    public function post(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(Route::post($path, $handler, $name));
    }

    /**
     * Register a PUT route.
     *
     * @throws RoutingException If the router is locked in strict cache mode
     */
    public function put(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(Route::put($path, $handler, $name));
    }

    /**
     * Register a PATCH route.
     *
     * @throws RoutingException If the router is locked in strict cache mode
     */
    public function patch(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(Route::patch($path, $handler, $name));
    }

    /**
     * Register a DELETE route.
     *
     * @throws RoutingException If the router is locked in strict cache mode
     */
    public function delete(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(Route::delete($path, $handler, $name));
    }

    /**
     * Register a route matching any method.
     *
     * @throws RoutingException If the router is locked in strict cache mode
     */
    public function any(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(Route::any($path, $handler, $name));
    }

    /**
     * Register a group of routes under a common prefix.
     *
     * Creates a temporary router, passes it to the callback, then
     * adds all registered routes with the prefix prepended to their paths.
     *
     * @param string   $prefix   The URL prefix for all routes in the group.
     * @param callable $callback Receives a RouterInterface to register grouped routes.
     *
     * @throws RoutingException If the router is locked in strict cache mode
     */
    public function group(string $prefix, callable $callback): self
    {
        $prefix = '/' . trim($prefix, '/');
        $subRouter = new self();

        $callback($subRouter);

        foreach ($subRouter->routes as $route) {
            $prefixedPath = $prefix . '/' . trim($route->path, '/');

            $this->add(new Route(
                methods: $route->methods,
                path: $prefixedPath,
                handler: $route->handler,
                name: $route->name,
                attributes: $route->attributes,
                middleware: $route->middleware,
                constraints: $route->constraints,
                host: $route->host,
            ));
        }

        return $this;
    }

    /**
     * Match a request path and method to a route.
     *
     * Uses a method-indexed lookup table on the hot path so that only
     * routes accepting the requested HTTP method are scanned. On a miss,
     * falls back to scanning all routes for method-not-allowed detection
     * (cold path).
     *
     * @param Method $method The HTTP method
     * @param string $path The request path
     * @param string|null $host The request Host header (for host-based routing)
     * @throws RoutingException When no route matches or method is not allowed
     */
    public function match(Method $method, string $path, ?string $host = null): MatchedRoute
    {
        return $this->index->match($method, $path, $host, $this->routes);
    }

    /**
     * Get a route by name.
     */
    public function getByName(string $name): ?Route
    {
        return $this->namedRoutes[$name] ?? null;
    }

    /**
     * Generate a URL for a named route.
     *
     * When a DomainConfig is provided and the route's attributes include
     * a 'scope' that is mapped to a subdomain, generates a fully-qualified
     * URL with the correct subdomain (e.g., 'https://forum.example.com/threads/1').
     *
     * Without domain config, returns a relative path as before.
     *
     * @param array<string, string> $parameters
     * @throws InvalidArgumentException If route not found
     */
    public function url(string $name, array $parameters = [], ?DomainConfig $domainConfig = null): string
    {
        $route = $this->getByName($name)
            ?? throw new InvalidArgumentException(sprintf('Route "%s" not found', $name));

        return RouteUrlGenerator::generate($route, $parameters, $domainConfig);
    }

    /**
     * Get all registered routes.
     *
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * Get the number of registered routes.
     */
    public function count(): int
    {
        return count($this->routes);
    }

    /**
     * Lock the router (strict cache mode).
     *
     * When locked, any attempt to register routes throws RoutingException::routerLocked().
     */
    public function lock(): void
    {
        $this->locked = true;
    }

    /**
     * Register an explicit parameter-to-model binding.
     *
     * When the model binding middleware resolves route parameters, explicit
     * bindings take precedence over implicit type-hint resolution.
     *
     * @param class-string $modelClass
     * @param class-string|null $resolverClass
     */
    public function model(string $parameter, string $modelClass, ?string $resolverClass = null): self
    {
        $this->explicitBindings[] = new ExplicitBinding($parameter, $modelClass, $resolverClass);

        return $this;
    }

    /**
     * Register a full resource route set (7 routes).
     *
     * Generates: index, create, store, show, edit, update, destroy.
     *
     * @param string $name Resource name (e.g. 'photos'): used for URL prefix and route names
     * @param string $controller Controller class or handler prefix (e.g. 'App\Controller\PhotoController')
     * @param list<string> $middleware Middleware applied to all resource routes
     *
     * @throws RoutingException If the router is locked in strict cache mode
     */
    public function resource(string $name, string $controller, array $middleware = []): self
    {
        foreach (ResourceRegistrar::resourceRoutes($name, $controller, $middleware) as $route) {
            $this->add($route);
        }

        return $this;
    }

    /**
     * Register an API resource route set (5 routes, no create/edit forms).
     *
     * Generates: index, store, show, update, destroy.
     *
     * @param string $name Resource name (e.g. 'photos'): used for URL prefix and route names
     * @param string $controller Controller class or handler prefix
     * @param list<string> $middleware Middleware applied to all resource routes
     *
     * @throws RoutingException If the router is locked in strict cache mode
     */
    public function apiResource(string $name, string $controller, array $middleware = []): self
    {
        foreach (ResourceRegistrar::apiResourceRoutes($name, $controller, $middleware) as $route) {
            $this->add($route);
        }

        return $this;
    }

    /**
     * Load pre-built routes (e.g. reconstructed from cache by the composition root).
     *
     * @param list<Route> $routes
     */
    public function loadRoutes(array $routes): void
    {
        foreach ($routes as $route) {
            $this->routes[] = $route;

            if ($route->name !== null) {
                $this->namedRoutes[$route->name] = $route;
            }

            $this->index->index($route);
        }
    }
}
