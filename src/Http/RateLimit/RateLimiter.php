<?php

declare(strict_types=1);

namespace Pulsar\Http\RateLimit;

use function time;

/**
 * Fixed-window in-memory rate limiter.
 *
 * Tracks request counts per key within fixed time windows.
 * Suitable for single-process deployments; for distributed deployments,
 * replace with a cache/store-backed implementation.
 */
final class RateLimiter
{
    /**
     * @var array<string, array{count: int, window_start: int}>
     */
    private array $hits = [];

    public function __construct(
        private readonly int $maxAttempts,
        private readonly int $windowSeconds,
    ) {}

    /**
     * Attempt a hit for the given key.
     *
     * Returns a result indicating whether the request is allowed and how many
     * attempts remain in the current window.
     */
    public function hit(string $key): RateLimitResult
    {
        $now = time();
        $this->pruneExpired($key, $now);

        if (!isset($this->hits[$key])) {
            $this->hits[$key] = ['count' => 0, 'window_start' => $now];
        }

        $entry = $this->hits[$key];
        $windowEnd = $entry['window_start'] + $this->windowSeconds;

        if ($entry['count'] >= $this->maxAttempts) {
            return new RateLimitResult(
                allowed: false,
                limit: $this->maxAttempts,
                remaining: 0,
                retryAfter: $windowEnd - $now,
            );
        }

        $this->hits[$key] = ['count' => $entry['count'] + 1, 'window_start' => $entry['window_start']];

        return new RateLimitResult(
            allowed: true,
            limit: $this->maxAttempts,
            remaining: $this->maxAttempts - $entry['count'] - 1,
            retryAfter: 0,
        );
    }

    /**
     * Get the current hit count for a key without incrementing.
     */
    public function attempts(string $key): int
    {
        $this->pruneExpired($key, time());

        return $this->hits[$key]['count'] ?? 0;
    }

    /**
     * Reset the counter for a key.
     */
    public function reset(string $key): void
    {
        unset($this->hits[$key]);
    }

    /**
     * Remove expired window entries for a key.
     */
    private function pruneExpired(string $key, int $now): void
    {
        if (!isset($this->hits[$key])) {
            return;
        }

        $entry = $this->hits[$key];
        if ($now >= $entry['window_start'] + $this->windowSeconds) {
            unset($this->hits[$key]);
        }
    }
}
