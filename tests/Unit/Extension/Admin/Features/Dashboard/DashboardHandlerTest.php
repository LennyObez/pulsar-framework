<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Features\Dashboard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Contracts\WidgetInterface;
use Pulsar\Extension\Admin\Features\Dashboard\DashboardHandler;

#[CoversClass(DashboardHandler::class)]
final class DashboardHandlerTest extends TestCase
{
    private ResourceRegistryInterface&Stub $registry;

    protected function setUp(): void
    {
        $this->registry = $this->createStub(ResourceRegistryInterface::class);
    }

    #[Test]
    public function rendersDashboardWithWidgets(): void
    {
        /** @var WidgetInterface&Stub $widget */
        $widget = $this->createStub(WidgetInterface::class);
        $widget->method('id')->willReturn('total-users');
        $widget->method('label')->willReturn('Total Users');
        $widget->method('size')->willReturn('small');
        $widget->method('render')->willReturn(['count' => 42]);

        $this->registry->method('all')->willReturn([]);

        $handler = new DashboardHandler($this->registry, [$widget]);
        $result = $handler->execute();

        self::assertCount(1, $result->widgets);
        self::assertSame('total-users', $result->widgets[0]['id']);
        self::assertSame('Total Users', $result->widgets[0]['label']);
        self::assertSame('small', $result->widgets[0]['size']);
        self::assertSame(['count' => 42], $result->widgets[0]['data']);
    }

    #[Test]
    public function listsRegisteredResources(): void
    {
        /** @var DataResourceInterface&Stub $resource */
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('pluralLabel')->willReturn('Users');
        $resource->method('icon')->willReturn('users-icon');

        $this->registry->method('all')->willReturn(['users' => $resource]);

        $handler = new DashboardHandler($this->registry, []);
        $result = $handler->execute();

        self::assertCount(1, $result->resources);
        self::assertSame('users', $result->resources[0]['name']);
        self::assertSame('Users', $result->resources[0]['label']);
        self::assertSame('users-icon', $result->resources[0]['icon']);
    }

    #[Test]
    public function handlesEmptyDashboard(): void
    {
        $this->registry->method('all')->willReturn([]);

        $handler = new DashboardHandler($this->registry, []);
        $result = $handler->execute();

        self::assertSame([], $result->widgets);
        self::assertSame([], $result->resources);
    }

    #[Test]
    public function rendersMultipleWidgets(): void
    {
        /** @var WidgetInterface&Stub $widget1 */
        $widget1 = $this->createStub(WidgetInterface::class);
        $widget1->method('id')->willReturn('w1');
        $widget1->method('label')->willReturn('Widget 1');
        $widget1->method('size')->willReturn('medium');
        $widget1->method('render')->willReturn(['value' => 10]);

        /** @var WidgetInterface&Stub $widget2 */
        $widget2 = $this->createStub(WidgetInterface::class);
        $widget2->method('id')->willReturn('w2');
        $widget2->method('label')->willReturn('Widget 2');
        $widget2->method('size')->willReturn('large');
        $widget2->method('render')->willReturn(['value' => 20]);

        $this->registry->method('all')->willReturn([]);

        $handler = new DashboardHandler($this->registry, [$widget1, $widget2]);
        $result = $handler->execute();

        self::assertCount(2, $result->widgets);
        self::assertSame('w1', $result->widgets[0]['id']);
        self::assertSame('w2', $result->widgets[1]['id']);
    }

    #[Test]
    public function listsMultipleResources(): void
    {
        /** @var DataResourceInterface&Stub $resource1 */
        $resource1 = $this->createStub(DataResourceInterface::class);
        $resource1->method('pluralLabel')->willReturn('Users');
        $resource1->method('icon')->willReturn('user-icon');

        /** @var DataResourceInterface&Stub $resource2 */
        $resource2 = $this->createStub(DataResourceInterface::class);
        $resource2->method('pluralLabel')->willReturn('Orders');
        $resource2->method('icon')->willReturn('order-icon');

        $this->registry->method('all')->willReturn([
            'users' => $resource1,
            'orders' => $resource2,
        ]);

        $handler = new DashboardHandler($this->registry, []);
        $result = $handler->execute();

        self::assertCount(2, $result->resources);
        self::assertSame('users', $result->resources[0]['name']);
        self::assertSame('orders', $result->resources[1]['name']);
    }
}
