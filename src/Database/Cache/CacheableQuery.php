<?php

declare(strict_types=1);

namespace Pulsar\Database\Cache;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Value object representing a query that should be checked against
 * the cache before execution.
 */
#[Api(since: '1.0.0')]
final readonly class CacheableQuery
{
    /**
     * @param array<string, mixed> $bindings
     * @param list<string> $tags Cache invalidation tags (typically table names)
     */
    public function __construct(
        public string $sql,
        public array $bindings,
        public int $ttlSeconds,
        public array $tags,
    ) {}

    /**
     * @param array<string, mixed> $bindings
     * @param list<string> $tags
     */
    #[NoDiscard]
    public static function forQuery(string $sql, array $bindings, int $ttlSeconds, array $tags): self
    {
        return new self($sql, $bindings, $ttlSeconds, $tags);
    }
}
