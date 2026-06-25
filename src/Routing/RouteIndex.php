<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use Pulsar\Api\Internal;
use Pulsar\Http\Method;

use function array_values;
use function strstr;
use function trim;

/**
 * Fast-lookup index and matcher for the {@see Router}.
 *
 * Owns the method-indexed static-route table (O(1) lookups for parameterless
 * routes) and the F2.21 first-segment dynamic-route buckets, and performs the
 * hot-path match plus cold-path 405 detection. Extracted from Router so route
 * registration, matching, URL generation, and resource scaffolding are each a
 * single responsibility.
 */
#[Internal(reason: 'Router lookup/matching internals; use Router')]
final class RouteIndex
{
    /**
     * Method-indexed lookup table for static routes (no dynamic segments).
     *
     * Indexed by HTTP method then normalized path for constant-time lookups
     * of the most common case.
     *
     * @var array<string, array<string, Route>>
     */
    private array $staticRoutes = [];

    /**
     * F2.21: first-segment bucket index for dynamic routes.
     *
     * Maps `method => firstStaticSegment => list<Route>` so the dynamic-route
     * scan for `/users/{id}` requests only walks routes whose pattern begins
     * with the `users` segment. The catch-all bucket `''` holds routes whose
     * pattern starts with a dynamic segment (e.g. `/{lang}/posts`).
     *
     * @var array<string, array<string, list<Route>>>
     */
    private array $dynamicRouteBuckets = [];

    /**
     * Add a route to the method-indexed and static lookup tables.
     */
    public function index(Route $route): void
    {
        // Index static routes (no dynamic segments) for O(1) lookup
        if ($route->compiledPattern === null && $route->host === null) {
            $normalizedPath = '/' . trim($route->path, '/');
            foreach ($route->methods as $method) {
                $this->staticRoutes[$method->value][$normalizedPath] = $route;
            }
            return;
        }

        // F2.21: bucket dynamic routes by their first static segment. A pattern
        // like `/users/{id}` buckets under `users`; `/{lang}/posts` buckets
        // under `''` (catch-all). At match() time we scan only the bucket that
        // matches the request path's first segment plus the catch-all.
        $firstSegment = self::firstStaticSegment($route->path);
        foreach ($route->methods as $method) {
            $this->dynamicRouteBuckets[$method->value][$firstSegment][] = $route;
        }
    }

    /**
     * Match a request method/path/host to a route.
     *
     * On a miss, the cold path scans $allRoutes for method-not-allowed (405)
     * detection.
     *
     * @param list<Route> $allRoutes
     * @throws RoutingException When no route matches or the method is not allowed
     */
    public function match(Method $method, string $path, ?string $host, array $allRoutes): MatchedRoute
    {
        $normalizedPath = '/' . trim($path, '/');

        // Fast path: O(1) lookup for static routes without host constraints
        if ($host === null && isset($this->staticRoutes[$method->value][$normalizedPath])) {
            return new MatchedRoute($this->staticRoutes[$method->value][$normalizedPath], []);
        }

        // F2.21: narrow the dynamic-route scan to the first-segment bucket of
        // the request path + the catch-all bucket (routes whose pattern starts
        // with `{...}`).
        $requestFirstSegment = self::firstStaticSegment($normalizedPath);
        $methodBuckets = $this->dynamicRouteBuckets[$method->value] ?? [];

        /** @var list<Route> $candidates */
        $candidates = [];
        if (isset($methodBuckets[$requestFirstSegment])) {
            $candidates = $methodBuckets[$requestFirstSegment];
        }
        if ($requestFirstSegment !== '' && isset($methodBuckets[''])) {
            $candidates = [...$candidates, ...$methodBuckets['']];
        }

        // F2.21: when the request carries a host header, the static-route fast
        // path was skipped above — but a host-less static route can still be a
        // legitimate fallback for the host. Append the matching static route to
        // the candidates so the host-aware scan can find it.
        if ($host !== null && isset($this->staticRoutes[$method->value][$normalizedPath])) {
            $candidates[] = $this->staticRoutes[$method->value][$normalizedPath];
        }

        foreach ($candidates as $route) {
            $matchResult = self::matchRouteAgainstHostAndPath($route, $path, $host);
            if ($matchResult !== null) {
                return new MatchedRoute($route, $matchResult);
            }
        }

        // Cold path: no match found: scan all routes for 405 detection
        $pathMatches = [];

        foreach ($allRoutes as $route) {
            $matchResult = self::matchRouteAgainstHostAndPath($route, $path, $host);
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
     * F2.21: extract the first static (non-`{...}`) path segment of a route
     * pattern. `/users/{id}` → `users`, `/api/v1/users/{id}` → `api`,
     * `/{lang}/posts` → `''`, `/` → `''`.
     */
    private static function firstStaticSegment(string $path): string
    {
        $normalized = trim($path, '/');
        if ($normalized === '') {
            return '';
        }

        $first = strstr($normalized, '/', true);
        $first = $first === false ? $normalized : $first;

        // A `{...}` first segment can't be pre-partitioned; the route lives in
        // the catch-all bucket.
        if ($first === '' || $first[0] === '{') {
            return '';
        }

        return $first;
    }

    /**
     * Check if a route matches the given host and path.
     *
     * Returns merged host+path parameters on match, null on no match.
     *
     * @return array<string, string>|null
     */
    private static function matchRouteAgainstHostAndPath(Route $route, string $path, ?string $host): ?array
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
}
