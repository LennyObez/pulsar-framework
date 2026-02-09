<?php

declare(strict_types=1);

namespace Pulsar\Http\RateLimit;

use Pulsar\Api\Api;

/**
 * Contract for rate limiter implementations.
 *
 * Implementations track request counts per key within time windows
 * and determine whether requests should be allowed or throttled.
 */
#[Api(since: '1.0.0')]
interface RateLimiterInterface
{
    /**
     * Record a hit for the given key and return the rate limit status.
     */
    public function hit(string $key): RateLimitResult;

    /**
     * Get the current hit count for a key without incrementing.
     */
    public function attempts(string $key): int;

    /**
     * Reset the counter for a key.
     */
    public function reset(string $key): void;
}
