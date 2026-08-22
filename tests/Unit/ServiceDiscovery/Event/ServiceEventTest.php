<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ServiceDiscovery\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ServiceDiscovery\Event\ServiceDeregistered;
use Pulsar\ServiceDiscovery\Event\ServiceHealthChanged;
use Pulsar\ServiceDiscovery\Event\ServiceRegistered;
use Pulsar\ServiceDiscovery\ServiceHealthStatus;

#[CoversClass(ServiceRegistered::class)]
#[CoversClass(ServiceDeregistered::class)]
#[CoversClass(ServiceHealthChanged::class)]
final class ServiceEventTest extends TestCase
{
    #[Test]
    public function serviceRegisteredHoldsProperties(): void
    {
        $event = new ServiceRegistered(
            serviceName: 'payment-api',
            host: '10.0.1.50',
            port: 8443,
            ttlSeconds: 300,
            occurredAt: 1709827200,
        );

        self::assertSame('payment-api', $event->serviceName);
        self::assertSame('10.0.1.50', $event->host);
        self::assertSame(8443, $event->port);
        self::assertSame(300, $event->ttlSeconds);
        self::assertSame(1709827200, $event->occurredAt);
    }

    #[Test]
    public function serviceRegisteredWithNullTtl(): void
    {
        $event = new ServiceRegistered('api', 'localhost', 80, null, 1709827200);

        self::assertNull($event->ttlSeconds);
    }

    #[Test]
    public function serviceDeregisteredHoldsProperties(): void
    {
        $event = new ServiceDeregistered(
            serviceName: 'auth-service',
            host: '10.0.1.51',
            port: 9090,
            reason: 'TTL expired',
            occurredAt: 1709827500,
        );

        self::assertSame('auth-service', $event->serviceName);
        self::assertSame('10.0.1.51', $event->host);
        self::assertSame(9090, $event->port);
        self::assertSame('TTL expired', $event->reason);
        self::assertSame(1709827500, $event->occurredAt);
    }

    #[Test]
    public function serviceHealthChangedHoldsProperties(): void
    {
        $event = new ServiceHealthChanged(
            serviceName: 'order-service',
            host: '10.0.2.100',
            port: 8080,
            previousStatus: ServiceHealthStatus::Healthy,
            newStatus: ServiceHealthStatus::Degraded,
            occurredAt: 1709828000,
        );

        self::assertSame('order-service', $event->serviceName);
        self::assertSame('10.0.2.100', $event->host);
        self::assertSame(8080, $event->port);
        self::assertSame(ServiceHealthStatus::Healthy, $event->previousStatus);
        self::assertSame(ServiceHealthStatus::Degraded, $event->newStatus);
        self::assertSame(1709828000, $event->occurredAt);
    }
}
