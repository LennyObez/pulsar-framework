<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Forms;

use Override;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Forms\SpamDetection\SpamDetectorInterface;
use Pulsar\Extension\Cms\Forms\SpamDetection\SpamResult;

use function hash;
use function is_int;
use function is_string;

/**
 * Detects spam by rate-limiting submissions per IP address.
 *
 * @psalm-api Aggregated by SpamScorer through the SpamDetectorInterface contract;
 *            resolved from the DI container, not instantiated by name.
 */
#[Internal(reason: 'Spam detector; use SpamDetectorInterface')]
final readonly class RateLimitDetector implements SpamDetectorInterface
{
    public function __construct(
        private CacheInterface $cache,
        private int $maxPerHour = 10,
    ) {}

    #[Override]
    public function detect(array $data, array $meta): SpamResult
    {
        $ip = isset($meta['ip']) && is_string($meta['ip']) ? $meta['ip'] : '';

        if ($ip === '') {
            return new SpamResult(false, 0.0, null);
        }

        $tenantId = isset($meta['tenant_id']) && is_string($meta['tenant_id']) ? $meta['tenant_id'] : '';
        $cacheKey = 'cms_form_rate_' . hash('xxh128', $tenantId . ':' . $ip);
        /** @var mixed $count */
        $count = $this->cache->get($cacheKey);

        $currentCount = is_int($count) ? $count : 0;
        $currentCount++;

        $this->cache->set($cacheKey, $currentCount, 3600);

        if ($currentCount > $this->maxPerHour) {
            return new SpamResult(true, 7.0, 'Rate limit exceeded (' . $currentCount . ' submissions/hour)');
        }

        return new SpamResult(false, 0.0, null);
    }
}
