<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Navigation;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Navigation\Menu;

#[CoversClass(Menu::class)]
final class MenuTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $now = new DateTimeImmutable('2026-01-15T12:00:00Z');

        $menu = new Menu(
            id: 'menu-001',
            tenantId: 'tenant-01',
            location: 'primary',
            createdAt: $now,
            importId: 'import-abc',
        );

        self::assertSame('menu-001', $menu->id);
        self::assertSame('tenant-01', $menu->tenantId);
        self::assertSame('primary', $menu->location);
        self::assertSame($now, $menu->createdAt);
        self::assertSame('import-abc', $menu->importId);
    }

    #[Test]
    public function tenantIdIsNullableForSingleTenantSites(): void
    {
        $menu = new Menu(
            id: 'menu-002',
            tenantId: null,
            location: 'footer',
            createdAt: new DateTimeImmutable(),
        );

        self::assertNull($menu->tenantId);
    }

    #[Test]
    public function importIdDefaultsToNull(): void
    {
        $menu = new Menu(
            id: 'menu-003',
            tenantId: null,
            location: 'sidebar',
            createdAt: new DateTimeImmutable(),
        );

        self::assertNull($menu->importId);
    }
}
