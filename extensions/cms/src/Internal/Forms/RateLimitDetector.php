<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Forms;

use Override;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Forms\SpamDetection\SpamDetectorInterface;
use Pulsar\Extension\Cms\Forms\SpamDetection\SpamResult;

use function is_int;
use function is_string;
use function sha1;

/**
 * Detects spam by rate-limiting submissions per IP address.
 */
#[Internal(reason: 'Spam detector — use SpamDetectorInterface')]
final readonly class RateLimitDetector implements SpamDetectorInterface
{
    public function __construct(
        private CacheInterface $cache,
        private int $maxPerHour = 10,
    ) {}

    #[Override]
    public function detect(array $data, array $meta): SpamResult
    {
        /** @var string $ip */
        $ip = isset($meta['ip']) && is_string($meta['ip']) ? $meta['ip'] : '';

        if ($ip === '') {
            return new SpamResult(false, 0.0, null);
        }

        /** @var string $tenantId */
        $tenantId = isset($meta['tenant_id']) && is_string($meta['tenant_id']) ? $meta['tenant_id'] : '';
        $cacheKey = 'cms_form_rate_' . sha1($tenantId . ':' . $ip);
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
