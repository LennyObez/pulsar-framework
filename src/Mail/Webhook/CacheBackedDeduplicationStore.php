<?php

declare(strict_types=1);

namespace Pulsar\Mail\Webhook;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Cache\Application\TaggedCacheInterface;

use function hash;
use function max;

/**
 * Cache-backed webhook deduplication store.
 *
 * Unlike {@see InMemoryDeduplicationStore}, this survives across requests and
 * workers, so a provider redelivering the same event (or an attacker replaying
 * one) is detected regardless of which process handles it. Entries expire by
 * TTL, which doubles as the dedup window.
 */
#[Internal]
final readonly class CacheBackedDeduplicationStore implements WebhookDeduplicationStoreInterface
{
    private const string CACHE_TAG = 'mail-webhook-dedup';

    public function __construct(
        private TaggedCacheInterface $cache,
        private int $ttlSeconds = 604800,
    ) {}

    #[Override]
    public function has(string $eventId, ?string $tenantId = null): bool
    {
        return $this->cache->get($this->key($eventId, $tenantId)) !== null;
    }

    #[Override]
    public function store(string $eventId, ?string $tenantId = null): void
    {
        $this->cache->set($this->key($eventId, $tenantId), '1', [self::CACHE_TAG], max(1, $this->ttlSeconds));
    }

    #[Override]
    public function cleanup(int $maxAgeDays): void
    {
        // No-op: entries expire by TTL. (Invalidating the tag would clear *all*
        // dedup state and reopen the replay window, so it is intentionally not done.)
    }

    private function key(string $eventId, ?string $tenantId): string
    {
        // Hash tenant + event together so arbitrary tenant/event ids can never
        // introduce cache-key reserved characters (PSR-6: {}()/\@:).
        return 'mail-webhook.dedup.' . hash('sha256', ($tenantId ?? 'default') . "\0" . $eventId);
    }
}
