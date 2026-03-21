<?php

declare(strict_types=1);

namespace Pulsar\Http\Cache;

use Pulsar\Api\Api;

use function array_keys;
use function count;

/**
 * In-memory HTTP response cache storage.
 *
 * Ideal for persistent workers (RoadRunner, FrankenPHP) where the process
 * lifetime spans multiple requests. Entries are evicted on expiration
 * or when max capacity is reached (LRU).
 * @api
 */
#[Api(since: '1.0.0')]
final class InMemoryCacheStorage implements CacheStorageInterface
{
    /** @var array<string, CachedResponse> */
    private array $store = [];

    /** @var array<string, list<string>> key → tags */
    private array $tagIndex = [];

    /** @var array<string, list<string>> tag → keys */
    private array $tagToKeys = [];

    public function __construct(
        private readonly int $maxEntries = 1024,
    ) {}

    public function get(string $key): ?CachedResponse
    {
        if (!isset($this->store[$key])) {
            return null;
        }

        $response = $this->store[$key];

        if ($response->isExpired()) {
            $this->delete($key);

            return null;
        }

        return $response;
    }

    public function set(string $key, CachedResponse $response, int $ttl, array $tags = []): void
    {
        // LRU eviction
        if (count($this->store) >= $this->maxEntries && !isset($this->store[$key])) {
            $this->evictOldest();
        }

        $this->store[$key] = $response;
        $this->tagIndex[$key] = $tags;

        foreach ($tags as $tag) {
            $this->tagToKeys[$tag][] = $key;
        }
    }

    public function delete(string $key): void
    {
        if (!isset($this->store[$key])) {
            return;
        }

        // Clean up tag indices
        $tags = $this->tagIndex[$key] ?? [];

        foreach ($tags as $tag) {
            if (isset($this->tagToKeys[$tag])) {
                $this->tagToKeys[$tag] = array_values(array_filter(
                    $this->tagToKeys[$tag],
                    static fn(string $k): bool => $k !== $key,
                ));

                if ($this->tagToKeys[$tag] === []) {
                    unset($this->tagToKeys[$tag]);
                }
            }
        }

        unset($this->store[$key], $this->tagIndex[$key]);
    }

    public function invalidateByTags(array $tags): void
    {
        $keysToDelete = [];

        foreach ($tags as $tag) {
            foreach ($this->tagToKeys[$tag] ?? [] as $key) {
                $keysToDelete[$key] = true;
            }
        }

        foreach (array_keys($keysToDelete) as $key) {
            $this->delete($key);
        }
    }

    public function clear(): void
    {
        $this->store = [];
        $this->tagIndex = [];
        $this->tagToKeys = [];
    }

    private function evictOldest(): void
    {
        // Evict the entry with the earliest expiration
        $oldestKey = null;
        $oldestExpiry = PHP_INT_MAX;

        foreach ($this->store as $key => $response) {
            if ($response->expiresAt < $oldestExpiry) {
                $oldestExpiry = $response->expiresAt;
                $oldestKey = $key;
            }
        }

        if ($oldestKey !== null) {
            $this->delete($oldestKey);
        }
    }
}
