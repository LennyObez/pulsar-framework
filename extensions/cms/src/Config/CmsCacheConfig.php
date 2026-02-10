<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;

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
            pageCacheTtlSeconds: (int) ($data['page_cache_ttl_seconds'] ?? 3600),
            stampedeProtection: (bool) ($data['stampede_protection'] ?? true),
            earlyRecomputeBeta: (int) ($data['early_recompute_beta'] ?? 10),
            staleGracePeriodSeconds: (int) ($data['stale_grace_period_seconds'] ?? 300),
            lockTimeoutSeconds: (int) ($data['lock_timeout_seconds'] ?? 5),
        );
    }
}
