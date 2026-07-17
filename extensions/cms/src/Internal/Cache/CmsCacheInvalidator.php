<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Cache;

use Pulsar\Api\Internal;
use Pulsar\Cache\Application\TaggedCacheInterface;

use function bin2hex;
use function random_bytes;

/**
 * @psalm-api Resolved from the DI container by content lifecycle event listeners
 *            and admin controllers; not instantiated by name.
 */
#[Internal(reason: 'CMS cache invalidation')]
final readonly class CmsCacheInvalidator
{
    public function __construct(
        private TaggedCacheInterface $cache,
    ) {}

    public function invalidateContent(string $contentId): void
    {
        $this->cache->invalidateTags([CmsCacheKeys::contentTag($contentId), CmsCacheKeys::TAG_ALL_PAGES]);
        $this->bumpEpoch();
    }

    public function invalidateMenu(string $location): void
    {
        // Cached pages cannot know which menus they rendered, so a menu change
        // invalidates the coarse page tag: over-invalidation is safe, a stale
        // navigation served for an hour is not.
        $this->cache->invalidateTags([CmsCacheKeys::menuTag($location), CmsCacheKeys::TAG_ALL_PAGES]);
        $this->bumpEpoch();
    }

    public function invalidateSettings(): void
    {
        $this->cache->invalidateTag(CmsCacheKeys::TAG_SETTINGS);
        $this->bumpEpoch();
    }

    public function invalidateAll(): void
    {
        $this->cache->invalidateTags([CmsCacheKeys::TAG_SETTINGS, CmsCacheKeys::TAG_ALL_MENUS, CmsCacheKeys::TAG_ALL_CONTENT, CmsCacheKeys::TAG_ALL_PAGES]);
        $this->bumpEpoch();
    }

    /**
     * Mark the invalidation epoch so a page render in flight knows its body
     * may predate this change and abandons its cache write.
     */
    private function bumpEpoch(): void
    {
        $this->cache->set(CmsCacheKeys::INVALIDATION_EPOCH_KEY, bin2hex(random_bytes(8)), [], null);
    }
}
