<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Resilience\HealthCheck;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Resilience\HealthCheck\HealthReport;
use Pulsar\Resilience\HealthCheck\HealthStatus;

#[CoversClass(HealthReport::class)]
final class HealthReportTest extends TestCase
{
    #[Test]
    public function isHealthyReturnsTrueWhenOverallIsHealthy(): void
    {
        $report = new HealthReport(
            overallStatus: HealthStatus::Healthy,
            results: [],
            generatedAt: new DateTimeImmutable(),
        );

        self::assertTrue($report->isHealthy());
    }

    #[Test]
    public function isHealthyReturnsFalseWhenOverallIsDegraded(): void
    {
        $report = new HealthReport(
            overallStatus: HealthStatus::Degraded,
            results: [],
            generatedAt: new DateTimeImmutable(),
        );

        self::assertFalse($report->isHealthy());
    }

    #[Test]
    public function isHealthyReturnsFalseWhenOverallIsUnhealthy(): void
    {
        $report = new HealthReport(
            overallStatus: HealthStatus::Unhealthy,
            results: [],
            generatedAt: new DateTimeImmutable(),
        );

        self::assertFalse($report->isHealthy());
    }
}
