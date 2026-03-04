<?php

declare(strict_types=1);

namespace Pulsar\Observability\Profiler;

use Pulsar\Api\Api;

use function array_splice;
use function count;
use function hrtime;
use function memory_get_peak_usage;
use function round;

/**
 * Per-request performance profiler.
 *
 * Records a timeline of operations (middleware, routing, controller, DB, view)
 * with exact timings, query counts, cache statistics, and memory usage.
 *
 * Usage:
 *   $timer = $profiler->start('middleware', 'auth');
 *   // ... work ...
 *   $profiler->stop($timer);
 *
 * At request end, call finish() to produce a RequestProfile snapshot.
 */
#[Api(since: '1.0.0')]
final class RequestProfiler
{
    private int $requestStartNs;

    /** @var list<ProfileEntry> */
    private array $entries = [];

    /** @var array<int, array{category: string, label: string, startNs: int, metadata: array<string, scalar>}> */
    private array $activeTimers = [];

    private int $nextTimerId = 0;

    private int $queryCount = 0;
    private float $queryTimeMs = 0.0;
    private int $cacheHits = 0;
    private int $cacheMisses = 0;

    private bool $enabled;

    /** @var int Maximum stored entries to prevent memory bloat */
    private int $maxEntries;

    /** @var list<RequestProfile> Stored profiles for recent requests */
    private array $profiles = [];
    private int $maxProfiles;

    public function __construct(
        bool $enabled = true,
        int $maxEntries = 512,
        int $maxProfiles = 50,
    ) {
        $this->enabled = $enabled;
        $this->maxEntries = $maxEntries;
        $this->maxProfiles = $maxProfiles;
        $this->requestStartNs = hrtime(true);
    }

    /**
     * Begin a new request profiling cycle.
     */
    public function begin(): void
    {
        $this->requestStartNs = hrtime(true);
        $this->entries = [];
        $this->activeTimers = [];
        $this->nextTimerId = 0;
        $this->queryCount = 0;
        $this->queryTimeMs = 0.0;
        $this->cacheHits = 0;
        $this->cacheMisses = 0;
    }

    /**
     * Start a timer for a profiled operation.
     *
     * @param string $category One of: middleware, routing, controller, database, view, custom
     * @param string $label Human-readable label for the operation
     * @param array<string, scalar> $metadata Additional context
     * @return int Timer ID: pass to stop()
     */
    public function start(string $category, string $label, array $metadata = []): int
    {
        if (!$this->enabled) {
            return -1;
        }

        $id = $this->nextTimerId++;

        $this->activeTimers[$id] = [
            'category' => $category,
            'label' => $label,
            'startNs' => (int) hrtime(true),
            'metadata' => $metadata,
        ];

        return $id;
    }

    /**
     * Stop a timer and record the entry.
     *
     * @param array<string, scalar> $extraMetadata Merged with start metadata
     */
    public function stop(int $timerId, array $extraMetadata = []): void
    {
        if (!$this->enabled || $timerId === -1) {
            return;
        }

        if (!isset($this->activeTimers[$timerId])) {
            return;
        }

        $timer = $this->activeTimers[$timerId];
        $endNs = hrtime(true);

        $metadata = [...$timer['metadata'], ...$extraMetadata];

        $this->addEntry(new ProfileEntry(
            category: $timer['category'],
            label: $timer['label'],
            startNs: $timer['startNs'],
            endNs: $endNs,
            metadata: $metadata,
        ));

        unset($this->activeTimers[$timerId]);
    }

    /**
     * Record a database query execution.
     */
    public function recordQuery(string $sql, float $durationMs, int $rowCount = 0): void
    {
        if (!$this->enabled) {
            return;
        }

        ++$this->queryCount;
        $this->queryTimeMs += $durationMs;

        $durationNs = (int) ($durationMs * 1_000_000);

        $this->addEntry(new ProfileEntry(
            category: 'database',
            label: $this->truncateSql($sql),
            startNs: hrtime(true) - $durationNs,
            endNs: hrtime(true),
            metadata: ['rows' => $rowCount, 'duration_ms' => round($durationMs, 3)],
        ));
    }

    /**
     * Record a cache hit.
     */
    public function recordCacheHit(string $key): void
    {
        if (!$this->enabled) {
            return;
        }

        ++$this->cacheHits;
    }

    /**
     * Record a cache miss.
     */
    public function recordCacheMiss(string $key): void
    {
        if (!$this->enabled) {
            return;
        }

        ++$this->cacheMisses;
    }

    /**
     * Finish profiling and produce the request profile.
     */
    public function finish(string $method, string $path, int $statusCode): RequestProfile
    {
        $totalNs = hrtime(true) - $this->requestStartNs;
        $totalMs = $totalNs / 1_000_000;

        // Stop any still-active timers
        foreach (array_keys($this->activeTimers) as $id) {
            $this->stop($id);
        }

        $profile = new RequestProfile(
            method: $method,
            path: $path,
            statusCode: $statusCode,
            totalMs: round($totalMs, 3),
            entries: $this->entries,
            queryCount: $this->queryCount,
            queryTimeMs: round($this->queryTimeMs, 3),
            cacheHits: $this->cacheHits,
            cacheMisses: $this->cacheMisses,
            peakMemoryBytes: memory_get_peak_usage(true),
        );

        $this->storeProfile($profile);

        return $profile;
    }

    /**
     * Get recent request profiles.
     *
     * @return list<RequestProfile>
     */
    public function recentProfiles(): array
    {
        return $this->profiles;
    }

    /**
     * Check if the profiler is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Enable or disable the profiler.
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * Get current entry count.
     */
    public function entryCount(): int
    {
        return count($this->entries);
    }

    private function addEntry(ProfileEntry $entry): void
    {
        // Prevent unbounded growth
        if (count($this->entries) >= $this->maxEntries) {
            return;
        }

        $this->entries[] = $entry;
    }

    private function storeProfile(RequestProfile $profile): void
    {
        $this->profiles[] = $profile;

        // Evict oldest profiles
        if (count($this->profiles) > $this->maxProfiles) {
            array_splice($this->profiles, 0, count($this->profiles) - $this->maxProfiles);
        }
    }

    private function truncateSql(string $sql, int $maxLength = 200): string
    {
        if (mb_strlen($sql) <= $maxLength) {
            return $sql;
        }

        return mb_substr($sql, 0, $maxLength) . '...';
    }
}
