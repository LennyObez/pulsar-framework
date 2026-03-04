<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Navigation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Navigation\LinkTarget;
use Pulsar\Extension\Cms\Navigation\MenuItemResolved;

#[CoversClass(MenuItemResolved::class)]
final class MenuItemResolvedTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $item = new MenuItemResolved(
            id: 'item-1',
            menuId: 'menu-1',
            parentId: null,
            contentId: 'content-1',
            url: null,
            label: 'Features',
            titleAttr: 'View features',
            target: LinkTarget::Self,
            cssClass: 'nav-features',
            icon: 'star',
            sortOrder: 2,
            visible: true,
            contentPath: 'features',
        );

        self::assertSame('item-1', $item->id);
        self::assertSame('menu-1', $item->menuId);
        self::assertNull($item->parentId);
        self::assertSame('content-1', $item->contentId);
        self::assertNull($item->url);
        self::assertSame('Features', $item->label);
        self::assertSame('View features', $item->titleAttr);
        self::assertSame(LinkTarget::Self, $item->target);
        self::assertSame('nav-features', $item->cssClass);
        self::assertSame('star', $item->icon);
        self::assertSame(2, $item->sortOrder);
        self::assertTrue($item->visible);
        self::assertSame('features', $item->contentPath);
    }

    #[Test]
    public function contentPathDefaultsToNull(): void
    {
        $item = new MenuItemResolved(
            id: 'item-2',
            menuId: 'menu-1',
            parentId: null,
            contentId: null,
            url: 'https://external.com',
            label: 'External',
            titleAttr: null,
            target: LinkTarget::Blank,
            cssClass: null,
            icon: null,
            sortOrder: 0,
            visible: true,
        );

        self::assertNull($item->contentPath);
        self::assertSame('https://external.com', $item->url);
        self::assertSame(LinkTarget::Blank, $item->target);
    }
}
