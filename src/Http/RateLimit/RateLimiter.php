<?php

declare(strict_types=1);

namespace Pulsar\Http\RateLimit;

use Pulsar\Api\Internal;

use function time;

/**
 * Fixed-window in-memory rate limiter.
 *
 * Tracks request counts per key within fixed time windows.
 * Suitable for single-process deployments and testing; for multi-process
 * deployments, use SqliteRateLimiter or a store-backed implementation.
 *
 * F7.5: long-running workers (Roadrunner / Swoole / FrankenPHP) call
 * `hit()` once per request, each potentially with a unique key (per-IP
 * + per-user composite). Without a global GC pass, expired entries
 * for keys that never call `hit()` again accumulate indefinitely. We
 * sweep the entire `$hits` map every `GC_INTERVAL_SECONDS` so the
 * footprint stays bounded by the active-key set rather than the
 * lifetime-distinct-key set.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final class RateLimiter implements RateLimiterInterface
{
    /**
     * F7.5: how often to sweep expired entries across every key.
     * 60 seconds keeps the worst-case overshoot at one window-length
     * worth of dead entries while making the GC pass amortised over
     * thousands of requests.
     */
    private const int GC_INTERVAL_SECONDS = 60;

    /**
     * @var array<string, array{count: int, window_start: int}>
     */
    private array $hits = [];

    private int $lastGcAt = 0;

    public function __construct(
        private readonly int $maxAttempts,
        private readonly int $windowSeconds,
    ) {}

    public function hit(string $key): RateLimitResult
    {
        $now = time();
        $this->pruneExpired($key, $now);
        $this->maybeSweepExpired($now);

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

    public function attempts(string $key): int
    {
        $this->pruneExpired($key, time());

        return $this->hits[$key]['count'] ?? 0;
    }

    public function reset(string $key): void
    {
        unset($this->hits[$key]);
    }

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

    /**
     * F7.5: drop every expired entry across the whole `$hits` map.
     * Runs at most once per `GC_INTERVAL_SECONDS` so the cost
     * amortises over thousands of `hit()` calls.
     */
    private function maybeSweepExpired(int $now): void
    {
        if ($now - $this->lastGcAt < self::GC_INTERVAL_SECONDS) {
            return;
        }

        foreach ($this->hits as $existingKey => $entry) {
            if ($now >= $entry['window_start'] + $this->windowSeconds) {
                unset($this->hits[$existingKey]);
            }
        }

        $this->lastGcAt = $now;
    }
}
