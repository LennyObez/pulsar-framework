<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use InvalidArgumentException;
use Pulsar\Api\Api;
use Pulsar\Config\DomainConfig;
use Pulsar\Http\Method;
use Pulsar\Routing\Binding\ExplicitBinding;

use function array_values;
use function count;
use function is_string;
use function preg_replace;
use function rawurlencode;
use function sprintf;
use function str_replace;
use function strstr;
use function trim;

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
     * O(1) hash map for static routes (no dynamic segments).
     *
     * Indexed by HTTP method then normalized path, enabling constant-time
     * lookups for routes without parameters: the most common case.
     *
     * @var array<string, array<string, Route>>
     */
    private array $staticRoutes = [];

    /**
     * F2.21: first-segment bucket index for dynamic routes.
     *
     * Maps `method => firstStaticSegment => list<Route>` so the
     * dynamic-route scan for `/users/{id}` requests only walks
     * routes whose pattern begins with the `users` segment,
     * instead of every dynamic route registered for that
     * method. For a typical REST API with N entities × ~5
     * routes each, the bucket narrows the scan from O(5N) to
     * O(5) — a partial-trie win without the full trie's
     * implementation surface.
     *
     * The catch-all bucket `''` holds routes whose pattern
     * starts with a dynamic segment (e.g. `/{lang}/posts`) —
     * those still walk the full bucket because we can't pre-
     * partition by an unknown first segment. They are
     * comparatively rare in practice.
     *
     * @var array<string, array<string, list<Route>>>
     */
    private array $dynamicRouteBuckets = [];

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
        // Index static routes (no dynamic segments) for O(1) lookup
        if ($route->compiledPattern === null && $route->host === null) {
            $normalizedPath = '/' . trim($route->path, '/');
            foreach ($route->methods as $method) {
                $this->staticRoutes[$method->value][$normalizedPath] = $route;
            }
            return;
        }

        // F2.21: bucket dynamic routes by their first static
        // segment. A pattern like `/users/{id}` buckets under
        // `users`; `/{lang}/posts` buckets under `''` (catch-
        // all). At match() time we scan only the bucket that
        // matches the request path's first segment plus the
        // catch-all — narrows the dynamic scan from O(N) to
        // O(per-bucket) for the typical REST shape.
        $firstSegment = $this->firstStaticSegment($route->path);
        foreach ($route->methods as $method) {
            $this->dynamicRouteBuckets[$method->value][$firstSegment][] = $route;
        }
    }

    /**
     * F2.21: extract the first static (non-`{...}`) path segment
     * of a route pattern. `/users/{id}` → `users`,
     * `/api/v1/users/{id}` → `api`, `/{lang}/posts` → `''`,
     * `/` → `''`.
     */
    private function firstStaticSegment(string $path): string
    {
        $normalized = trim($path, '/');
        if ($normalized === '') {
            return '';
        }

        $first = strstr($normalized, '/', true);
        $first = $first === false ? $normalized : $first;

        // A `{...}` first segment can't be pre-partitioned; the
        // route lives in the catch-all bucket.
        if ($first === '' || $first[0] === '{') {
            return '';
        }

        return $first;
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
        $normalizedPath = '/' . trim($path, '/');

        // Fast path: O(1) lookup for static routes without host constraints
        if ($host === null && isset($this->staticRoutes[$method->value][$normalizedPath])) {
            return new MatchedRoute($this->staticRoutes[$method->value][$normalizedPath], []);
        }

        // F2.21: narrow the dynamic-route scan to the first-
        // segment bucket of the request path + the catch-all
        // bucket (routes whose pattern starts with `{...}`).
        // For an API with N entities × ~5 routes each, this
        // typically cuts the scan from O(5N) to O(5 + |catch-all|).
        $requestFirstSegment = $this->firstStaticSegment($normalizedPath);
        $methodBuckets = $this->dynamicRouteBuckets[$method->value] ?? [];

        /** @var list<Route> $candidates */
        $candidates = [];
        if (isset($methodBuckets[$requestFirstSegment])) {
            $candidates = $methodBuckets[$requestFirstSegment];
        }
        if ($requestFirstSegment !== '' && isset($methodBuckets[''])) {
            $candidates = [...$candidates, ...$methodBuckets['']];
        }

        // F2.21: when the request carries a host header, the
        // static-route fast path was skipped above — but a
        // host-less static route can still be a legitimate
        // fallback for the host. Append the matching static
        // route to the candidates so the host-aware scan can
        // find it.
        if ($host !== null && isset($this->staticRoutes[$method->value][$normalizedPath])) {
            $candidates[] = $this->staticRoutes[$method->value][$normalizedPath];
        }

        foreach ($candidates as $route) {
            $matchResult = $this->matchRouteAgainstHostAndPath($route, $path, $host);
            if ($matchResult !== null) {
                return new MatchedRoute($route, $matchResult);
            }
        }

        // Cold path: no match found: scan all routes for 405 detection
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

        $path = $route->path;

        if ($parameters !== []) {
            $search = [];
            $replace = [];

            foreach ($parameters as $key => $value) {
                // RFC 3986 §2 path-segment encoding (F2.7): a raw value
                // containing `/`, `?`, `#`, `..`, ` `, or any reserved
                // byte would otherwise punch out of its segment and
                // either change the route taken or become a path-
                // traversal vector against routes downstream of this URL.
                // `rawurlencode` percent-encodes everything outside the
                // unreserved set so a parameter like `'../admin'` is
                // rendered as `%2E%2E%2Fadmin` and stays inside its slot.
                $encoded = rawurlencode($value);
                $search[] = '{' . $key . '}';
                $search[] = '{' . $key . '?}';
                $replace[] = $encoded;
                $replace[] = $encoded;
            }

            $path = str_replace($search, $replace, $path);
        }

        // Remove unfilled optional parameters
        $replaced = preg_replace('#\{[a-zA-Z_][a-zA-Z0-9_]*\?}#', '', $path);
        $path = $replaced ?? $path;

        // Clean up double slashes
        $replaced = preg_replace('#//+#', '/', $path);
        $path = $replaced ?? $path;

        $relativePath = '/' . trim($path, '/');

        // Domain-aware URL generation: if a route has a scope attribute and
        // that scope is mapped to a subdomain, generate a fully-qualified URL
        if ($domainConfig !== null && $domainConfig->hasSubdomainMappings()) {
            $scope = $route->attributes['scope'] ?? null;

            if (is_string($scope)) {
                $subdomain = $domainConfig->subdomainForScope($scope);

                if ($subdomain !== null) {
                    return $domainConfig->scheme . '://' . $subdomain . '.' . $domainConfig->defaultDomain . $relativePath;
                }
            }
        }

        return $relativePath;
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
        $prefix = '/' . trim($name, '/');
        $paramName = $this->singularize($name);
        $paramSegment = '/{' . $paramName . '}';

        $this->add(new Route([Method::GET, Method::HEAD], $prefix, [$controller, 'index'], $name . '.index', middleware: $middleware));
        $this->add(new Route([Method::GET, Method::HEAD], $prefix . '/create', [$controller, 'create'], $name . '.create', middleware: $middleware));
        $this->add(new Route([Method::POST], $prefix, [$controller, 'store'], $name . '.store', middleware: $middleware));
        $this->add(new Route([Method::GET, Method::HEAD], $prefix . $paramSegment, [$controller, 'show'], $name . '.show', middleware: $middleware));
        $this->add(new Route([Method::GET, Method::HEAD], $prefix . $paramSegment . '/edit', [$controller, 'edit'], $name . '.edit', middleware: $middleware));
        $this->add(new Route([Method::PUT, Method::PATCH], $prefix . $paramSegment, [$controller, 'update'], $name . '.update', middleware: $middleware));
        $this->add(new Route([Method::DELETE], $prefix . $paramSegment, [$controller, 'destroy'], $name . '.destroy', middleware: $middleware));

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
        $prefix = '/' . trim($name, '/');
        $paramName = $this->singularize($name);
        $paramSegment = '/{' . $paramName . '}';

        $this->add(new Route([Method::GET, Method::HEAD], $prefix, [$controller, 'index'], $name . '.index', middleware: $middleware));
        $this->add(new Route([Method::POST], $prefix, [$controller, 'store'], $name . '.store', middleware: $middleware));
        $this->add(new Route([Method::GET, Method::HEAD], $prefix . $paramSegment, [$controller, 'show'], $name . '.show', middleware: $middleware));
        $this->add(new Route([Method::PUT, Method::PATCH], $prefix . $paramSegment, [$controller, 'update'], $name . '.update', middleware: $middleware));
        $this->add(new Route([Method::DELETE], $prefix . $paramSegment, [$controller, 'destroy'], $name . '.destroy', middleware: $middleware));

        return $this;
    }

    /**
     * Naive English pluralization: derive singular from plural resource name.
     *
     * Handles common suffixes: -ies -> -y, -ses/-xes/-zes/-shes/-ches -> drop suffix, -s -> drop s.
     * For irregular nouns, the user should specify the parameter name via route constraints.
     */
    private function singularize(string $name): string
    {
        // Only take the last segment if nested (e.g. 'admin/photos' -> 'photos')
        if (str_contains($name, '/')) {
            $segments = explode('/', trim($name, '/'));
            $name = end($segments);
        }

        if (str_ends_with($name, 'ies')) {
            return substr($name, 0, -3) . 'y';
        }

        if (str_ends_with($name, 'ses') || str_ends_with($name, 'xes') || str_ends_with($name, 'zes')
            || str_ends_with($name, 'shes') || str_ends_with($name, 'ches')) {
            return substr($name, 0, -2);
        }

        if (str_ends_with($name, 's') && !str_ends_with($name, 'ss')) {
            return substr($name, 0, -1);
        }

        return $name;
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
