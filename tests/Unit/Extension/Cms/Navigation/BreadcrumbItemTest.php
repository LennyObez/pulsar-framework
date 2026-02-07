<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Navigation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Navigation\BreadcrumbItem;
use ReflectionClass;

#[CoversClass(BreadcrumbItem::class)]
final class BreadcrumbItemTest extends TestCase
{
    #[Test]
    public function test_construction(): void
    {
        $item = new BreadcrumbItem(
            label: 'Home',
            url: '/',
            isCurrent: false,
        );

        self::assertSame('Home', $item->label);
        self::assertSame('/', $item->url);
        self::assertFalse($item->isCurrent);
    }

    #[Test]
    public function test_current_page_item(): void
    {
        $item = new BreadcrumbItem(
            label: 'Getting Started',
            url: '/docs/getting-started',
            isCurrent: true,
        );

        self::assertTrue($item->isCurrent);
    }

    #[Test]
    public function test_root_page_single_item(): void
    {
        $trail = [
            new BreadcrumbItem(label: 'Home', url: '/', isCurrent: true),
        ];

        self::assertCount(1, $trail);
        self::assertTrue($trail[0]->isCurrent);
        self::assertSame('/', $trail[0]->url);
    }

    #[Test]
    public function test_nested_page_full_trail(): void
    {
        $trail = [
            new BreadcrumbItem(label: 'Home', url: '/', isCurrent: false),
            new BreadcrumbItem(label: 'Docs', url: '/docs', isCurrent: false),
            new BreadcrumbItem(label: 'Getting Started', url: '/docs/getting-started', isCurrent: true),
        ];

        self::assertCount(3, $trail);
        self::assertFalse($trail[0]->isCurrent);
        self::assertFalse($trail[1]->isCurrent);
        self::assertTrue($trail[2]->isCurrent);
        self::assertSame('Home', $trail[0]->label);
        self::assertSame('Docs', $trail[1]->label);
        self::assertSame('Getting Started', $trail[2]->label);
    }

    #[Test]
    public function test_is_readonly_class(): void
    {
        $reflection = new ReflectionClass(BreadcrumbItem::class);
        self::assertTrue($reflection->isReadOnly());
    }
}
