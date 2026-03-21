<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Dashboard;

use Pulsar\Api\Api;

/**
 * Provides cache hit/miss statistics for dashboard display.
 *
 * Implementations may aggregate from PSR-6 pool metrics or
 * the underlying cache driver's diagnostic counters.
 *
 * @psalm-api Public binding contract; consumed by dashboard widgets.
 * @api
 */
#[Api(since: '1.0.0')]
interface CacheStatsProviderInterface
{
    /**
     * Get the cache hit rate as a float between 0.0 and 1.0.
     *
     * Returns 0.0 when no cache operations have been recorded.
     */
    public function getHitRate(): float;

    /**
     * Get the total number of cache hits.
     */
    public function getHitCount(): int;

    /**
     * Get the total number of cache misses.
     */
    public function getMissCount(): int;
}
