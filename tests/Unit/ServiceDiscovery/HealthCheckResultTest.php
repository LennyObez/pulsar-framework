<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ServiceDiscovery;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ServiceDiscovery\HealthCheckResult;
use Pulsar\ServiceDiscovery\ServiceHealthStatus;

#[CoversClass(HealthCheckResult::class)]
final class HealthCheckResultTest extends TestCase
{
    #[Test]
    public function healthyFactory(): void
    {
        $result = HealthCheckResult::healthy(5.2);

        self::assertSame(ServiceHealthStatus::Healthy, $result->status);
        self::assertSame(5.2, $result->latencyMs);
        self::assertNull($result->message);
        self::assertNotNull($result->checkedAt);
    }

    #[Test]
    public function unhealthyFactory(): void
    {
        $result = HealthCheckResult::unhealthy('Connection refused');

        self::assertSame(ServiceHealthStatus::Unhealthy, $result->status);
        self::assertSame('Connection refused', $result->message);
        self::assertNull($result->latencyMs);
    }

    #[Test]
    public function unhealthyFactoryRetainsLatency(): void
    {
        // A connection failure still has a measured latency (e.g. the timeout
        // ceiling). The factory must preserve it for diagnostics.
        $result = HealthCheckResult::unhealthy('Connection failed', 5000.0);

        self::assertSame(ServiceHealthStatus::Unhealthy, $result->status);
        self::assertSame('Connection failed', $result->message);
        self::assertSame(5000.0, $result->latencyMs);
    }

    #[Test]
    public function degradedFactory(): void
    {
        $result = HealthCheckResult::degraded('High latency', 500.0);

        self::assertSame(ServiceHealthStatus::Degraded, $result->status);
        self::assertSame('High latency', $result->message);
        self::assertSame(500.0, $result->latencyMs);
    }
}
