<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use InvalidArgumentException;
use Pulsar\Api\Api;
use Pulsar\Http\Method;
use Pulsar\Routing\Binding\ExplicitBinding;

use function array_values;
use function count;
use function sprintf;

/**
 * HTTP router for route registration and matching.
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
     * Method-indexed lookup table for fast matching.
     *
     * Maps each HTTP method value to the list of routes that accept it.
     * Built incrementally at registration time so that `match()` only
     * scans routes for the requested method on the hot path.
     *
     * @var array<string, list<Route>>
     */
    private array $routesByMethod = [];

    /**
     * O(1) hash map for static routes (no dynamic segments).
     *
     * Indexed by HTTP method then normalized path, enabling constant-time
     * lookups for routes without parameters — the most common case.
     *
     * @var array<string, array<string, Route>>
     */
    private array $staticRoutes = [];

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

        $this->indexRouteByMethod($route);

        return $this;
    }

    /**
     * Add a route to the method-indexed and static lookup tables.
     */
    private function indexRouteByMethod(Route $route): void
    {
        foreach ($route->methods as $method) {
            $this->routesByMethod[$method->value][] = $route;
        }

        // Index static routes (no dynamic segments) for O(1) lookup
        if ($route->compiledPattern === null && $route->host === null) {
            $normalizedPath = '/' . trim($route->path, '/');
            foreach ($route->methods as $method) {
                $this->staticRoutes[$method->value][$normalizedPath] = $route;
            }
        }
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
     * @param callable|class-string|array{0: class-string, 1: string} $handler
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
     * @param callable|class-string|array{0: class-string, 1: string} $handler
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
     * @param callable|class-string|array{0: class-string, 1: string} $handler
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
     * @param callable|class-string|array{0: class-string, 1: string} $handler
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
     * @param callable|class-string|array{0: class-string, 1: string} $handler
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
     * @param callable|class-string|array{0: class-string, 1: string} $handler
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
        $normalizedPath = '/' . trim($path, '/');

        // Fast path: O(1) lookup for static routes without host constraints
        if ($host === null && isset($this->staticRoutes[$method->value][$normalizedPath])) {
            return new MatchedRoute($this->staticRoutes[$method->value][$normalizedPath], []);
        }

        // Hot path: only scan routes that accept the requested method
        $candidates = $this->routesByMethod[$method->value] ?? [];

        foreach ($candidates as $route) {
            $matchResult = $this->matchRouteAgainstHostAndPath($route, $path, $host);
            if ($matchResult !== null) {
                return new MatchedRoute($route, $matchResult);
            }
        }

        // Cold path: no match found — scan all routes for 405 detection
        $pathMatches = [];

        foreach ($this->routes as $route) {
            $matchResult = $this->matchRouteAgainstHostAndPath($route, $path, $host);
            if ($matchResult !== null) {
                $pathMatches[] = $route;
            }
        }

        if ($pathMatches !== []) {
            $allowedMethodsMap = [];

            foreach ($pathMatches as $route) {
                foreach ($route->methods as $m) {
                    $allowedMethodsMap[$m->value] = $m;
                }
            }

            throw RoutingException::methodNotAllowed($path, $method, array_values($allowedMethodsMap));
        }

        throw RoutingException::notFound($path);
    }

    /**
     * Check if a route matches the given host and path.
     *
     * Returns merged host+path parameters on match, null on no match.
     *
     * @return array<string, string>|null
     */
    private function matchRouteAgainstHostAndPath(Route $route, string $path, ?string $host): ?array
    {
        if ($host !== null) {
            $hostParams = $route->matchesHost($host);
            if ($hostParams === null) {
                return null;
            }
        } else {
            $hostParams = [];
            if ($route->host !== null) {
                return null;
            }
        }

        $params = $route->matchesPath($path);
        if ($params === null) {
            return null;
        }

        return [...$hostParams, ...$params];
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
     * @param array<string, string> $parameters
     * @throws InvalidArgumentException If route not found
     */
    public function url(string $name, array $parameters = []): string
    {
        $route = $this->getByName($name)
            ?? throw new InvalidArgumentException(sprintf('Route "%s" not found', $name));

        $path = $route->path;

        if ($parameters !== []) {
            $search = [];
            $replace = [];

            foreach ($parameters as $key => $value) {
                $search[] = '{' . $key . '}';
                $search[] = '{' . $key . '?}';
                $replace[] = $value;
                $replace[] = $value;
            }

            $path = str_replace($search, $replace, $path);
        }

        // Remove unfilled optional parameters
        $replaced = preg_replace('#\{[a-zA-Z_][a-zA-Z0-9_]*\?}#', '', $path);
        $path = $replaced ?? $path;

        // Clean up double slashes
        $replaced = preg_replace('#//+#', '/', $path);
        $path = $replaced ?? $path;

        return '/' . trim($path, '/');
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

            $this->indexRouteByMethod($route);
        }
    }
}
