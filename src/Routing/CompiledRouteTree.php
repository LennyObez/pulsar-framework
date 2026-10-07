<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use Pulsar\Api\Api;
use Pulsar\Http\Method;

use function array_keys;
use function count;
use function is_string;
use function preg_match;
use function trim;

/**
 * Pre-compiled route lookup structure for O(1) static route matching
 * and fast trie-based dynamic route resolution.
 *
 * Generated at build time by RouteCompiler. At runtime, static routes
 * resolve via hash table lookup (no regex). Dynamic routes are matched
 * via a prefix trie that narrows candidates before any regex evaluation.
 * @api
 */
#[Api(since: '1.0.0')]
final class CompiledRouteTree
{
    /**
     * Whether any compiled route carries a host constraint.
     *
     * Mirrors {@see Router::$hasHostConstrainedRoutes}: when no route is
     * host-constrained, the request `Host` header cannot change which route
     * matches, so the O(1) static fast path stays correct with a `Host` present.
     * Computed once at construction — the tree is built at `pulsar optimize`
     * time or restored from the preloaded cache, and the scan is one pass over
     * a list the restore has just walked anyway.
     */
    private readonly bool $hasHostConstrainedRoutes;

    /**
     * @param array<string, array<string, CompiledRouteEntry>> $staticTable
     *        method → path → entry (O(1) lookup)
     * @param array<string, list<CompiledDynamicRoute>> $dynamicRoutes
     *        method → ordered list of dynamic routes with pre-compiled patterns
     * @param array<string, CompiledRouteEntry> $namedRoutes
     *        name → entry (for URL generation)
     */
    public function __construct(
        private readonly array $staticTable,
        private readonly array $dynamicRoutes,
        private readonly array $namedRoutes,
    ) {
        $this->hasHostConstrainedRoutes = $this->detectHostConstrainedRoutes($dynamicRoutes);
    }

    /**
     * Match a request to a compiled route.
     *
     * @throws RoutingException When no route matches or method is not allowed
     */
    public function match(Method $method, string $path, ?string $host = null): MatchedRoute
    {
        $normalizedPath = '/' . trim($path, '/');
        $methodValue = $method->value;

        // Strip the port so a `Host: api.example.com:8000` header matches a route
        // declared against `api.example.com` (shared with the live Router).
        $host = $host === null ? null : HostNormalizer::stripPort($host);

        $staticEntry = $this->staticTable[$methodValue][$normalizedPath] ?? null;

        // Fast path: O(1) static route lookup. Safe when the request carries no
        // host, OR when no compiled route is host-constrained.
        if ($staticEntry !== null && ($host === null || !$this->hasHostConstrainedRoutes)) {
            return new MatchedRoute($staticEntry->toRoute(), []);
        }

        // Dynamic route matching: iterate pre-compiled patterns for the method
        $candidates = $this->dynamicRoutes[$methodValue] ?? [];

        // Same three-tier precedence as {@see Router::match()} — the two
        // matchers advertise equivalence, so the order has to be the same one:
        //
        //   1. a route constrained to a host matching this request's Host;
        //   2. the host-less static (literal-path) route;
        //   3. host-less dynamic routes, first-registered-wins (ADR-0034).
        //
        // Reaching here with a static entry means a Host header arrived and the
        // table holds at least one host-constrained route. Scanning the whole
        // candidate list first let a later-registered `/users/{id}` answer
        // `/users/profile` for exactly those requests — the client's own header
        // choosing the handler, and with it the route's access declaration.
        if ($staticEntry !== null) {
            foreach ($candidates as $dynamic) {
                if ($dynamic->host === null) {
                    continue;
                }

                $matched = $this->matchDynamicRoute($dynamic, $normalizedPath, $host);

                if ($matched !== null) {
                    return $matched;
                }
            }

            // Tier 2 beats every tier-3 candidate by definition: a literal path
            // is the most specific match a placeholder route could contest.
            return new MatchedRoute($staticEntry->toRoute(), []);
        }

        foreach ($candidates as $dynamic) {
            $matched = $this->matchDynamicRoute($dynamic, $normalizedPath, $host);

            if ($matched !== null) {
                return $matched;
            }
        }

        // 405 detection: check if path matches under a different method
        $allowedMethods = $this->findAllowedMethods($normalizedPath, $host);

        if ($allowedMethods !== []) {
            throw RoutingException::methodNotAllowed($path, $method, $allowedMethods);
        }

        throw RoutingException::notFound($path);
    }

