<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Internal\Cache\CmsCacheInvalidator;
use Pulsar\Extension\Cms\Internal\Cache\CmsCacheKeys;

#[CoversClass(CmsCacheInvalidator::class)]
final class CmsCacheInvalidatorTest extends TestCase
{
    #[Test]
    public function invalidateContentBumpsTheContentTagThePagesTagAndTheEpoch(): void
    {
        $cache = $this->createMock(TaggedCacheInterface::class);
        $cache->expects(self::once())
            ->method('invalidateTags')
            ->with(['cms_content.post-123', CmsCacheKeys::TAG_ALL_PAGES]);
        $cache->expects(self::once())
            ->method('set')
            ->with(CmsCacheKeys::INVALIDATION_EPOCH_KEY, self::isString(), [], null);

        $invalidator = new CmsCacheInvalidator($cache);
        $invalidator->invalidateContent('post-123');
    }

    #[Test]
    public function invalidateMenuBumpsTheMenuTagAndTheCoarsePagesTag(): void
    {
        // Cached pages cannot know which menus they rendered, so a menu change
        // must reach every page entry via the coarse tag.
        $cache = $this->createMock(TaggedCacheInterface::class);
        $cache->expects(self::once())
            ->method('invalidateTags')
            ->with(['cms_menu.header', CmsCacheKeys::TAG_ALL_PAGES]);
        $cache->expects(self::once())
            ->method('set')
            ->with(CmsCacheKeys::INVALIDATION_EPOCH_KEY, self::isString(), [], null);

        $invalidator = new CmsCacheInvalidator($cache);
        $invalidator->invalidateMenu('header');
    }

    #[Test]
    public function invalidateSettingsBumpsTheSettingsTagAndTheEpoch(): void
    {
        $cache = $this->createMock(TaggedCacheInterface::class);
        $cache->expects(self::once())
            ->method('invalidateTag')
            ->with('cms_settings');
        $cache->expects(self::once())
            ->method('set')
            ->with(CmsCacheKeys::INVALIDATION_EPOCH_KEY, self::isString(), [], null);

        $invalidator = new CmsCacheInvalidator($cache);
        $invalidator->invalidateSettings();
    }

    #[Test]
    public function invalidateAllBumpsEveryCoarseTagAndTheEpoch(): void
    {
        $cache = $this->createMock(TaggedCacheInterface::class);
        $cache->expects(self::once())
            ->method('invalidateTags')
            ->with(['cms_settings', 'cms_menu', 'cms_content', CmsCacheKeys::TAG_ALL_PAGES]);
        $cache->expects(self::once())
            ->method('set')
            ->with(CmsCacheKeys::INVALIDATION_EPOCH_KEY, self::isString(), [], null);

        $invalidator = new CmsCacheInvalidator($cache);
        $invalidator->invalidateAll();
    }
}
