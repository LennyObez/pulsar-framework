<?php

declare(strict_types=1);

namespace Pulsar\Database\Cache;

use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Routing\ConnectionRole;

use function array_values;

/**
 * Runs a query through the query cache, or straight past it.
 *
 * This is the piece `config/database.php`'s `query_cache` section configures. Every
 * part of the cache existed already — {@see QueryCache} stores and invalidates,
 * {@see QueryCacheKey} builds the key, {@see SensitivityMetadata} decides what may
 * never be cached, {@see TableTagExtractor} finds the tags, {@see CacheableQuery}
 * describes one request — and nothing assembled them. The section was parsed into a
 * typed {@see QueryCacheConfig} that no runtime object read: `enabled`,
 * `default_ttl_seconds`, `sensitive_table_names` and `authorization_columns` were four
 * settings an operator could tune with no effect whatsoever. They take effect here.
 *
 * ## Opt in per query
 *
 * Caching is asked for, never applied behind a caller's back. A transparent read cache
 * over every `SELECT` would change what an application sees after its own writes, and
 * `enabled` defaults to true — switching that on for existing deployments is not a
 * change a bug fix gets to make. So `ConnectionInterface` is untouched, and a caller
 * that wants a cached read asks for one:
 *
 * ```php
 * $result = $runner->query('SELECT * FROM articles WHERE status = :s', ['s' => 'live']);
 * $runner->invalidate('articles');   // after writing to it
 * ```
 *
 * ## What the settings do
 *
 * - `enabled` false — {@see query()} executes and returns without reading or writing
 *   the cache. The runner is still resolvable and still works; it just does not cache,
 *   which is what the operator asked for.
 * - `default_ttl_seconds` — the lifetime of an entry whose caller named none.
 * - `sensitive_table_names` / `authorization_columns` — consulted through
 *   {@see SensitivityMetadata} on every query, and they override `enabled` in the
 *   direction that matters: a query touching a sensitive table, or shaped by an
 *   authorization column, is executed and never stored, whatever the caller asked for.
 *
 * ## The key
 *
 * The tenant and the schema version are supplied by the caller because this class
 * cannot know them without reaching for ambient state, and a cache key built from
 * ambient state is a cache key that changes when nothing about the query did. A
 * multi-tenant application passes its tenant id; leaving it null means "this result is
 * the same for everyone", which is a claim the caller makes, not one made for it.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CachedQueryRunner
{
    public function __construct(
        private ConnectionInterface $connection,
        private QueryCacheInterface $cache,
        private SensitivityMetadata $sensitivity,
        private QueryCacheConfig $config,
        private TableTagExtractorInterface $tagExtractor = new TableTagExtractor(),
        private ?string $tenantId = null,
        private ?string $schemaVersion = null,
    ) {}

    /**
     * Run a query, serving it from the cache when that is both asked for and allowed.
     *
     * @param array<string, mixed> $bindings
     * @param int|null $ttlSeconds Lifetime of the entry; `default_ttl_seconds` when null
     */
    public function query(string $sql, array $bindings = [], ?int $ttlSeconds = null): Result
    {
        return $this->run(CacheableQuery::forQuery(
            $sql,
            $bindings,
            $ttlSeconds ?? $this->config->defaultTtlSeconds,
            $this->tagExtractor->extractTags($sql),
        ));
    }

    /**
     * Run a query described by a {@see CacheableQuery}.
     */
    public function run(CacheableQuery $query): Result
    {
        if (!$this->isCacheable($query)) {
            return $this->connection->query($query->sql, $query->bindings);
        }

        $key = $this->keyFor($query);
        $cached = $this->cache->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $result = $this->connection->query($query->sql, $query->bindings);

        $this->cache->put($key, $result, $query->ttlSeconds, $query->tags);

        return $result;
    }

    /**
     * Drop every cached result that touched any of these tables.
     *
     * Call it after writing to them. Invalidation is not automatic: this class sees
     * only the queries it is given, and a write issued through the connection directly
     * — or by another process — never reaches it. Claiming automatic invalidation
     * would be claiming to know about writes it cannot see.
     */
    public function invalidate(string ...$tables): void
    {
        if ($tables === []) {
            return;
        }

        // array_values, not $tables: a variadic is a list at runtime, but the early
        // return above narrows it to a non-empty array whose key type the analyser can
        // no longer prove is sequential, and invalidateByTags() is declared list<string>.
        $this->cache->invalidateByTags(array_values($tables));
    }

    /**
     * Whether this query may be served from, and stored in, the cache.
     *
     * Both conditions must hold, so either may be checked first. `enabled` is checked
     * first only because it is a field read rather than two regular expressions.
     */
    private function isCacheable(CacheableQuery $query): bool
    {
        if (!$this->config->enabled) {
            return false;
        }

        return $this->sensitivity->shouldCache($query->sql, $query->bindings, $query->tags);
    }

    private function keyFor(CacheableQuery $query): string
    {
        return QueryCacheKey::build(
            $query->sql,
            $query->bindings,
            $this->tenantId,
            // Reads are what this runner caches, and the role separates a cached read
            // from anything a write connection ever put under the same SQL.
            ConnectionRole::Read,
            $this->schemaVersion,
        );
    }
}
