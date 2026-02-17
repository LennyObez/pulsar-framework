<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Widget;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Contracts\ResourceQueryInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Internal\Widget\ResourceCountWidget;

final class ResourceCountWidgetTest extends TestCase
{
    #[Test]
    public function id_returns_resource_count(): void
    {
        $widget = $this->createWidget([], []);

        self::assertSame('resource_count', $widget->id());
    }

    #[Test]
    public function label_returns_human_readable(): void
    {
        $widget = $this->createWidget([], []);

        self::assertSame('Resource Counts', $widget->label());
    }

    #[Test]
    public function size_returns_medium(): void
    {
        $widget = $this->createWidget([], []);

        self::assertSame('medium', $widget->size());
    }

    #[Test]
    public function render_returns_resource_counts(): void
    {
        $usersResource = $this->createResourceStub('Users', 'users-icon');
        $ordersResource = $this->createResourceStub('Orders', 'cart-icon');

        $widget = $this->createWidget(
            ['users' => $usersResource, 'orders' => $ordersResource],
            ['users' => 42, 'orders' => 108],
        );

        $data = $widget->render();

        self::assertArrayHasKey('resources', $data);
        self::assertCount(2, $data['resources']);

        self::assertSame('users', $data['resources'][0]['name']);
        self::assertSame('Users', $data['resources'][0]['label']);
        self::assertSame('users-icon', $data['resources'][0]['icon']);
        self::assertSame(42, $data['resources'][0]['count']);

        self::assertSame('orders', $data['resources'][1]['name']);
        self::assertSame(108, $data['resources'][1]['count']);
    }

    #[Test]
    public function render_with_no_resources(): void
    {
        $widget = $this->createWidget([], []);
        $data = $widget->render();

        self::assertSame(['resources' => []], $data);
    }

    /**
     * @param array<string, DataResourceInterface&Stub> $resources
     * @param array<string, int> $counts
     */
    private function createWidget(array $resources, array $counts): ResourceCountWidget
    {
        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('all')->willReturn($resources);

        $query = $this->createStub(ResourceQueryInterface::class);
        $query->method('count')->willReturnCallback(
            static function (DataResourceInterface $resource) use ($counts, $resources): int {
                foreach ($resources as $name => $r) {
                    if ($r === $resource) {
                        return $counts[$name] ?? 0;
                    }
                }
                return 0;
            },
        );

        return new ResourceCountWidget($registry, $query);
    }

    private function createResourceStub(string $pluralLabel, string $icon): DataResourceInterface&Stub
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('pluralLabel')->willReturn($pluralLabel);
        $resource->method('icon')->willReturn($icon);

        return $resource;
    }
}
