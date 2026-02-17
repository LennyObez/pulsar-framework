<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AuditFix;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\HealthStatus\Internal\Adapter\CoreHealthCheckRunnerAdapter;
use Pulsar\Resilience\HealthCheck\HealthCheckResult;
use Pulsar\Resilience\HealthCheck\HealthCheckRunnerInterface;
use Pulsar\Resilience\HealthCheck\HealthReport;
use Pulsar\Resilience\HealthCheck\HealthStatus;

/**
 * Verifies that the CoreHealthCheckRunnerAdapter correctly converts
 * a core HealthReport into a HealthSnapshot.
 */
#[CoversClass(CoreHealthCheckRunnerAdapter::class)]
final class CoreHealthCheckRunnerAdapterTest extends TestCase
{
    #[Test]
    public function runConvertsHealthReportToSnapshot(): void
    {
        $coreRunner = $this->createStub(HealthCheckRunnerInterface::class);
        $coreRunner->method('runAll')->willReturn(new HealthReport(
            overallStatus: HealthStatus::Healthy,
            results: [
                HealthCheckResult::healthy('database', 'Connected', 5.2),
                HealthCheckResult::healthy('cache', 'OK', 1.1),
            ],
            generatedAt: new DateTimeImmutable(),
        ));

        $adapter = new CoreHealthCheckRunnerAdapter($coreRunner);
        $snapshot = $adapter->run();

        self::assertSame(HealthStatus::Healthy, $snapshot->overallStatus);
        self::assertCount(2, $snapshot->results);
        self::assertSame('database', $snapshot->results[0]['name']);
        self::assertSame('healthy', $snapshot->results[0]['status']);
        self::assertSame(5.2, $snapshot->results[0]['latency_ms']);
        self::assertSame('cache', $snapshot->results[1]['name']);
        self::assertNotEmpty($snapshot->id);
        self::assertGreaterThan(0, $snapshot->totalDurationMs);
    }

    #[Test]
    public function runHandlesEmptyResultList(): void
    {
        $coreRunner = $this->createStub(HealthCheckRunnerInterface::class);
        $coreRunner->method('runAll')->willReturn(new HealthReport(
            overallStatus: HealthStatus::Healthy,
            results: [],
            generatedAt: new DateTimeImmutable(),
        ));

        $adapter = new CoreHealthCheckRunnerAdapter($coreRunner);
        $snapshot = $adapter->run();

        self::assertSame(HealthStatus::Healthy, $snapshot->overallStatus);
        self::assertCount(0, $snapshot->results);
    }
}
