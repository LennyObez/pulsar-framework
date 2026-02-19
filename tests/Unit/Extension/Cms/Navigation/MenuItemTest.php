<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Navigation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Navigation\LinkTarget;
use Pulsar\Extension\Cms\Navigation\MenuItem;
use ReflectionClass;

#[CoversClass(MenuItem::class)]
final class MenuItemTest extends TestCase
{
    private const string MENU_ID = '01912345-6789-7abc-8def-0123456789ab';

    #[Test]
    public function test_construction_with_content_link(): void
    {
        $item = new MenuItem(
            id: 'mi-001',
            menuId: self::MENU_ID,
            parentId: null,
            contentId: 'content-001',
            url: null,
            target: LinkTarget::Self,
            cssClass: null,
            icon: null,
            sortOrder: 0,
            visible: true,
        );

        self::assertSame('mi-001', $item->id);
        self::assertSame(self::MENU_ID, $item->menuId);
        self::assertNull($item->parentId);
        self::assertSame('content-001', $item->contentId);
        self::assertNull($item->url);
        self::assertSame(LinkTarget::Self, $item->target);
        self::assertTrue($item->visible);
    }

    #[Test]
    public function test_construction_with_external_url(): void
    {
        $item = new MenuItem(
            id: 'mi-002',
            menuId: self::MENU_ID,
            parentId: null,
            contentId: null,
            url: 'https://example.com',
            target: LinkTarget::Blank,
            cssClass: 'external-link',
            icon: 'external-link-alt',
            sortOrder: 1,
            visible: true,
        );

        self::assertNull($item->contentId);
        self::assertSame('https://example.com', $item->url);
        self::assertSame(LinkTarget::Blank, $item->target);
        self::assertSame('external-link', $item->cssClass);
        self::assertSame('external-link-alt', $item->icon);
    }

    #[Test]
    public function test_tree_construction_from_flat_items(): void
    {
        $root = new MenuItem('mi-root', self::MENU_ID, null, 'c1', null, LinkTarget::Self, null, null, 0, true);
        $child1 = new MenuItem('mi-child1', self::MENU_ID, 'mi-root', 'c2', null, LinkTarget::Self, null, null, 0, true);
        $child2 = new MenuItem('mi-child2', self::MENU_ID, 'mi-root', 'c3', null, LinkTarget::Self, null, null, 1, true);
        $grandchild = new MenuItem('mi-gc1', self::MENU_ID, 'mi-child1', 'c4', null, LinkTarget::Self, null, null, 0, true);

        // Build tree structure from flat list
        $items = [$root, $child1, $child2, $grandchild];
        $childrenMap = [];

        foreach ($items as $item) {
            $parentKey = $item->parentId ?? '__root__';
            $childrenMap[$parentKey][] = $item;
        }

        // Root items
        self::assertCount(1, $childrenMap['__root__']);
        self::assertSame('mi-root', $childrenMap['__root__'][0]->id);

        // Children of root
        self::assertCount(2, $childrenMap['mi-root']);

        // Grandchildren
        self::assertCount(1, $childrenMap['mi-child1']);
        self::assertSame('mi-gc1', $childrenMap['mi-child1'][0]->id);
    }

    #[Test]
    public function test_hidden_item(): void
    {
        $item = new MenuItem('mi-hidden', self::MENU_ID, null, 'c1', null, LinkTarget::Self, null, null, 0, false);

        self::assertFalse($item->visible);
    }

    #[Test]
    public function test_is_readonly_class(): void
    {
        $reflection = new ReflectionClass(MenuItem::class);
        self::assertTrue($reflection->isReadOnly());
    }
}
