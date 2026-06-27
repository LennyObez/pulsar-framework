<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ServiceDiscovery;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ServiceDiscovery\ServiceHealthStatus;
use Pulsar\ServiceDiscovery\ServiceInstance;
use Pulsar\ServiceDiscovery\ServiceTtlEntry;

#[CoversClass(ServiceTtlEntry::class)]
final class ServiceTtlEntryTest extends TestCase
{
    #[Test]
    public function healthyInstanceSetsHealthyStatus(): void
    {
        $instance = new ServiceInstance('api', 'localhost', 8080, healthy: true);
        $entry = new ServiceTtlEntry($instance, 60, 1000, 1000);

        self::assertSame(ServiceHealthStatus::Healthy, $entry->healthStatus);
    }

    #[Test]
    public function unhealthyInstanceSetsUnhealthyStatus(): void
    {
        $instance = new ServiceInstance('api', 'localhost', 8080, healthy: false);
        $entry = new ServiceTtlEntry($instance, 60, 1000, 1000);

        self::assertSame(ServiceHealthStatus::Unhealthy, $entry->healthStatus);
    }

    #[Test]
    public function isExpiredReturnsTrueWhenPastTtl(): void
    {
        $instance = new ServiceInstance('api', 'localhost', 8080);
        $entry = new ServiceTtlEntry($instance, 60, 1000, 1000);

        self::assertTrue($entry->isExpired(1061));
    }

    #[Test]
    public function isExpiredReturnsFalseWithinTtl(): void
    {
        $instance = new ServiceInstance('api', 'localhost', 8080);
        $entry = new ServiceTtlEntry($instance, 60, 1000, 1000);

        self::assertFalse($entry->isExpired(1059));
    }

    #[Test]
    public function isExpiredReturnsFalseWhenTtlIsNull(): void
    {
        $instance = new ServiceInstance('api', 'localhost', 8080);
        $entry = new ServiceTtlEntry($instance, null, 1000, 1000);

        self::assertFalse($entry->isExpired(999999));
    }

    #[Test]
    public function refreshHeartbeatUpdatesTimestamp(): void
    {
        $instance = new ServiceInstance('api', 'localhost', 8080);
        $entry = new ServiceTtlEntry($instance, 60, 1000, 1000);

        $entry->refreshHeartbeat(2000);

        self::assertSame(2000, $entry->lastHeartbeat());
        self::assertFalse($entry->isExpired(2059));
    }

    #[Test]
    public function keyReturnsHostAndPort(): void
    {
        $instance = new ServiceInstance('api', '192.168.1.100', 9090);
        $entry = new ServiceTtlEntry($instance, 60, 1000, 1000);

        self::assertSame('192.168.1.100:9090', $entry->key());
    }
}
