<?php

declare(strict_types=1);

namespace Pulsar\Dev\Toolbar;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Routing\MatchedRoute;

use function is_array;
use function is_object;
use function is_string;
use function memory_get_peak_usage;

use const PHP_VERSION;

/**
 * Collects runtime metrics from the request lifecycle for the dev toolbar.
 *
 * Aggregates database queries, cache stats, loaded templates, and route
 * information into a ToolbarData value object.
 */
#[Internal]
final class ToolbarDataCollector
{
    /** @var list<array{sql: string, time_ms: float}> */
    private array $queries = [];

    private int $cacheHits = 0;
    private int $cacheMisses = 0;

    /** @var list<string> */
    private array $loadedTemplates = [];

    /**
     * Record a database query.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function recordQuery(string $sql, float $timeMs): void
    {
        $this->queries[] = ['sql' => $sql, 'time_ms' => $timeMs];
    }

    /**
     * Record a cache hit.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function recordCacheHit(): void
    {
        $this->cacheHits++;
    }

    /**
     * Record a cache miss.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function recordCacheMiss(): void
    {
        $this->cacheMisses++;
    }

    /**
     * Record a loaded template.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function recordTemplate(string $templatePath): void
    {
        $this->loadedTemplates[] = $templatePath;
    }

    /**
     * Collect all metrics into a ToolbarData object.
     */
    public function collect(ServerRequestInterface $request, float $requestTimeMs): ToolbarData
    {
        /** @var mixed $matchedRoute */
        $matchedRoute = $request->getAttribute('_matched_route');
        $routeName = null;
        $controller = null;
        $routePattern = null;

        if ($matchedRoute instanceof MatchedRoute) {
            $routeName = $matchedRoute->getName();
            $routePattern = $matchedRoute->route->path;

            /** @var mixed $handler */
            $handler = $matchedRoute->getHandler();
            if (is_string($handler)) {
                $controller = $handler;
            } elseif (is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[1])) {
                $controllerClass = is_string($handler[0]) ? $handler[0] : (is_object($handler[0]) ? $handler[0]::class : '');
                $controller = $controllerClass . '::' . $handler[1];
            }
        }

        return new ToolbarData(
            requestTimeMs: $requestTimeMs,
            memoryPeakBytes: memory_get_peak_usage(true),
            phpVersion: PHP_VERSION,
            queries: $this->queries,
            cacheHits: $this->cacheHits,
            cacheMisses: $this->cacheMisses,
            loadedTemplates: $this->loadedTemplates,
            routeName: $routeName,
            controller: $controller,
            routePattern: $routePattern,
        );
    }

    /**
     * Reset all collected data (useful between requests in persistent runtimes).
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function reset(): void
    {
        $this->queries = [];
        $this->cacheHits = 0;
        $this->cacheMisses = 0;
        $this->loadedTemplates = [];
    }
}
