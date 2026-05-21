<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\Dashboard;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Contracts\WidgetInterface;
use Pulsar\Extension\Admin\Features\Dashboard\DashboardHandler;
use Pulsar\Extension\Admin\Features\Dashboard\DashboardResult;

final class DashboardHandlerTest extends TestCase
{
    #[Test]
    public function execute_returns_widgets_and_resources(): void
    {
        $widget = $this->createStub(WidgetInterface::class);
        $widget->method('id')->willReturn('test_widget');
        $widget->method('label')->willReturn('Test Widget');
        $widget->method('size')->willReturn('small');
        $widget->method('render')->willReturn(['count' => 42]);

        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('pluralLabel')->willReturn('Users');
        $resource->method('icon')->willReturn('user');

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('all')->willReturn(['users' => $resource]);

        $handler = new DashboardHandler($registry, [$widget]);
        $result = $handler->execute();

        self::assertInstanceOf(DashboardResult::class, $result);
        self::assertCount(1, $result->widgets);
        self::assertSame('test_widget', $result->widgets[0]['id']);
        self::assertSame('Test Widget', $result->widgets[0]['label']);
        self::assertSame('small', $result->widgets[0]['size']);
        self::assertSame(['count' => 42], $result->widgets[0]['data']);

        self::assertCount(1, $result->resources);
        self::assertSame('users', $result->resources[0]['name']);
        self::assertSame('Users', $result->resources[0]['label']);
        self::assertSame('user', $result->resources[0]['icon']);
    }

    #[Test]
    public function execute_with_no_widgets_and_no_resources(): void
    {
        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('all')->willReturn([]);

        $handler = new DashboardHandler($registry, []);
        $result = $handler->execute();

        self::assertSame([], $result->widgets);
        self::assertSame([], $result->resources);
    }

    #[Test]
    public function execute_with_multiple_widgets(): void
    {
        $widget1 = $this->createWidgetStub('w1', 'Widget 1', 'medium', ['a' => 1]);
        $widget2 = $this->createWidgetStub('w2', 'Widget 2', 'large', ['b' => 2]);

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('all')->willReturn([]);

        $handler = new DashboardHandler($registry, [$widget1, $widget2]);
        $result = $handler->execute();

        self::assertCount(2, $result->widgets);
        self::assertSame('w1', $result->widgets[0]['id']);
        self::assertSame('w2', $result->widgets[1]['id']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createWidgetStub(string $id, string $label, string $size, array $data): WidgetInterface&Stub
    {
        $widget = $this->createStub(WidgetInterface::class);
        $widget->method('id')->willReturn($id);
        $widget->method('label')->willReturn($label);
        $widget->method('size')->willReturn($size);
        $widget->method('render')->willReturn($data);

        return $widget;
    }
}
