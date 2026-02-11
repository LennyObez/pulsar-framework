<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Internal\Cache\CmsCacheInvalidator;

#[CoversClass(CmsCacheInvalidator::class)]
final class CmsCacheInvalidatorTest extends TestCase
{
    #[Test]
    public function invalidateContentCallsInvalidateTagWithContentId(): void
    {
        $cache = $this->createMock(TaggedCacheInterface::class);
        $cache->expects(self::once())
            ->method('invalidateTag')
            ->with('cms_content:post-123');

        $invalidator = new CmsCacheInvalidator($cache);
        $invalidator->invalidateContent('post-123');
    }

    #[Test]
    public function invalidateMenuCallsInvalidateTagWithLocation(): void
    {
        $cache = $this->createMock(TaggedCacheInterface::class);
        $cache->expects(self::once())
            ->method('invalidateTag')
            ->with('cms_menu:header');

        $invalidator = new CmsCacheInvalidator($cache);
        $invalidator->invalidateMenu('header');
    }

    #[Test]
    public function invalidateSettingsCallsInvalidateTagForSettings(): void
    {
        $cache = $this->createMock(TaggedCacheInterface::class);
        $cache->expects(self::once())
            ->method('invalidateTag')
            ->with('cms_settings');

        $invalidator = new CmsCacheInvalidator($cache);
        $invalidator->invalidateSettings();
    }

    #[Test]
    public function invalidateAllCallsInvalidateTagsWithAllCmsTags(): void
    {
        $cache = $this->createMock(TaggedCacheInterface::class);
        $cache->expects(self::once())
            ->method('invalidateTags')
            ->with(['cms_settings', 'cms_menu', 'cms_content']);

        $invalidator = new CmsCacheInvalidator($cache);
        $invalidator->invalidateAll();
    }
}
