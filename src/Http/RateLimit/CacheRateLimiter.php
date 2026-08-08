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
 * Two weaknesses are accepted deliberately, and recorded as accepted risks
 * against ASVS 11.1.4 and 11.1.6 in `docs/security/asvs-l2-matrix.md`. Neither
 * is a bug to be fixed here; both are bounded by the conditions below.
 *
 * 1. Fail-open (11.1.4). Any cache read/write error — backend down, missing
 *    binding surfaced as an exception — is treated as "allowed". Accepted
 *    because a store outage would otherwise turn every request into a 429 and
 *    take the site down, and because this limiter guards request volume rather
 *    than credentials: the credential-facing gate is independent of it and
 *    fails CLOSED, {@see \Pulsar\Auth\TwoFactor\TwoFactorManager::verifyCode()}
 *    denying outright when no 2FA limiter is bound. Not acceptable for a flow
 *    whose only protection is this counter — such a flow must use a
 *    store-atomic limiter and treat a store error as a refusal.
 *
 * 2. Check-then-set window (11.1.6). Read, increment, write is not atomic, so
 *    concurrent hits share a window and may allow slightly more than the limit.
 *    Accepted because the overshoot is bounded by concurrency and the error is
 *    permissive, never destructive: no state is corrupted and no request is
 *    wrongly refused. Not acceptable where the count is the control itself
 *    (one-time-use redemption, a per-user quota that must not be exceeded) —
 *    use a Lua/Redis atomic limiter there.
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
