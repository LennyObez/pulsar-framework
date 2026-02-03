<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use function count;
use function in_array;

use InvalidArgumentException;
use Pulsar\Http\Method;

use function sprintf;

/**
 * HTTP router for route registration and matching.
 */
final class Router
{
    /**
     * @var list<Route>
     */
    private array $routes = [];

    /**
     * @var array<string, Route>
     */
    private array $namedRoutes = [];

    /**
     * Add a route to the router.
     */
    public function add(Route $route): self
    {
        $this->routes[] = $route;

        if ($route->name !== null) {
            $this->namedRoutes[$route->name] = $route;
        }

        return $this;
    }

    /**
     * Add multiple routes from a group.
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
     */
    public function get(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(Route::get($path, $handler, $name));
    }

    /**
     * Register a POST route.
     *
     * @param callable|class-string|array{0: class-string, 1: string} $handler
     */
    public function post(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(Route::post($path, $handler, $name));
    }

    /**
     * Register a PUT route.
     *
     * @param callable|class-string|array{0: class-string, 1: string} $handler
     */
    public function put(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(Route::put($path, $handler, $name));
    }

    /**
     * Register a PATCH route.
     *
     * @param callable|class-string|array{0: class-string, 1: string} $handler
     */
    public function patch(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(Route::patch($path, $handler, $name));
    }

    /**
     * Register a DELETE route.
     *
     * @param callable|class-string|array{0: class-string, 1: string} $handler
     */
    public function delete(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(Route::delete($path, $handler, $name));
    }

    /**
     * Register a route matching any method.
     *
     * @param callable|class-string|array{0: class-string, 1: string} $handler
     */
    public function any(string $path, mixed $handler, ?string $name = null): self
    {
        return $this->add(Route::any($path, $handler, $name));
    }

    /**
     * Match a request path and method to a route.
     *
     * @param Method $method The HTTP method
     * @param string $path The request path
     * @param string|null $host The request Host header (for host-based routing)
     * @throws RoutingException When no route matches or method is not allowed
     */
    public function match(Method $method, string $path, ?string $host = null): MatchedRoute
    {
        $pathMatches = [];

        foreach ($this->routes as $route) {
            // Check host constraint first
            if ($host !== null) {
                $hostParams = $route->matchesHost($host);
                if ($hostParams === null) {
                    continue;
                }
            } else {
                $hostParams = [];
                // Skip routes that require a specific host when no host is provided
                if ($route->host !== null) {
                    continue;
                }
            }

            $params = $route->matchesPath($path);

            if ($params !== null) {
                $mergedParams = [...$hostParams, ...$params];
                $pathMatches[] = ['route' => $route, 'params' => $mergedParams];

                if ($route->matchesMethod($method)) {
                    return new MatchedRoute($route, $mergedParams);
                }
            }
        }

        // Path matched but method didn't
        if ($pathMatches !== []) {
            $allowedMethods = [];
            foreach ($pathMatches as $match) {
                foreach ($match['route']->methods as $m) {
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
        $route = $this->getByName($name);

        if ($route === null) {
            throw new InvalidArgumentException(sprintf('Route "%s" not found', $name));
        }

        $path = $route->path;

        foreach ($parameters as $key => $value) {
            $path = str_replace('{' . $key . '}', $value, $path);
            $path = str_replace('{' . $key . '?}', $value, $path);
        }

        // Remove unfilled optional parameters
        $replaced = preg_replace('#\{[a-zA-Z_][a-zA-Z0-9_]*\?\}#', '', $path);
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
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Get all named routes.
     *
     * @return array<string, Route>
     */
    public function getNamedRoutes(): array
    {
        return $this->namedRoutes;
    }

    /**
     * Get the number of registered routes.
     */
    public function count(): int
    {
        return count($this->routes);
    }
}
