<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Dashboard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Dashboard\DashboardService;
use Pulsar\Extension\Cms\Dashboard\DashboardWidgetInterface;

#[CoversClass(DashboardService::class)]
final class DashboardServiceTest extends TestCase
{
    #[Test]
    public function collectWidgetDataReturnsEmptyForNoWidgets(): void
    {
        $service = new DashboardService([]);

        self::assertSame([], $service->collectWidgetData());
    }

    #[Test]
    public function collectWidgetDataAggregatesAllWidgets(): void
    {
        $widget1 = $this->createWidgetMock('widget_a', ['value' => 1], 'template/a');
        $widget2 = $this->createWidgetMock('widget_b', ['value' => 2], 'template/b');

        $service = new DashboardService([$widget1, $widget2]);
        $result = $service->collectWidgetData();

        self::assertCount(2, $result);
        self::assertArrayHasKey('widget_a', $result);
        self::assertArrayHasKey('widget_b', $result);
        self::assertSame(['value' => 1], $result['widget_a']['data']);
        self::assertSame('template/a', $result['widget_a']['template']);
        self::assertSame(['value' => 2], $result['widget_b']['data']);
        self::assertSame('template/b', $result['widget_b']['template']);
    }

    #[Test]
    public function getWidgetDataReturnsDataForExistingWidget(): void
    {
        $widget = $this->createWidgetMock('my_widget', ['key' => 'val'], 'tpl/mine');

        $service = new DashboardService([$widget]);
        $result = $service->getWidgetData('my_widget');

        self::assertNotNull($result);
        self::assertSame(['key' => 'val'], $result['data']);
        self::assertSame('tpl/mine', $result['template']);
    }

    #[Test]
    public function getWidgetDataReturnsNullForUnknownWidget(): void
    {
        $service = new DashboardService([]);

        self::assertNull($service->getWidgetData('nonexistent'));
    }

    #[Test]
    public function getWidgetNamesReturnsAllRegisteredNames(): void
    {
        $widget1 = $this->createWidgetMock('alpha', [], '');
        $widget2 = $this->createWidgetMock('beta', [], '');
        $widget3 = $this->createWidgetMock('gamma', [], '');

        $service = new DashboardService([$widget1, $widget2, $widget3]);

        self::assertSame(['alpha', 'beta', 'gamma'], $service->getWidgetNames());
    }

    #[Test]
    public function getWidgetNamesReturnsEmptyForNoWidgets(): void
    {
        $service = new DashboardService([]);

        self::assertSame([], $service->getWidgetNames());
    }

    #[Test]
    public function collectWidgetDataCallsGetDataOnEachWidget(): void
    {
        $widget = $this->createMock(DashboardWidgetInterface::class);
        $widget->method('getName')->willReturn('test');
        $widget->method('getTemplate')->willReturn('tpl');
        $widget->expects(self::once())->method('getData')->willReturn(['called' => true]);

        $service = new DashboardService([$widget]);
        $service->collectWidgetData();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createWidgetMock(string $name, array $data, string $template): DashboardWidgetInterface
    {
        $widget = $this->createStub(DashboardWidgetInterface::class);
        $widget->method('getName')->willReturn($name);
        $widget->method('getData')->willReturn($data);
        $widget->method('getTemplate')->willReturn($template);

        return $widget;
    }
}
