<?php

declare(strict_types=1);

namespace Pulsar\Http\Cache;

use Pulsar\Api\Api;

/**
 * Storage backend for the HTTP response cache.
 * @api
 */
#[Api(since: '1.0.0')]
interface CacheStorageInterface
{
    /**
     * Get a cached response by key.
     */
    public function get(string $key): ?CachedResponse;

    /**
     * Store a response with the given key and tags.
     *
     * @param list<string> $tags Cache tags for invalidation
     */
    public function set(string $key, CachedResponse $response, int $ttl, array $tags = []): void;

    /**
     * Remove a cached response by key.
     */
    public function delete(string $key): void;

    /**
     * Invalidate all entries tagged with any of the given tags.
     *
     * @param list<string> $tags
     */
    public function invalidateByTags(array $tags): void;

    /**
     * Remove all cached responses.
     */
    public function clear(): void;
}
