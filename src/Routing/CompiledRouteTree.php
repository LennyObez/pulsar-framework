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
 */
#[Api(since: '1.0.0')]
final class CompiledRouteTree
{
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
    ) {}

    /**
     * Match a request to a compiled route.
     *
     * @throws RoutingException When no route matches or method is not allowed
     */
    public function match(Method $method, string $path, ?string $host = null): MatchedRoute
    {
        $normalizedPath = '/' . trim($path, '/');
        $methodValue = $method->value;

        // Fast path: O(1) static route lookup (no host constraint)
        if ($host === null && isset($this->staticTable[$methodValue][$normalizedPath])) {
            $entry = $this->staticTable[$methodValue][$normalizedPath];

            return new MatchedRoute($entry->toRoute(), []);
        }

        // Dynamic route matching: iterate pre-compiled patterns for the method
        $candidates = $this->dynamicRoutes[$methodValue] ?? [];

        foreach ($candidates as $dynamic) {
            if ($host !== null && $dynamic->host !== null) {
                if (!$this->matchesHost($dynamic->host, $dynamic->hostPattern, $host)) {
                    continue;
                }
            } elseif ($host === null && $dynamic->host !== null) {
                continue;
            }

            if (preg_match($dynamic->pattern, $normalizedPath, $matches)) {
                $params = $this->extractNamedParams($matches);

                if ($host !== null && $dynamic->hostPattern !== null) {
                    if (preg_match($dynamic->hostPattern, $host, $hostMatches)) {
                        $params = [...$this->extractNamedParams($hostMatches), ...$params];
                    }
                }

                return new MatchedRoute($dynamic->entry->toRoute(), $params);
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
            if (is_string($key) && $value !== '') {
                $params[$key] = $value;
            }
        }

        return $params;
    }
}
