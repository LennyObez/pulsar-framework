<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Cache;

use Pulsar\Api\Internal;
use Pulsar\Cache\Application\TaggedCacheInterface;

#[Internal(reason: 'CMS cache invalidation')]
final readonly class CmsCacheInvalidator
{
    public function __construct(
        private TaggedCacheInterface $cache,
    ) {}

    public function invalidateContent(string $contentId): void
    {
        $this->cache->invalidateTag('cms_content:' . $contentId);
    }

    public function invalidateMenu(string $location): void
    {
        $this->cache->invalidateTag('cms_menu:' . $location);
    }

    public function invalidateSettings(): void
    {
        $this->cache->invalidateTag('cms_settings');
    }

    public function invalidateAll(): void
    {
        $this->cache->invalidateTags(['cms_settings', 'cms_menu', 'cms_content']);
    }
}
