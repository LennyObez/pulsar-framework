<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Navigation;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Navigation\LinkTarget;
use Pulsar\Extension\Cms\Navigation\Menu;
use Pulsar\Extension\Cms\Navigation\MenuItem;
use Pulsar\Extension\Cms\Navigation\MenuItemTranslation;
use Pulsar\Extension\Cms\Navigation\MenuTranslation;

#[CoversClass(Menu::class)]
#[CoversClass(MenuItem::class)]
#[CoversClass(LinkTarget::class)]
#[CoversClass(MenuItemTranslation::class)]
#[CoversClass(MenuTranslation::class)]
final class NavigationEntitiesTest extends TestCase
{
    #[Test]
    public function menuConstructor(): void
    {
        $now = new DateTimeImmutable('2025-03-07T10:00:00+00:00');

        $menu = new Menu(
            id: '0194d4e0-1111-7000-2222-000000000001',
            tenantId: 'tenant-01',
            location: 'primary',
            createdAt: $now,
        );

        self::assertSame('0194d4e0-1111-7000-2222-000000000001', $menu->id);
        self::assertSame('tenant-01', $menu->tenantId);
        self::assertSame('primary', $menu->location);
        self::assertSame($now, $menu->createdAt);
    }

    #[Test]
    public function menuWithoutTenant(): void
    {
        $menu = new Menu(
            id: '0194d4e0-1111-7000-2222-000000000002',
            tenantId: null,
            location: 'footer',
            createdAt: new DateTimeImmutable(),
        );

        self::assertNull($menu->tenantId);
        self::assertSame('footer', $menu->location);
    }

    #[Test]
    public function menuItemWithContentLink(): void
    {
        $item = new MenuItem(
            id: '0194d4e0-3333-7000-4444-000000000001',
            menuId: '0194d4e0-1111-7000-2222-000000000001',
            parentId: null,
            contentId: '0194d4e0-5555-7000-6666-000000000001',
            url: null,
            target: LinkTarget::Self,
            cssClass: 'nav-link--primary',
            icon: 'home',
            sortOrder: 0,
            visible: true,
        );

        self::assertNotNull($item->contentId);
        self::assertNull($item->url);
        self::assertSame(LinkTarget::Self, $item->target);
        self::assertSame('nav-link--primary', $item->cssClass);
        self::assertSame('home', $item->icon);
        self::assertSame(0, $item->sortOrder);
        self::assertTrue($item->visible);
    }

    #[Test]
    public function menuItemWithExternalUrl(): void
    {
        $item = new MenuItem(
            id: '0194d4e0-3333-7000-4444-000000000002',
            menuId: '0194d4e0-1111-7000-2222-000000000001',
            parentId: '0194d4e0-3333-7000-4444-000000000001',
            contentId: null,
            url: 'https://docs.example.com',
            target: LinkTarget::Blank,
            cssClass: null,
            icon: null,
            sortOrder: 5,
            visible: false,
        );

        self::assertNull($item->contentId);
        self::assertSame('https://docs.example.com', $item->url);
        self::assertSame(LinkTarget::Blank, $item->target);
        self::assertNull($item->cssClass);
        self::assertFalse($item->visible);
    }

    #[Test]
    public function menuItemTranslationConstructor(): void
    {
        $trans = new MenuItemTranslation(
            menuItemId: '0194d4e0-3333-7000-4444-000000000001',
            locale: 'de',
            label: 'Startseite',
            titleAttr: 'Zurück zur Startseite',
        );

        self::assertSame('de', $trans->locale);
        self::assertSame('Startseite', $trans->label);
        self::assertSame('Zurück zur Startseite', $trans->titleAttr);
    }

    #[Test]
    public function menuItemTranslationWithoutTitleAttr(): void
    {
        $trans = new MenuItemTranslation(
            menuItemId: '0194d4e0-3333-7000-4444-000000000002',
            locale: 'fr',
            label: 'Accueil',
            titleAttr: null,
        );

        self::assertNull($trans->titleAttr);
    }

    #[Test]
    public function menuTranslationConstructor(): void
    {
        $trans = new MenuTranslation(
            menuId: '0194d4e0-1111-7000-2222-000000000001',
            locale: 'es',
            name: 'Navegación principal',
        );

        self::assertSame('es', $trans->locale);
        self::assertSame('Navegación principal', $trans->name);
    }

    #[Test]
    public function linkTargetValues(): void
    {
        self::assertSame('_self', LinkTarget::Self->value);
        self::assertSame('_blank', LinkTarget::Blank->value);
    }
}
