<?php

declare(strict_types=1);

namespace Pulsar\Database\Cache;

use Pulsar\Api\Api;
use Pulsar\Database\Result;

/**
 * Caches query results with tag-based invalidation.
 */
#[Api(since: '1.0.0')]
interface QueryCacheInterface
{
    /**
     * Retrieve a cached result by key.
     */
    public function get(string $key): ?Result;

    /**
     * Store a query result with TTL and tags for invalidation.
     *
     * @param list<string> $tags Tags for targeted invalidation (e.g., table names)
     */
    public function put(string $key, Result $result, int $ttlSeconds, array $tags): void;

    /**
     * Invalidate all cached entries matching any of the given tags.
     *
     * @param list<string> $tags
     */
    public function invalidateByTags(array $tags): void;

    /**
     * Flush all cached query results.
     */
    public function flush(): void;
}
