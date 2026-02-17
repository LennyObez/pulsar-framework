<?php

declare(strict_types=1);

namespace Pulsar\Http\RateLimit;

use Pulsar\Api\Api;

use function array_filter;
use function count;
use function time;

/**
 * Sliding-window in-memory rate limiter.
 *
 * Unlike the fixed-window RateLimiter, this implementation tracks
 * individual hit timestamps and counts only those within the trailing
 * window. This eliminates the burst-at-boundary problem where a client
 * can send 2x the limit across a fixed-window boundary.
 *
 * Suitable for single-process deployments and testing; for multi-process
 * deployments, use a store-backed implementation.
 */
#[Api(since: '1.0.0')]
final class SlidingWindowRateLimiter implements RateLimiterInterface
{
    /**
     * @var array<string, list<int>> Key => list of hit timestamps
     */
    private array $hits = [];

    public function __construct(
        private readonly int $maxAttempts,
        private readonly int $windowSeconds,
    ) {}

    public function hit(string $key): RateLimitResult
    {
        $now = time();
        $this->prune($key, $now);

        $currentHits = $this->hits[$key] ?? [];

        if (count($currentHits) >= $this->maxAttempts) {
            $oldestHit = $currentHits[0] ?? $now;
            $retryAfter = ($oldestHit + $this->windowSeconds) - $now;

            return new RateLimitResult(
                allowed: false,
                limit: $this->maxAttempts,
                remaining: 0,
                retryAfter: $retryAfter > 0 ? $retryAfter : 1,
            );
        }

        $this->hits[$key][] = $now;

        return new RateLimitResult(
            allowed: true,
            limit: $this->maxAttempts,
            remaining: $this->maxAttempts - count($this->hits[$key]),
            retryAfter: 0,
        );
    }

    public function attempts(string $key): int
    {
        $this->prune($key, time());

        return count($this->hits[$key] ?? []);
    }

    public function reset(string $key): void
    {
        unset($this->hits[$key]);
    }

    private function prune(string $key, int $now): void
    {
        if (!isset($this->hits[$key])) {
            return;
        }

        $threshold = $now - $this->windowSeconds;
        $this->hits[$key] = array_values(array_filter(
            $this->hits[$key],
            static fn(int $ts): bool => $ts > $threshold,
        ));

        if ($this->hits[$key] === []) {
            unset($this->hits[$key]);
        }
    }
}
