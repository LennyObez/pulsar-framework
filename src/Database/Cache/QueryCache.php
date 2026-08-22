<?php

declare(strict_types=1);

namespace Pulsar\Database\Cache;

use Override;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Api\Api;
use Pulsar\Database\Result;
use Pulsar\Database\Row;

use function array_map;
use function is_array;
use function is_int;

/**
 * PSR-16-backed query cache with tag-based invalidation.
 *
 * Stores serialized query results, each stamped with the version of every tag
 * it was cached against. Invalidation bumps a per-tag version counter, so all
 * entries carrying an older version become stale on their next read without
 * the cache having to enumerate or rewrite them.
 *
 * This versioning model replaces a per-tag key-list index. The list had two
 * defects: building it was a read-modify-write that silently lost entries when
 * two requests cached under the same tag concurrently (a dropped entry then
 * survived invalidation as stale data), and it was written without a TTL, so
 * it grew unbounded and never expired. Versioning has neither: writes touch
 * only the entry's own key, and the bounded set of small integer counters is
 * the only persistent bookkeeping.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class QueryCache implements QueryCacheInterface
{
    private const string TAG_VERSION_PREFIX = 'qc_tagver.';

    public function __construct(
        private CacheInterface $cache,
    ) {}

    #[Override]
    public function get(string $key): ?Result
    {
        /** @var mixed $cached */
        $cached = $this->cache->get($key);

        if (
            !is_array($cached)
            || !isset($cached['rows'], $cached['tags'])
            || !is_array($cached['rows'])
            || !is_array($cached['tags'])
        ) {
            return null;
        }

        // The entry is fresh only while every tag it was stamped with still
        // carries the same version. A version bumped by invalidateByTags makes
        // the entry stale here, on read, without it being touched at write time.
        /** @var array<array-key, mixed> $storedTags */
        $storedTags = $cached['tags'];

        foreach ($storedTags as $tag => $storedVersion) {
            if (!is_int($storedVersion) || $this->tagVersion((string) $tag) !== $storedVersion) {
                return null;
            }
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $cached['rows'];

        return self::deserializeResult($rows);
    }

    #[Override]
    public function put(string $key, Result $result, int $ttlSeconds, array $tags): void
    {
        $tagVersions = [];

        foreach ($tags as $tag) {
            $tagVersions[$tag] = $this->tagVersion($tag);
        }

        $this->cache->set($key, [
            'tags' => $tagVersions,
            'rows' => self::serializeResult($result),
        ], $ttlSeconds);
    }

    #[Override]
    public function invalidateByTags(array $tags): void
    {
        foreach ($tags as $tag) {
            // Persisted without a TTL: the counter must outlive every entry it
            // governs, and the count of distinct tags (table names) is bounded.
            $this->cache->set(self::TAG_VERSION_PREFIX . $tag, $this->tagVersion($tag) + 1);
        }
    }

    #[Override]
    public function flush(): void
    {
        $this->cache->clear();
    }

    /**
     * Current version of a tag. Absent (never invalidated, or the counter was
     * evicted) reads as 0 so that entries stamped 0 stay fresh; any later
     * invalidation advances past 0 and makes them stale.
     */
    private function tagVersion(string $tag): int
    {
        /** @var mixed $version */
        $version = $this->cache->get(self::TAG_VERSION_PREFIX . $tag);

        return is_int($version) ? $version : 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function serializeResult(Result $result): array
    {
        return array_map(
            static fn(Row $row): array => $row->toArray(),
            $result->rows,
        );
    }

    /**
     * @param list<array<string, mixed>> $data
     */
    private static function deserializeResult(array $data): Result
    {
        return Result::fromArrays($data);
    }
}
