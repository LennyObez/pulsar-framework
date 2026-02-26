<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Monitor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Monitor\HealthStatus;
use Pulsar\Queue\Monitor\MetricsCollector;
use Pulsar\Queue\Monitor\QueueHealthCheck;
use Pulsar\Queue\Monitor\QueueMonitor;
use Pulsar\Queue\QueueDriverInterface;

#[CoversClass(QueueMonitor::class)]
final class QueueMonitorTest extends TestCase
{
    #[Test]
    public function metricsReturnsDelegatedCollector(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $collector = new MetricsCollector($driver);
        $healthCheck = new QueueHealthCheck($driver);

        $monitor = new QueueMonitor($collector, $healthCheck);

        self::assertSame($collector, $monitor->metrics());
    }

    #[Test]
    public function healthCheckDelegatesToHealthChecker(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('size')->willReturn(0);

        $collector = new MetricsCollector($driver);
        $healthCheck = new QueueHealthCheck($driver);

        $monitor = new QueueMonitor($collector, $healthCheck);

        self::assertSame(HealthStatus::Healthy, $monitor->healthCheck('default'));
    }
}
