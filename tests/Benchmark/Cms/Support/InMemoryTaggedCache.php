<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Cms\Support;

use Override;
use Pulsar\Cache\Application\TaggedCacheInterface;

use function array_key_exists;
use function count;
use function time;

/**
 * In-memory tagged cache for benchmark scenarios.
 *
 * Provides O(1) get/set with TTL expiration and tag invalidation.
 */
final class InMemoryTaggedCache implements TaggedCacheInterface
{
    /** @var array<string, array{value: mixed, tags: list<string>, expires: int|null}> */
    private array $store = [];

    /** @var array<string, bool> */
    private array $invalidatedTags = [];

    #[Override]
    public function get(string $key): mixed
    {
        if (!array_key_exists($key, $this->store)) {
            return null;
        }

        $entry = $this->store[$key];

        if ($entry['expires'] !== null && $entry['expires'] < time()) {
            unset($this->store[$key]);

            return null;
        }

        foreach ($entry['tags'] as $tag) {
            if (array_key_exists($tag, $this->invalidatedTags)) {
                unset($this->store[$key]);

                return null;
            }
        }

        return $entry['value'];
    }

    #[Override]
    public function set(string $key, mixed $value, array $tags, ?int $ttlSeconds = null): bool
    {
        $this->store[$key] = [
            'value' => $value,
            'tags' => $tags,
            'expires' => $ttlSeconds !== null ? time() + $ttlSeconds : null,
        ];

        return true;
    }

    #[Override]
    public function delete(string $key): bool
    {
        $existed = array_key_exists($key, $this->store);
        unset($this->store[$key]);

        return $existed;
    }

    #[Override]
    public function invalidateTag(string $tag): void
    {
        $this->invalidatedTags[$tag] = true;
    }

    #[Override]
    public function invalidateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            $this->invalidatedTags[$tag] = true;
        }
    }

    public function clear(): void
    {
        $this->store = [];
        $this->invalidatedTags = [];
    }

    public function count(): int
    {
        return count($this->store);
    }

    /**
     * Pre-warm the cache with a specific key/value for benchmark scenarios.
     *
     * @param list<string> $tags
     */
    public function seed(string $key, mixed $value, array $tags = [], ?int $ttlSeconds = null): void
    {
        $this->set($key, $value, $tags, $ttlSeconds);
    }
}
