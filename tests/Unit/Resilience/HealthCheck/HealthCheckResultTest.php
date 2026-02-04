<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Resilience\HealthCheck;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Resilience\HealthCheck\HealthCheckResult;
use Pulsar\Resilience\HealthCheck\HealthStatus;

#[CoversClass(HealthCheckResult::class)]
final class HealthCheckResultTest extends TestCase
{
    #[Test]
    public function healthyFactoryCreatesHealthyResult(): void
    {
        $result = HealthCheckResult::healthy('database', 'Connection OK', 1.5);

        self::assertSame('database', $result->name);
        self::assertSame(HealthStatus::Healthy, $result->status);
        self::assertSame('Connection OK', $result->message);
        self::assertSame(1.5, $result->responseTimeMs);
        self::assertInstanceOf(DateTimeImmutable::class, $result->checkedAt);
    }

    #[Test]
    public function degradedFactoryCreatesDegradedResult(): void
    {
        $result = HealthCheckResult::degraded('cache', 'Slow response', 250.0);

        self::assertSame('cache', $result->name);
        self::assertSame(HealthStatus::Degraded, $result->status);
        self::assertSame('Slow response', $result->message);
        self::assertSame(250.0, $result->responseTimeMs);
        self::assertInstanceOf(DateTimeImmutable::class, $result->checkedAt);
    }

    #[Test]
    public function unhealthyFactoryCreatesUnhealthyResult(): void
    {
        $result = HealthCheckResult::unhealthy('queue', 'Connection refused', 0.0);

        self::assertSame('queue', $result->name);
        self::assertSame(HealthStatus::Unhealthy, $result->status);
        self::assertSame('Connection refused', $result->message);
        self::assertSame(0.0, $result->responseTimeMs);
        self::assertInstanceOf(DateTimeImmutable::class, $result->checkedAt);
    }

    #[Test]
    public function fieldAccessReturnsConstructorValues(): void
    {
        $checkedAt = new DateTimeImmutable('2025-01-01T00:00:00+00:00');

        $result = new HealthCheckResult(
            name: 'disk',
            status: HealthStatus::Degraded,
            message: 'Low space',
            responseTimeMs: 42.7,
            checkedAt: $checkedAt,
        );

        self::assertSame('disk', $result->name);
        self::assertSame(HealthStatus::Degraded, $result->status);
        self::assertSame('Low space', $result->message);
        self::assertSame(42.7, $result->responseTimeMs);
        self::assertSame($checkedAt, $result->checkedAt);
    }
}
