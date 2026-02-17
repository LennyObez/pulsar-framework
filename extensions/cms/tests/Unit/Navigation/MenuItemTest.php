<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Navigation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Navigation\LinkTarget;
use Pulsar\Extension\Cms\Navigation\MenuItem;

#[CoversClass(MenuItem::class)]
final class MenuItemTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $item = new MenuItem(
            id: 'item-001',
            menuId: 'menu-001',
            parentId: null,
            contentId: 'content-abc',
            url: null,
            target: LinkTarget::Self,
            cssClass: 'nav-link',
            icon: 'home',
            sortOrder: 0,
            visible: true,
            importId: 'import-001',
        );

        self::assertSame('item-001', $item->id);
        self::assertSame('menu-001', $item->menuId);
        self::assertNull($item->parentId);
        self::assertSame('content-abc', $item->contentId);
        self::assertNull($item->url);
        self::assertSame(LinkTarget::Self, $item->target);
        self::assertSame('nav-link', $item->cssClass);
        self::assertSame('home', $item->icon);
        self::assertSame(0, $item->sortOrder);
        self::assertTrue($item->visible);
        self::assertSame('import-001', $item->importId);
    }

    #[Test]
    public function externalLinkUsesBlankTarget(): void
    {
        $item = new MenuItem(
            id: 'item-002',
            menuId: 'menu-001',
            parentId: null,
            contentId: null,
            url: 'https://example.com',
            target: LinkTarget::Blank,
            cssClass: null,
            icon: null,
            sortOrder: 1,
            visible: true,
        );

        self::assertSame('https://example.com', $item->url);
        self::assertSame(LinkTarget::Blank, $item->target);
        self::assertNull($item->contentId);
    }

    #[Test]
    public function hiddenItemHasVisibleFalse(): void
    {
        $item = new MenuItem(
            id: 'item-003',
            menuId: 'menu-001',
            parentId: 'item-001',
            contentId: null,
            url: '/hidden',
            target: LinkTarget::Self,
            cssClass: null,
            icon: null,
            sortOrder: 5,
            visible: false,
        );

        self::assertFalse($item->visible);
        self::assertSame('item-001', $item->parentId);
    }
}
