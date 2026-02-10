<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Security;

use Pulsar\Api\Internal;
use Pulsar\Cache\Application\TaggedCacheInterface;

use function sprintf;
use function time;

/**
 * Simple rate limiter for CMS admin operations using the cache layer.
 *
 * Implements a fixed-window counter keyed by operation and identity.
 * Returns false when the limit is exceeded, true when the attempt is allowed.
 */
#[Internal(reason: 'CMS rate limiting helper — not part of public API')]
final readonly class CmsRateLimiter
{
    public function __construct(
        private TaggedCacheInterface $cache,
    ) {}

    /**
     * Check and record a rate-limited attempt.
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

        $current = $this->cache->get($cacheKey);
        $attempts = ($current !== null) ? ((int) $current + 1) : 1;

        if ($attempts > $maxAttempts) {
            return false;
        }

        $this->cache->set($cacheKey, (string) $attempts, ['cms_rate_limit'], $windowSeconds);

        return true;
    }
}
