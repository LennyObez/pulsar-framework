<?php

declare(strict_types=1);

namespace Pulsar\Dev\Toolbar;

use Pulsar\Api\Internal;

use function count;
use function number_format;

/**
 * Collects metrics and data for the dev toolbar display.
 *
 * Immutable value object built during request processing. The toolbar
 * middleware reads this to render the floating bar at the bottom of HTML pages.
 */
#[Internal]
final readonly class ToolbarData
{
    /**
     * @param float $requestTimeMs Total request processing time in milliseconds
     * @param int $memoryPeakBytes Peak memory usage in bytes
     * @param string $phpVersion Current PHP version
     * @param list<array{sql: string, time_ms: float}> $queries Executed database queries
     * @param int $cacheHits Number of cache hits during the request
     * @param int $cacheMisses Number of cache misses during the request
     * @param list<string> $loadedTemplates Templates rendered during the request
     * @param string|null $routeName Matched route name (if any)
     * @param string|null $controller Controller that handled the request
     * @param string|null $routePattern Route path pattern
     */
    public function __construct(
        public float $requestTimeMs,
        public int $memoryPeakBytes,
        public string $phpVersion,
        public array $queries = [],
        public int $cacheHits = 0,
        public int $cacheMisses = 0,
        public array $loadedTemplates = [],
        public ?string $routeName = null,
        public ?string $controller = null,
        public ?string $routePattern = null,
    ) {}

    /**
     * Number of database queries executed.
     */
    public function queryCount(): int
    {
        return count($this->queries);
    }

    /**
     * Total database query time in milliseconds.
     */
    public function totalQueryTimeMs(): float
    {
        $total = 0.0;

        foreach ($this->queries as $query) {
            $total += $query['time_ms'];
        }

        return $total;
    }

    /**
     * Format memory as a human-readable string.
     */
    public function formattedMemory(): string
    {
        $bytes = $this->memoryPeakBytes;

        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        if ($bytes < 1073741824) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }

        return number_format($bytes / 1073741824, 1) . ' GB';
    }

    /**
     * Format request time for display.
     */
    public function formattedRequestTime(): string
    {
        return number_format($this->requestTimeMs, 1) . ' ms';
    }
}
