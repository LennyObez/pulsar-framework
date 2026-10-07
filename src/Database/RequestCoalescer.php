<?php

declare(strict_types=1);

namespace Pulsar\Database;

use Pulsar\Api\Api;

use function array_key_first;
use function count;
use function hash;
use function ksort;
use function serialize;

/**
 * Deduplicates identical in-flight database queries within a single request.
 *
 * When multiple components issue the same query (same SQL + same bindings),
 * the coalescer executes it once and shares the result. Similar to GraphQL
 * DataLoader but for arbitrary SQL queries.
 *
 * The cache is request-scoped: call reset() between requests on persistent
 * workers, or let it go out of scope on FPM.
 * @api
 */
#[Api(since: '1.0.0')]
final class RequestCoalescer
{
    /** @var array<string, Result> */
    private array $cache = [];

    /**
     * Insertion-ordered set of cache keys for LRU eviction.
     *
     * Keyed by the cache key (value is always `true`) so membership tests and
     * targeted removal in {@see invalidate()} are O(1). PHP preserves insertion
     * order on associative arrays, so the oldest key remains
     * `array_key_first()` for eviction.
     *
     * @var array<string, true>
     */
    private array $keys = [];

    private int $hits = 0;
    private int $misses = 0;

    /**
     * @param int $maxEntries Maximum cached query results before LRU eviction
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly int $maxEntries = 256,
    ) {}

    /**
     * Execute a SELECT query with coalescing.
     *
     * If an identical query (SQL + bindings) was already executed in this
     * request cycle, the cached result is returned without a database round-trip.
     *
     * @param array<string, mixed> $bindings
     */
    public function query(string $sql, array $bindings = []): Result
    {
        $key = $this->computeKey($sql, $bindings);

        if (isset($this->cache[$key])) {
            ++$this->hits;

            return $this->cache[$key];
        }

        ++$this->misses;
        $result = $this->connection->query($sql, $bindings);

        $this->store($key, $result);

        return $result;
    }

    /**
     * Execute a mutating statement and invalidate the coalescer cache.
     *
     * INSERT/UPDATE/DELETE invalidate all cached results since any of them
     * could now be stale.
     *
     * @param array<string, mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): int
    {
        $this->cache = [];
        $this->keys = [];

        return $this->connection->execute($sql, $bindings);
    }

    /**
     * Invalidate all cached query results.
     *
     * Call this between requests on persistent workers (RoadRunner, FrankenPHP).
     */
    public function reset(): void
    {
        $this->cache = [];
        $this->keys = [];
        $this->hits = 0;
        $this->misses = 0;
    }

    /**
     * Invalidate a specific query from the cache.
     *
     * @param array<string, mixed> $bindings
     */
    public function invalidate(string $sql, array $bindings = []): void
    {
        $key = $this->computeKey($sql, $bindings);

        if (isset($this->cache[$key])) {
            unset($this->cache[$key], $this->keys[$key]);
        }
    }

    /**
     * Get the cache hit count for the current request cycle.
     */
    public function hits(): int
    {
        return $this->hits;
    }

    /**
     * Get the cache miss count for the current request cycle.
     */
    public function misses(): int
    {
        return $this->misses;
    }

    /**
     * Get the hit rate as a percentage (0.0 to 100.0).
     */
    public function hitRate(): float
    {
        $total = $this->hits + $this->misses;

        if ($total === 0) {
            return 0.0;
        }

        return ($this->hits / $total) * 100.0;
    }

    /**
     * Get the number of currently cached query results.
     */
    public function size(): int
    {
        return count($this->cache);
    }

    /**
     * Compute a deterministic cache key from SQL and bindings.
     *
     * @param array<string, mixed> $bindings
     */
    private function computeKey(string $sql, array $bindings): string
    {
        // Sort bindings by key so that logically identical queries whose
        // binding arrays differ only in key order produce the same cache key.
        // Mirrors QueryCacheKey::build(), which ksort()s before hashing.
        ksort($bindings);

        return hash('xxh128', $sql . "\0" . serialize($bindings));
    }

    private function store(string $key, Result $result): void
    {
        // LRU eviction when at capacity
        if (count($this->cache) >= $this->maxEntries && !isset($this->cache[$key])) {
            $evictKey = array_key_first($this->keys);
            if ($evictKey !== null) {
                unset($this->cache[$evictKey], $this->keys[$evictKey]);
            }
        }

        $this->cache[$key] = $result;
        $this->keys[$key] = true;
    }
}
