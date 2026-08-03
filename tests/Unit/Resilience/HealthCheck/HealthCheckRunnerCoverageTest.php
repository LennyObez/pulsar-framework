<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Resilience\HealthCheck;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Resilience\Exception\ResilienceException;
use Pulsar\Resilience\HealthCheck\HealthCheckInterface;
use Pulsar\Resilience\HealthCheck\HealthCheckResult;
use Pulsar\Resilience\HealthCheck\HealthCheckRunner;
use Pulsar\Resilience\HealthCheck\HealthReport;
use Pulsar\Resilience\HealthCheck\HealthStatus;

#[CoversClass(HealthCheckRunner::class)]
#[CoversClass(HealthReport::class)]
final class HealthCheckRunnerCoverageTest extends TestCase
{
    #[Test]
    public function runAllWithNoChecksReturnsHealthy(): void
    {
        $runner = new HealthCheckRunner();

        $report = $runner->runAll();

        self::assertSame(HealthStatus::Healthy, $report->overallStatus);
        self::assertSame([], $report->results);
        self::assertTrue($report->isHealthy());
        self::assertInstanceOf(DateTimeImmutable::class, $report->generatedAt);
    }

    #[Test]
    public function runAllAllHealthyReportsOverallHealthy(): void
    {
        $runner = new HealthCheckRunner();

        $check1 = $this->createHealthCheck('db', HealthCheckResult::healthy('db', 'OK', 1.0));
        $check2 = $this->createHealthCheck('cache', HealthCheckResult::healthy('cache', 'OK', 0.5));

        $runner->register($check1);
        $runner->register($check2);

        $report = $runner->runAll();

        self::assertSame(HealthStatus::Healthy, $report->overallStatus);
        self::assertTrue($report->isHealthy());
        self::assertCount(2, $report->results);
    }

    #[Test]
    public function runAllDegradedReportsDegraded(): void
    {
        $runner = new HealthCheckRunner();

        $check1 = $this->createHealthCheck('db', HealthCheckResult::healthy('db', 'OK', 1.0));
        $check2 = $this->createHealthCheck('cache', HealthCheckResult::degraded('cache', 'Slow', 600.0));

        $runner->register($check1);
        $runner->register($check2);

        $report = $runner->runAll();

        self::assertSame(HealthStatus::Degraded, $report->overallStatus);
        self::assertFalse($report->isHealthy());
    }

    #[Test]
    public function runAllUnhealthyOverridesDegraded(): void
    {
        $runner = new HealthCheckRunner();

        $check1 = $this->createHealthCheck('db', HealthCheckResult::unhealthy('db', 'Down', 0.0));
        $check2 = $this->createHealthCheck('cache', HealthCheckResult::degraded('cache', 'Slow', 600.0));
        $check3 = $this->createHealthCheck('disk', HealthCheckResult::healthy('disk', 'OK', 0.1));

        $runner->register($check1);
        $runner->register($check2);
        $runner->register($check3);

        $report = $runner->runAll();

        self::assertSame(HealthStatus::Unhealthy, $report->overallStatus);
        self::assertFalse($report->isHealthy());
        self::assertCount(3, $report->results);
    }

    #[Test]
    public function runSingleCheckByName(): void
    {
        $runner = new HealthCheckRunner();

        $check = $this->createHealthCheck('db', HealthCheckResult::healthy('db', 'Connected', 2.5));
        $runner->register($check);

        $result = $runner->run('db');

        self::assertSame(HealthStatus::Healthy, $result->status);
        self::assertSame('db', $result->name);
    }

    #[Test]
    public function runUnregisteredCheckThrows(): void
    {
        $runner = new HealthCheckRunner();

        $this->expectException(ResilienceException::class);
        $this->expectExceptionMessageIsOrContains('not registered');

        $runner->run('unknown');
    }

    #[Test]
    public function namesReturnsRegisteredCheckNames(): void
    {
        $runner = new HealthCheckRunner();

        self::assertSame([], $runner->names());

        $runner->register($this->createHealthCheck('db', HealthCheckResult::healthy('db', 'OK', 0.0)));
        $runner->register($this->createHealthCheck('cache', HealthCheckResult::healthy('cache', 'OK', 0.0)));
        $runner->register($this->createHealthCheck('disk', HealthCheckResult::healthy('disk', 'OK', 0.0)));

        self::assertSame(['db', 'cache', 'disk'], $runner->names());
    }

    #[Test]
    public function registerOverwritesSameNameCheck(): void
    {
        $runner = new HealthCheckRunner();

        $check1 = $this->createHealthCheck('db', HealthCheckResult::healthy('db', 'v1', 1.0));
        $check2 = $this->createHealthCheck('db', HealthCheckResult::unhealthy('db', 'v2', 0.0));

        $runner->register($check1);
        $runner->register($check2);

        self::assertCount(1, $runner->names());

        $result = $runner->run('db');
        self::assertSame(HealthStatus::Unhealthy, $result->status);
    }

    #[Test]
    public function healthReportIsHealthyOnlyWhenOverallHealthy(): void
    {
        $report1 = new HealthReport(
            overallStatus: HealthStatus::Healthy,
            results: [],
            generatedAt: new DateTimeImmutable(),
        );
        self::assertTrue($report1->isHealthy());

        $report2 = new HealthReport(
            overallStatus: HealthStatus::Degraded,
            results: [],
            generatedAt: new DateTimeImmutable(),
        );
        self::assertFalse($report2->isHealthy());

        $report3 = new HealthReport(
            overallStatus: HealthStatus::Unhealthy,
            results: [],
            generatedAt: new DateTimeImmutable(),
        );
        self::assertFalse($report3->isHealthy());
    }

    /**
     * @return HealthCheckInterface&Stub
     */
    private function createHealthCheck(string $name, HealthCheckResult $result): HealthCheckInterface
    {
        $check = $this->createStub(HealthCheckInterface::class);
        $check->method('getName')->willReturn($name);
        $check->method('check')->willReturn($result);

        return $check;
    }
}
