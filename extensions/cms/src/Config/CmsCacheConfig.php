<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * CMS caching configuration.
 *
 * @psalm-api Public configuration DTO loaded from config/cms.php; consumed
 *            by caching decorators and admin diagnostic views.
 * @api
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
     * @param array{
     *     page_cache_ttl_seconds?: int,
     *     stampede_protection?: bool,
     *     early_recompute_beta?: int,
     *     stale_grace_period_seconds?: int,
     *     lock_timeout_seconds?: int,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            pageCacheTtlSeconds: Coerce::int($data['page_cache_ttl_seconds'] ?? null, 3600),
            stampedeProtection: Coerce::strictBool($data['stampede_protection'] ?? null, true),
            earlyRecomputeBeta: Coerce::int($data['early_recompute_beta'] ?? null, 10),
            staleGracePeriodSeconds: Coerce::int($data['stale_grace_period_seconds'] ?? null, 300),
            lockTimeoutSeconds: Coerce::int($data['lock_timeout_seconds'] ?? null, 5),
        );
    }
}
