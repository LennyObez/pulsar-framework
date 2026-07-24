<?php

declare(strict_types=1);

namespace Pulsar\Http\RateLimit;

use Override;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Api\Internal;
use Throwable;

use function hash;
use function is_array;
use function is_int;
use function max;
use function strlen;
use function strtr;
use function time;

/**
 * Fixed-window rate limiter backed by a PSR-16 cache, for multi-process
 * deployments (PHP-FPM) where the in-memory {@see RateLimiter} cannot share
 * counts across workers.
 *
 * Window semantics: the first hit for a key stamps `window_start = now` and the
 * cache entry is given a TTL of `windowSeconds`, so the window expires on its
 * own — no pruning, and each key rolls its own window from its first request.
 *
 * Fails OPEN: any cache read/write error (backend down, missing binding surfaced
 * as an exception) is treated as "allowed" rather than blocking traffic. A rate
 * limiter must never take the site down when its store is unavailable.
 *
 * Note: like every non-atomic store-backed limiter, concurrent hits share a
 * check-then-set window (TOCTOU) and may allow slightly more than the limit —
 * conservative, never destructive. Use a Lua/Redis atomic limiter where strict
 * counting matters.
 */
#[Internal(reason: 'Use RateLimiterInterface')]
final readonly class CacheRateLimiter implements RateLimiterInterface
{
    /**
     * PSR-16 reserves `{}()/\@:` in keys. The middleware and other callers build
     * keys with `:`, so every reserved character is mapped to `.` before the key
     * reaches the cache.
     */
    private const string RESERVED = '{}()/\\@:';
    private const string RESERVED_REPLACEMENT = '........';

    public function __construct(
        private CacheInterface $cache,
        private int $maxAttempts,
        private int $windowSeconds,
        private string $prefix = 'ratelimit.',
    ) {}

    #[Override]
    public function hit(string $key): RateLimitResult
    {
        $cacheKey = $this->cacheKey($key);
        $now = time();

        try {
            /** @var mixed $entry */
            $entry = $this->cache->get($cacheKey);

            [$count, $windowStart] = $this->readEntry($entry, $now);
            $count++;

            // TTL is the remaining life of the current window, so the entry
            // disappears exactly when the window closes.
            $ttl = max(1, $windowStart + $this->windowSeconds - $now);
            $this->cache->set($cacheKey, ['c' => $count, 'w' => $windowStart], $ttl);
        } catch (Throwable) {
            return $this->allow();
        }

        $allowed = $count <= $this->maxAttempts;

        return new RateLimitResult(
            allowed: $allowed,
            limit: $this->maxAttempts,
            remaining: max(0, $this->maxAttempts - $count),
            retryAfter: $allowed ? 0 : max(1, $windowStart + $this->windowSeconds - $now),
        );
    }

    #[Override]
    public function attempts(string $key): int
    {
        try {
            [$count] = $this->readEntry($this->cache->get($this->cacheKey($key)), time());

            return $count;
        } catch (Throwable) {
            return 0;
        }
    }

    #[Override]
    public function reset(string $key): void
    {
        try {
            $this->cache->delete($this->cacheKey($key));
        } catch (Throwable) {
            // Best-effort: a failed reset must not surface as a request error.
        }
    }

    /**
     * Decode a stored entry, treating a missing, malformed, or expired-window
     * entry as a fresh window starting now.
     *
     * @return array{0: int, 1: int} [count, windowStart]
     */
    private function readEntry(mixed $entry, int $now): array
    {
        if (
            is_array($entry)
            && isset($entry['c'], $entry['w'])
            && is_int($entry['c'])
            && is_int($entry['w'])
            && $entry['w'] + $this->windowSeconds > $now
        ) {
            return [$entry['c'], $entry['w']];
        }

        return [0, $now];
    }

    private function allow(): RateLimitResult
    {
        return new RateLimitResult(
            allowed: true,
            limit: $this->maxAttempts,
            remaining: max(0, $this->maxAttempts - 1),
            retryAfter: 0,
        );
    }

    private function cacheKey(string $key): string
    {
        $safe = $this->prefix . strtr($key, self::RESERVED, self::RESERVED_REPLACEMENT);

        // PSR-16 guarantees only 64-char key support; hash anything longer to a
        // fixed, legal length rather than risk an implementation rejecting it.
        if (strlen($safe) > 64) {
            return $this->prefix . hash('xxh128', $key);
        }

        return $safe;
    }
}
