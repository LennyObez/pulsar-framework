<?php

declare(strict_types=1);

namespace Pulsar\Database\Cache;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Configuration for query result caching.
 *
 * In regulated environments, caching is disabled by default to prevent
 * stale authorization data from leaking between requests. The sensitive
 * table and authorization column lists drive automatic cache invalidation.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class QueryCacheConfig
{
    /**
     * @param list<string> $sensitiveTableNames Tables that must never be cached
     * @param list<string> $authorizationColumns Columns that scope cache keys for tenant isolation
     */
    public function __construct(
        public bool $enabled = true,
        public int $defaultTtlSeconds = 60,
        public array $sensitiveTableNames = [],
        public array $authorizationColumns = ['user_id', 'tenant_id'],
    ) {}

    /**
     * Build from a raw config array.
     *
     * @param array{
     *     enabled?: bool|int|string,
     *     default_ttl_seconds?: int|string,
     *     sensitive_table_names?: list<string>,
     *     authorization_columns?: list<string>,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['enabled'] ?? true),
            defaultTtlSeconds: (int) ($data['default_ttl_seconds'] ?? 60),
            sensitiveTableNames: $data['sensitive_table_names'] ?? [],
            authorizationColumns: $data['authorization_columns'] ?? ['user_id', 'tenant_id'],
        );
    }
}
