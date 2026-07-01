<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Posture;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Resilience\HealthCheck\HealthStatus;
use Pulsar\Security\Posture\SecurityPostureHealthCheck;
use Pulsar\Security\Posture\SecurityPostureItem;
use Pulsar\Security\Posture\SecurityPostureReport;

#[CoversClass(SecurityPostureHealthCheck::class)]
final class SecurityPostureHealthCheckTest extends TestCase
{
    #[Test]
    public function reportsHealthyWhenAllControlsOk(): void
    {
        $check = new SecurityPostureHealthCheck(new SecurityPostureReport([
            SecurityPostureItem::ok('csrf_protection', 'enabled'),
        ]));

        self::assertSame('security_posture', $check->getName());
        self::assertSame(HealthStatus::Healthy, $check->check()->status);
    }

    #[Test]
    public function reportsDegradedWhenAControlIsDegraded(): void
    {
        $check = new SecurityPostureHealthCheck(new SecurityPostureReport([
            SecurityPostureItem::degraded('hsts', 'max-age low', 'raise it'),
        ]));

        self::assertSame(HealthStatus::Degraded, $check->check()->status);
    }

    #[Test]
    public function reportsUnhealthyWhenAControlFails(): void
    {
        $check = new SecurityPostureHealthCheck(new SecurityPostureReport([
            SecurityPostureItem::fail('master_key', 'missing', 'set it'),
            SecurityPostureItem::degraded('hsts', 'low', 'raise it'),
        ]));

        $result = $check->check();

        self::assertSame(HealthStatus::Unhealthy, $result->status);
        self::assertStringContainsString('failing', $result->message);
    }
}
