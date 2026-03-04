<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\Dashboard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Features\Dashboard\DashboardResult;

#[CoversClass(DashboardResult::class)]
final class DashboardResultTest extends TestCase
{
    #[Test]
    public function constructor_sets_widgets_and_resources(): void
    {
        $widgets = [
            ['id' => 'count', 'label' => 'Total', 'size' => 'small', 'data' => ['count' => 42]],
        ];
        $resources = [
            ['name' => 'users', 'label' => 'Users', 'icon' => 'users'],
        ];

        $result = new DashboardResult(widgets: $widgets, resources: $resources);

        self::assertCount(1, $result->widgets);
        self::assertSame('count', $result->widgets[0]['id']);
        self::assertCount(1, $result->resources);
        self::assertSame('users', $result->resources[0]['name']);
    }

    #[Test]
    public function empty_widgets_and_resources(): void
    {
        $result = new DashboardResult(widgets: [], resources: []);

        self::assertSame([], $result->widgets);
        self::assertSame([], $result->resources);
    }
}
