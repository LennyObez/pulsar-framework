<?php

declare(strict_types=1);

namespace Pulsar\Database\Cache;

use Override;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Api\Api;
use Pulsar\Database\Result;
use Pulsar\Database\Row;

use function array_keys;
use function array_map;
use function is_array;

/**
 * PSR-16-backed query cache with tag-based invalidation.
 *
 * Stores serialized query results and maintains a tag index so that
 * writes to specific tables can invalidate all related cached queries.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class QueryCache implements QueryCacheInterface
{
    private const string TAG_INDEX_PREFIX = 'qc_tag:';

    public function __construct(
        private CacheInterface $cache,
    ) {}

    #[Override]
    public function get(string $key): ?Result
    {
        /** @var mixed $cached */
        $cached = $this->cache->get($key);

        if (!is_array($cached)) {
            return null;
        }

        /** @var list<array<string, mixed>> $cached */
        return self::deserializeResult($cached);
    }

    #[Override]
    public function put(string $key, Result $result, int $ttlSeconds, array $tags): void
    {
        $this->cache->set($key, self::serializeResult($result), $ttlSeconds);

        foreach ($tags as $tag) {
            $tagKey = self::TAG_INDEX_PREFIX . $tag;

            /** @var mixed $existing */
            $existing = $this->cache->get($tagKey);

            /** @var list<string> $existingKeys */
            $existingKeys = is_array($existing) ? $existing : [];

            $set = [];
            foreach ($existingKeys as $k) {
                $set[$k] = true;
            }
            $set[$key] = true;

            $this->cache->set($tagKey, array_keys($set));
        }
    }

    #[Override]
    public function invalidateByTags(array $tags): void
    {
        $keysToDelete = [];

        foreach ($tags as $tag) {
            $tagKey = self::TAG_INDEX_PREFIX . $tag;

            /** @var mixed $existing */
            $existing = $this->cache->get($tagKey);

            if (is_array($existing)) {
                /** @var list<string> $existing */
                foreach ($existing as $k) {
                    $keysToDelete[$k] = true;
                }
            }

            $this->cache->delete($tagKey);
        }

        foreach (array_keys($keysToDelete) as $key) {
            $this->cache->delete($key);
        }
    }

    #[Override]
    public function flush(): void
    {
        $this->cache->clear();
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
