<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Navigation;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Navigation\LinkTarget;
use Pulsar\Extension\Cms\Navigation\Menu;
use Pulsar\Extension\Cms\Navigation\MenuItem;

#[CoversClass(Menu::class)]
#[CoversClass(MenuItem::class)]
final class MenuImportIdTest extends TestCase
{
    #[Test]
    public function menuAcceptsImportId(): void
    {
        $menu = new Menu(
            id: 'menu-1',
            tenantId: null,
            location: 'primary',
            createdAt: new DateTimeImmutable(),
            importId: 'menu:primary',
        );

        self::assertSame('menu:primary', $menu->importId);
    }

    #[Test]
    public function menuImportIdDefaultsToNull(): void
    {
        $menu = new Menu(
            id: 'menu-2',
            tenantId: null,
            location: 'footer',
            createdAt: new DateTimeImmutable(),
        );

        self::assertNull($menu->importId);
    }

    #[Test]
    public function menuItemAcceptsImportId(): void
    {
        $item = new MenuItem(
            id: 'item-1',
            menuId: 'menu-1',
            parentId: null,
            contentId: null,
            url: 'https://example.com',
            target: LinkTarget::Self,
            cssClass: null,
            icon: null,
            sortOrder: 0,
            visible: true,
            importId: 'menuitem:home',
        );

        self::assertSame('menuitem:home', $item->importId);
    }

    #[Test]
    public function menuItemImportIdDefaultsToNull(): void
    {
        $item = new MenuItem(
            id: 'item-2',
            menuId: 'menu-1',
            parentId: null,
            contentId: null,
            url: null,
            target: LinkTarget::Self,
            cssClass: null,
            icon: null,
            sortOrder: 0,
            visible: true,
        );

        self::assertNull($item->importId);
    }
}
