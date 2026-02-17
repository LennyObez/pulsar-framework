<?php

declare(strict_types=1);

namespace Pulsar\Observability\Profiler;

use Pulsar\Api\Api;

use function array_filter;
use function array_map;
use function array_slice;
use function array_sum;
use function array_values;
use function count;
use function round;
use function usort;

/**
 * Completed profile for a single request lifecycle.
 *
 * Contains the full timeline of profiled operations grouped by category.
 */
#[Api(since: '1.0.0')]
final readonly class RequestProfile
{
    /**
     * @param string $method HTTP method
     * @param string $path Request path
     * @param int $statusCode Response status code
     * @param float $totalMs Total request duration in milliseconds
     * @param list<ProfileEntry> $entries Timeline entries
     * @param int $queryCount Number of database queries executed
     * @param float $queryTimeMs Total database query time in milliseconds
     * @param int $cacheHits Number of cache hits
     * @param int $cacheMisses Number of cache misses
     * @param int $peakMemoryBytes Peak memory usage in bytes
     */
    public function __construct(
        public string $method,
        public string $path,
        public int $statusCode,
        public float $totalMs,
        public array $entries,
        public int $queryCount = 0,
        public float $queryTimeMs = 0.0,
        public int $cacheHits = 0,
        public int $cacheMisses = 0,
        public int $peakMemoryBytes = 0,
    ) {}

    /**
     * Get entries for a specific category.
     *
     * @return list<ProfileEntry>
     */
    public function entriesByCategory(string $category): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn(ProfileEntry $e): bool => $e->category === $category,
        ));
    }

    /**
     * Get the total time spent in a category in milliseconds.
     */
    public function categoryTimeMs(string $category): float
    {
        $entries = $this->entriesByCategory($category);

        return array_sum(array_map(
            static fn(ProfileEntry $e): float => $e->durationMs(),
            $entries,
        ));
    }

    /**
     * Get the cache hit rate as a percentage.
     */
    public function cacheHitRate(): float
    {
        $total = $this->cacheHits + $this->cacheMisses;

        if ($total === 0) {
            return 0.0;
        }

        return ($this->cacheHits / $total) * 100.0;
    }

    /**
     * Get entries sorted by start time (timeline order).
     *
     * @return list<ProfileEntry>
     */
    public function timeline(): array
    {
        $sorted = $this->entries;

        usort($sorted, static fn(ProfileEntry $a, ProfileEntry $b): int => $a->startNs <=> $b->startNs);

        return $sorted;
    }

    /**
     * Get the top N slowest entries.
     *
     * @return list<ProfileEntry>
     */
    public function slowest(int $limit = 5): array
    {
        $sorted = $this->entries;

        usort($sorted, static fn(ProfileEntry $a, ProfileEntry $b): int => $b->durationNs() <=> $a->durationNs());

        return array_slice($sorted, 0, $limit);
    }

    /**
     * Serialize to array for JSON API / Studio dashboard.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'path' => $this->path,
            'status_code' => $this->statusCode,
            'total_ms' => round($this->totalMs, 3),
            'query_count' => $this->queryCount,
            'query_time_ms' => round($this->queryTimeMs, 3),
            'cache_hits' => $this->cacheHits,
            'cache_misses' => $this->cacheMisses,
            'cache_hit_rate' => round($this->cacheHitRate(), 1),
            'peak_memory_bytes' => $this->peakMemoryBytes,
            'categories' => [
                'middleware' => round($this->categoryTimeMs('middleware'), 3),
                'routing' => round($this->categoryTimeMs('routing'), 3),
                'controller' => round($this->categoryTimeMs('controller'), 3),
                'database' => round($this->categoryTimeMs('database'), 3),
                'view' => round($this->categoryTimeMs('view'), 3),
            ],
            'timeline' => array_map(
                static fn(ProfileEntry $e): array => $e->toArray(),
                $this->timeline(),
            ),
            'entry_count' => count($this->entries),
        ];
    }
}
