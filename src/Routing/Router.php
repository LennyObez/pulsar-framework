<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use function count;
use function in_array;

use InvalidArgumentException;
use Pulsar\Api\Api;
use Pulsar\Http\Method;

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
     * Add a route to the method-indexed lookup table.
     */
    private function indexRouteByMethod(Route $route): void
    {
        foreach ($route->methods as $method) {
            $this->routesByMethod[$method->value][] = $route;
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
            $allowedMethods = [];
            foreach ($pathMatches as $route) {
                foreach ($route->methods as $m) {
                    if (!in_array($m, $allowedMethods, true)) {
                        $allowedMethods[] = $m;
                    }
                }
            }

            throw RoutingException::methodNotAllowed($path, $method, $allowedMethods);
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

        foreach ($parameters as $key => $value) {
            $path = str_replace('{' . $key . '}', $value, $path);
            $path = str_replace('{' . $key . '?}', $value, $path);
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
