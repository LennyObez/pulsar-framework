<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Aggregation\DashboardAggregator;
use Pulsar\Extension\Studio\Console\Storage\SqliteEventStore;
use Pulsar\Extension\Studio\Server\Controller\BenchmarkController;

#[CoversClass(BenchmarkController::class)]
final class BenchmarkDashboardControllerTest extends TestCase
{
    #[Test]
    public function classIsInstantiable(): void
    {
        $store = new SqliteEventStore(':memory:');
        $aggregator = new DashboardAggregator($store);
        $controller = new BenchmarkController($aggregator);

        self::assertInstanceOf(BenchmarkController::class, $controller);
    }
}
