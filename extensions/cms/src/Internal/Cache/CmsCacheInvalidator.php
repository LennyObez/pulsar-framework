<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Cache;

use Pulsar\Api\Internal;
use Pulsar\Cache\Application\TaggedCacheInterface;

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
        $this->cache->invalidateTag(CmsCacheKeys::contentTag($contentId));
    }

    public function invalidateMenu(string $location): void
    {
        $this->cache->invalidateTag(CmsCacheKeys::menuTag($location));
    }

    public function invalidateSettings(): void
    {
        $this->cache->invalidateTag(CmsCacheKeys::TAG_SETTINGS);
    }

    public function invalidateAll(): void
    {
        $this->cache->invalidateTags([CmsCacheKeys::TAG_SETTINGS, CmsCacheKeys::TAG_ALL_MENUS, CmsCacheKeys::TAG_ALL_CONTENT]);
    }
}
