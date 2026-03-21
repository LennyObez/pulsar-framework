<?php

declare(strict_types=1);

namespace Pulsar\Http\RateLimit;

use Pulsar\Api\Api;

/**
 * Contract for rate limiter implementations.
 *
 * Implementations track request counts per key within time windows
 * and determine whether requests should be allowed or throttled.
 *
 * Note: In-memory implementations (e.g., {@see RateLimiter}) have an inherent
 * TOCTOU window under concurrent requests because the check-and-increment is
 * not atomic across processes. This is conservative (may allow slightly more
 * requests than the limit) rather than destructive. For strict atomicity in
 * multi-process deployments, use {@see SqliteRateLimiter} which uses database
 * transactions, or a Redis-based implementation with Lua scripting.
 * @api
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
