<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Widget;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Contracts\ResourceQueryInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Internal\Widget\ResourceCountWidget;

#[CoversClass(ResourceCountWidget::class)]
final class ResourceCountWidgetTest extends TestCase
{
    #[Test]
    public function idReturnsResourceCount(): void
    {
        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('all')->willReturn([]);
        $query = $this->createStub(ResourceQueryInterface::class);

        $widget = new ResourceCountWidget($registry, $query);

        self::assertSame('resource_count', $widget->id());
    }

    #[Test]
    public function labelReturnsResourceCounts(): void
    {
        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('all')->willReturn([]);
        $query = $this->createStub(ResourceQueryInterface::class);

        $widget = new ResourceCountWidget($registry, $query);

        self::assertSame('Resource Counts', $widget->label());
    }

    #[Test]
    public function sizeReturnsMedium(): void
    {
        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('all')->willReturn([]);
        $query = $this->createStub(ResourceQueryInterface::class);

        $widget = new ResourceCountWidget($registry, $query);

        self::assertSame('medium', $widget->size());
    }

    #[Test]
    public function renderReturnsEmptyResourcesWhenNoRegistrations(): void
    {
        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('all')->willReturn([]);
        $query = $this->createStub(ResourceQueryInterface::class);

        $widget = new ResourceCountWidget($registry, $query);

        $data = $widget->render();

        self::assertArrayHasKey('resources', $data);
        self::assertSame([], $data['resources']);
    }

    #[Test]
    public function renderReturnsCountsForAllResources(): void
    {
        $resource1 = $this->createStub(DataResourceInterface::class);
        $resource1->method('pluralLabel')->willReturn('Users');
        $resource1->method('icon')->willReturn('user');

        $resource2 = $this->createStub(DataResourceInterface::class);
        $resource2->method('pluralLabel')->willReturn('Orders');
        $resource2->method('icon')->willReturn('shopping-cart');

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('all')->willReturn([
            'users' => $resource1,
            'orders' => $resource2,
        ]);

        $query = $this->createStub(ResourceQueryInterface::class);
        $query->method('count')->willReturnOnConsecutiveCalls(42, 100);

        $widget = new ResourceCountWidget($registry, $query);
        $data = $widget->render();

        self::assertArrayHasKey('resources', $data);
        /** @var list<array<string, mixed>> $resources */
        $resources = $data['resources'];
        self::assertCount(2, $resources);

        self::assertSame('users', $resources[0]['name']);
        self::assertSame('Users', $resources[0]['label']);
        self::assertSame('user', $resources[0]['icon']);
        self::assertSame(42, $resources[0]['count']);

        self::assertSame('orders', $resources[1]['name']);
        self::assertSame('Orders', $resources[1]['label']);
        self::assertSame('shopping-cart', $resources[1]['icon']);
        self::assertSame(100, $resources[1]['count']);
    }
}
