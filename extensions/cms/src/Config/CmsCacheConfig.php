<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;

use function is_bool;
use function is_int;

/**
 * CMS caching configuration.
 */
#[Api(since: '1.0.0')]
final readonly class CmsCacheConfig
{
    /**
     * @param int $pageCacheTtlSeconds Full-page cache TTL (default: 1 hour)
     * @param bool $stampedeProtection Enable probabilistic early recomputation + lock-based single-flight
     * @param int $earlyRecomputeBeta XFetch aggressiveness parameter (higher = more aggressive)
     * @param int $staleGracePeriodSeconds How long expired entries are retained for stale serving
     * @param int $lockTimeoutSeconds Max wait time for single-flight lock
     */
    public function __construct(
        public int $pageCacheTtlSeconds = 3600,
        public bool $stampedeProtection = true,
        public int $earlyRecomputeBeta = 10,
        public int $staleGracePeriodSeconds = 300,
        public int $lockTimeoutSeconds = 5,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            pageCacheTtlSeconds: is_int($data['page_cache_ttl_seconds'] ?? null) ? $data['page_cache_ttl_seconds'] : 3600,
            stampedeProtection: is_bool($data['stampede_protection'] ?? null) ? $data['stampede_protection'] : true,
            earlyRecomputeBeta: is_int($data['early_recompute_beta'] ?? null) ? $data['early_recompute_beta'] : 10,
            staleGracePeriodSeconds: is_int($data['stale_grace_period_seconds'] ?? null) ? $data['stale_grace_period_seconds'] : 300,
            lockTimeoutSeconds: is_int($data['lock_timeout_seconds'] ?? null) ? $data['lock_timeout_seconds'] : 5,
        );
    }
}
