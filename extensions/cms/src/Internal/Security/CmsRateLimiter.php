<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Security;

use Pulsar\Api\Internal;
use Pulsar\Cache\Application\Lock\LockInterface;
use Pulsar\Cache\Application\TaggedCacheInterface;

use function is_numeric;
use function sprintf;
use function time;

/**
 * Rate limiter for CMS admin operations using the cache layer.
 *
 * Implements a fixed-window counter keyed by operation and identity.
 * Uses a distributed lock around the read-modify-write cycle to prevent
 * TOCTOU races: concurrent requests cannot both observe the same
 * pre-increment value and slip through the limit.
 *
 * Returns false when the limit is exceeded, true when the attempt is allowed.
 */
/**
 * @psalm-api Resolved from the DI container by middleware enforcing per-action
 *            rate limits; not instantiated by name.
 */
#[Internal(reason: 'CMS rate limiting helper; not part of public API')]
final readonly class CmsRateLimiter
{
    public function __construct(
        private TaggedCacheInterface $cache,
        private LockInterface $lock,
    ) {}

    /**
     * Check and record a rate-limited attempt.
     *
     * Acquires a distributed lock before reading/writing the counter so
     * that concurrent requests serialize through the critical section.
     *
     * @param string $key Unique key identifying the operation and actor (e.g., "backup_create:<user_id>")
     * @param int $maxAttempts Maximum allowed attempts within the window
     * @param int $windowSeconds Duration of the rate limit window in seconds
     *
     * @return bool True if the attempt is allowed, false if rate limit exceeded
     */
    public function attempt(string $key, int $maxAttempts, int $windowSeconds = 60): bool
    {
        $window = (int) (time() / $windowSeconds);
        $cacheKey = sprintf('cms_rate:%s:%d', $key, $window);
        $lockResource = sprintf('cms_rate_lock:%s:%d', $key, $window);

        $handle = $this->lock->acquire($lockResource, ttlSeconds: $windowSeconds, timeoutMs: 1000);

        try {
            $current = $this->cache->get($cacheKey);
            $attempts = ($current !== null && is_numeric($current)) ? ((int) $current + 1) : 1;

            $this->cache->set($cacheKey, (string) $attempts, ['cms_rate_limit'], $windowSeconds);

            return $attempts <= $maxAttempts;
        } finally {
            $this->lock->release($handle);
        }
    }
}
