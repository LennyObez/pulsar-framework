<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Internal;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Extension\Cms\Internal\Cache\CachedMenuRepository;
use Pulsar\Extension\Cms\Navigation\LinkTarget;
use Pulsar\Extension\Cms\Navigation\Menu;
use Pulsar\Extension\Cms\Navigation\MenuItem;
use Pulsar\Extension\Cms\Navigation\MenuRepositoryInterface;

#[CoversClass(CachedMenuRepository::class)]
final class CachedMenuRepositoryTest extends TestCase
{
    #[Test]
    public function findByLocationReturnsCachedValue(): void
    {
        $menu = new Menu('m1', null, 'header', new DateTimeImmutable());

        $cache = $this->createStub(TaggedCacheInterface::class);
        $cache->method('get')->willReturn($menu);

        $inner = $this->createStub(MenuRepositoryInterface::class);
        // Inner should NOT be called when cache hits
        $inner->method('findByLocation')->willReturn(null);

        $repo = new CachedMenuRepository($inner, $cache);
        $result = $repo->findByLocation('header', 'en');

        self::assertSame($menu, $result);
    }

    #[Test]
    public function findByLocationDelegatesToInnerOnCacheMiss(): void
    {
        $menu = new Menu('m1', null, 'header', new DateTimeImmutable());

        $cache = $this->createMock(TaggedCacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->expects(self::once())->method('set');

        $inner = $this->createStub(MenuRepositoryInterface::class);
        $inner->method('findByLocation')->willReturn($menu);

        $repo = new CachedMenuRepository($inner, $cache);
        $result = $repo->findByLocation('header', 'en');

        self::assertSame($menu, $result);
    }

    #[Test]
    public function findByLocationDoesNotCacheNullResult(): void
    {
        $cache = $this->createMock(TaggedCacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->expects(self::never())->method('set');

        $inner = $this->createStub(MenuRepositoryInterface::class);
        $inner->method('findByLocation')->willReturn(null);

        $repo = new CachedMenuRepository($inner, $cache);
        $result = $repo->findByLocation('header', 'en');

        self::assertNull($result);
    }

    #[Test]
    public function saveInvalidatesMenuLocationCache(): void
    {
        $menu = new Menu('m1', null, 'footer', new DateTimeImmutable());

        $cache = $this->createMock(TaggedCacheInterface::class);
        $cache->expects(self::once())
            ->method('invalidateTag')
            ->with('cms_menu:footer');

        $inner = $this->createMock(MenuRepositoryInterface::class);
        $inner->expects(self::once())->method('save');

        $repo = new CachedMenuRepository($inner, $cache);
        $repo->save($menu, []);
    }

    #[Test]
    public function saveItemInvalidatesAllMenusCache(): void
    {
        $item = new MenuItem('i1', 'm1', null, null, '/about', LinkTarget::Self, null, null, 0, true);

        $cache = $this->createMock(TaggedCacheInterface::class);
        $cache->expects(self::once())
            ->method('invalidateTag')
            ->with('cms_menu');

        $inner = $this->createMock(MenuRepositoryInterface::class);
        $inner->expects(self::once())->method('saveItem');

        $repo = new CachedMenuRepository($inner, $cache);
        $repo->saveItem($item, []);
    }
}
