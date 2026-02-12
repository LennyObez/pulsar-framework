<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Health\ConnectionHealthCheckerInterface;
use Pulsar\Database\Health\HeartbeatMonitor;

#[CoversClass(HeartbeatMonitor::class)]
final class HeartbeatMonitorTest extends TestCase
{
    #[Test]
    public function registersConnections(): void
    {
        $healthChecker = $this->createMock(ConnectionHealthCheckerInterface::class);
        $monitor = new HeartbeatMonitor($healthChecker);

        $connection = $this->createMock(ConnectionInterface::class);
        $monitor->register('primary', $connection);

        self::assertTrue($monitor->isRegistered('primary'));
        self::assertFalse($monitor->isRegistered('replica'));
    }

    #[Test]
    public function reportsUnhealthyConnections(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);

        $healthChecker = $this->createMock(ConnectionHealthCheckerInterface::class);
        $healthChecker->method('isHealthy')
            ->with($connection)
            ->willReturn(false);

        $monitor = new HeartbeatMonitor($healthChecker, maxConsecutiveFailures: 5);
        $monitor->register('primary', $connection);

        $statusChanges = [];
        $results = $monitor->checkAll(function (string $name, bool $healthy) use (&$statusChanges): void {
            $statusChanges[] = ['name' => $name, 'healthy' => $healthy];
        });

        self::assertFalse($results['primary']);
        self::assertCount(1, $statusChanges);
        self::assertSame('primary', $statusChanges[0]['name']);
        self::assertFalse($statusChanges[0]['healthy']);
    }

    #[Test]
    public function removesDeadConnections(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);

        $healthChecker = $this->createMock(ConnectionHealthCheckerInterface::class);
        $healthChecker->method('isHealthy')
            ->with($connection)
            ->willReturn(false);

        $monitor = new HeartbeatMonitor($healthChecker, maxConsecutiveFailures: 2);
        $monitor->register('dead-conn', $connection);

        // First check: failure 1
        $monitor->checkAll();
        self::assertTrue($monitor->isRegistered('dead-conn'));

        // Second check: failure 2 — exceeds threshold, connection removed
        $monitor->checkAll();
        self::assertFalse($monitor->isRegistered('dead-conn'));
    }

    #[Test]
    public function healthyCheckResetsFailureCount(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);

        $healthChecker = $this->createMock(ConnectionHealthCheckerInterface::class);
        $healthChecker->method('isHealthy')
            ->willReturnOnConsecutiveCalls(false, true, false, false);

        $monitor = new HeartbeatMonitor($healthChecker, maxConsecutiveFailures: 2);
        $monitor->register('conn', $connection);

        // Failure 1
        $monitor->checkAll();
        self::assertTrue($monitor->isRegistered('conn'));

        // Success — resets failure count
        $monitor->checkAll();
        self::assertTrue($monitor->isRegistered('conn'));

        // Failure 1 (reset)
        $monitor->checkAll();
        self::assertTrue($monitor->isRegistered('conn'));

        // Failure 2 — now removed
        $monitor->checkAll();
        self::assertFalse($monitor->isRegistered('conn'));
    }

    #[Test]
    public function statusReturnsLastKnownState(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);

        $healthChecker = $this->createMock(ConnectionHealthCheckerInterface::class);
        $healthChecker->method('isHealthy')->willReturn(true);

        $monitor = new HeartbeatMonitor($healthChecker);
        $monitor->register('primary', $connection);

        $status = $monitor->status();
        self::assertTrue($status['primary']);
    }

    #[Test]
    public function unregisterRemovesConnection(): void
    {
        $healthChecker = $this->createMock(ConnectionHealthCheckerInterface::class);
        $monitor = new HeartbeatMonitor($healthChecker);

        $connection = $this->createMock(ConnectionInterface::class);
        $monitor->register('primary', $connection);
        self::assertTrue($monitor->isRegistered('primary'));

        $monitor->unregister('primary');
        self::assertFalse($monitor->isRegistered('primary'));
    }
}
