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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var list<string> $sensitiveTableNames */
        $sensitiveTableNames = $data['sensitive_table_names'] ?? [];

        /** @var list<string> $authorizationColumns */
        $authorizationColumns = $data['authorization_columns'] ?? ['user_id', 'tenant_id'];

        /** @var int|string $defaultTtl */
        $defaultTtl = $data['default_ttl_seconds'] ?? 60;

        return new self(
            enabled: (bool) ($data['enabled'] ?? true),
            defaultTtlSeconds: (int) $defaultTtl,
            sensitiveTableNames: $sensitiveTableNames,
            authorizationColumns: $authorizationColumns,
        );
    }
}