    /**
     * Get a named route entry for URL generation.
     */
    public function getByName(string $name): ?CompiledRouteEntry
    {
        return $this->namedRoutes[$name] ?? null;
    }

    /**
     * Get total number of compiled routes.
     */
    public function count(): int
    {
        $count = 0;

        foreach ($this->staticTable as $routes) {
            $count += count($routes);
        }

        foreach ($this->dynamicRoutes as $routes) {
            $count += count($routes);
        }

        return $count;
    }

    /**
     * Get all method keys that have routes.
     *
     * @return list<string>
     */
    public function methods(): array
    {
        $methods = [...array_keys($this->staticTable), ...array_keys($this->dynamicRoutes)];

        return array_values(array_unique($methods));
    }

    /**
     * Match one pre-compiled dynamic route against a path and host.
     *
     * Returns null when the route's host constraint excludes this request or
     * the path pattern does not match.
     */
    private function matchDynamicRoute(
        CompiledDynamicRoute $dynamic,
        string $normalizedPath,
        ?string $host,
    ): ?MatchedRoute {
        if ($dynamic->host !== null) {
            // Host-constrained route: matches only when the request carries a
            // host and that host matches the route's host pattern.
            if ($host === null || !$this->matchesHost($dynamic->host, $dynamic->hostPattern, $host)) {
                return null;
            }
        }

        if (!preg_match($dynamic->pattern, $normalizedPath, $matches)) {
            return null;
        }

        $params = $this->extractNamedParams($matches);

        if ($host !== null && $dynamic->hostPattern !== null) {
            if (preg_match($dynamic->hostPattern, $host, $hostMatches)) {
                $params = [...$this->extractNamedParams($hostMatches), ...$params];
            }
        }

        return new MatchedRoute($dynamic->entry->toRoute(), $params);
    }

    /**
     * Whether any route in the compiled dynamic table carries a host constraint.
     *
     * @param array<string, list<CompiledDynamicRoute>> $dynamicRoutes
     */
    private function detectHostConstrainedRoutes(array $dynamicRoutes): bool
    {
        foreach ($dynamicRoutes as $routes) {
            foreach ($routes as $dynamic) {
                if ($dynamic->host !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<Method>
     */
    private function findAllowedMethods(string $normalizedPath, ?string $host): array
    {
        $allowed = [];

        // Check static table
        foreach ($this->staticTable as $methodValue => $routes) {
            if (isset($routes[$normalizedPath])) {
                $allowed[$methodValue] = Method::from($methodValue);
            }
        }

        // Check dynamic routes
        foreach ($this->dynamicRoutes as $methodValue => $routes) {
            if (isset($allowed[$methodValue])) {
                continue;
            }

            foreach ($routes as $dynamic) {
                if ($host === null && $dynamic->host !== null) {
                    continue;
                }

                if ($host !== null && $dynamic->host !== null) {
                    if (!$this->matchesHost($dynamic->host, $dynamic->hostPattern, $host)) {
                        continue;
                    }
                }

                if (preg_match($dynamic->pattern, $normalizedPath)) {
                    $allowed[$methodValue] = Method::from($methodValue);
                    break;
                }
            }
        }

        return array_values($allowed);
    }

    private function matchesHost(string $hostDef, ?string $hostPattern, string $host): bool
    {
        if ($hostPattern !== null) {
            return (bool) preg_match($hostPattern, $host);
        }

        return strtolower($hostDef) === strtolower($host);
    }

    /**
     * @param array<int|string, string> $matches
     * @return array<string, string>
     */
    private function extractNamedParams(array $matches): array
    {
        $params = [];

        foreach ($matches as $key => $value) {
            // Keep empty-string captures (parity with Route::extractNamedParameters,
            // which filters by key only) so both matchers return identical params.
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }
}
