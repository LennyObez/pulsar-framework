<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application;

use Pulsar\Api\Api;

/**
 * Tag-based cache API.
 * @api
 */
#[Api(since: '1.0.0')]
interface TaggedCacheInterface
{
    /**
     * Get a tagged value.
     *
     * Returns null if the key doesn't exist or any of its tags have been invalidated.
     */
    public function get(string $key): mixed;

    /**
     * Store a value with tags.
     *
     * @param list<string> $tags
     * @param int|null $ttlSeconds Seconds until expiration (null = pool default or no expiration)
     */
    public function set(string $key, mixed $value, array $tags, ?int $ttlSeconds = null): bool;

    /**
     * Delete a tagged value.
     */
    public function delete(string $key): bool;

    /**
     * Invalidate all values associated with the given tag.
     */
    public function invalidateTag(string $tag): void;

    /**
     * Invalidate all values associated with the given tags.
     *
     * @param list<string> $tags
     */
    public function invalidateTags(array $tags): void;
}
