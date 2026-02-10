<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Monitor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Monitor\HealthStatus;
use Pulsar\Queue\Monitor\QueueHealthCheck;
use Pulsar\Queue\QueueDriverInterface;
use RuntimeException;

#[CoversClass(QueueHealthCheck::class)]
final class QueueHealthCheckTest extends TestCase
{
    #[Test]
    public function returnsHealthyWhenBelowThreshold(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('size')->willReturn(50);

        $check = new QueueHealthCheck($driver, pendingThreshold: 10000);

        self::assertSame(HealthStatus::Healthy, $check->check('default'));
    }

    #[Test]
    public function returnsDegradedWhenAtThreshold(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('size')->willReturn(10000);

        $check = new QueueHealthCheck($driver, pendingThreshold: 10000);

        self::assertSame(HealthStatus::Degraded, $check->check('default'));
    }

    #[Test]
    public function returnsDegradedWhenAboveThreshold(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('size')->willReturn(15000);

        $check = new QueueHealthCheck($driver, pendingThreshold: 10000);

        self::assertSame(HealthStatus::Degraded, $check->check('default'));
    }

    #[Test]
    public function returnsUnhealthyWhenDriverThrows(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('size')->willThrowException(new RuntimeException('connection lost'));

        $check = new QueueHealthCheck($driver);

        self::assertSame(HealthStatus::Unhealthy, $check->check('default'));
    }

    #[Test]
    public function customThresholdIsRespected(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('size')->willReturn(100);

        $check = new QueueHealthCheck($driver, pendingThreshold: 50);

        self::assertSame(HealthStatus::Degraded, $check->check('default'));
    }
}
