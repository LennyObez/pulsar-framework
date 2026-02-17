<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Tests\Unit\Scheduler;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\HealthStatus\Contracts\HealthHistoryStoreInterface;
use Pulsar\Extension\HealthStatus\Contracts\IncidentDetectorInterface;
use Pulsar\Extension\HealthStatus\Domain\HealthSnapshot;
use Pulsar\Extension\HealthStatus\Domain\Incident;
use Pulsar\Extension\HealthStatus\Domain\IncidentSeverity;
use Pulsar\Extension\HealthStatus\Domain\IncidentStatus;
use Pulsar\Extension\HealthStatus\Scheduler\HealthCheckSnapshotJob;
use Pulsar\Resilience\HealthCheck\HealthCheckResult;
use Pulsar\Resilience\HealthCheck\HealthCheckRunnerInterface;
use Pulsar\Resilience\HealthCheck\HealthReport;
use Pulsar\Resilience\HealthCheck\HealthStatus;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobStatus;
use Pulsar\Scheduler\Schedule;
use RuntimeException;

#[CoversClass(HealthCheckSnapshotJob::class)]
final class HealthCheckSnapshotJobTest extends TestCase
{
    #[Test]
    public function getNameReturnsExpectedValue(): void
    {
        $job = $this->createJob();

        self::assertSame('health-status:snapshot', $job->getName());
    }

    #[Test]
    public function getScheduleReturnsEveryMinute(): void
    {
        $job = $this->createJob();
        $schedule = $job->getSchedule();

        self::assertInstanceOf(Schedule::class, $schedule);
        self::assertSame('* * * * *', $schedule->expression);
    }

    #[Test]
    public function getDescriptionReturnsNonEmptyString(): void
    {
        $job = $this->createJob();

        self::assertNotEmpty($job->getDescription());
    }

    #[Test]
    public function executeStoresSnapshotAndRunsDetection(): void
    {
        $report = new HealthReport(
            overallStatus: HealthStatus::Healthy,
            results: [
                HealthCheckResult::healthy('database', 'OK', 1.5),
            ],
            generatedAt: new DateTimeImmutable(),
        );

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('runAll')->willReturn($report);

        $storedSnapshot = null;
        $store = $this->createStub(HealthHistoryStoreInterface::class);
        $store->method('storeSnapshot')->willReturnCallback(
            static function (HealthSnapshot $s) use (&$storedSnapshot): void {
                $storedSnapshot = $s;
            },
        );

        $detectorCalled = false;
        $detector = $this->createStub(IncidentDetectorInterface::class);
        $detector->method('detect')->willReturnCallback(
            static function () use (&$detectorCalled): array {
                $detectorCalled = true;

                return [];
            },
        );

        $job = new HealthCheckSnapshotJob($runner, $store, $detector);
        $context = new JobContext(
            scheduledAt: new DateTimeImmutable(),
            startedAt: new DateTimeImmutable(),
        );

        $result = $job->execute($context);

        self::assertSame(JobStatus::Success, $result->status);
        self::assertNotNull($storedSnapshot);
        self::assertSame(HealthStatus::Healthy, $storedSnapshot->overallStatus);
        self::assertTrue($detectorCalled);
    }

    #[Test]
    public function executeStoresDetectedIncidents(): void
    {
        $report = new HealthReport(
            overallStatus: HealthStatus::Unhealthy,
            results: [
                HealthCheckResult::unhealthy('database', 'Connection refused', 0.0),
            ],
            generatedAt: new DateTimeImmutable(),
        );

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('runAll')->willReturn($report);

        /** @var list<Incident> $storedIncidents */
        $storedIncidents = [];
        $store = $this->createStub(HealthHistoryStoreInterface::class);
        $store->method('storeIncident')->willReturnCallback(
            static function (Incident $i) use (&$storedIncidents): void {
                $storedIncidents[] = $i;
            },
        );

        $newIncident = new Incident(
            id: 'inc-new',
            checkName: 'database',
            severity: IncidentSeverity::Major,
            status: IncidentStatus::Open,
            message: 'Connection refused',
            startedAt: new DateTimeImmutable(),
        );

        $detector = $this->createStub(IncidentDetectorInterface::class);
        $detector->method('detect')->willReturn([$newIncident]);

        $job = new HealthCheckSnapshotJob($runner, $store, $detector);
        $context = new JobContext(
            scheduledAt: new DateTimeImmutable(),
            startedAt: new DateTimeImmutable(),
        );

        $result = $job->execute($context);

        self::assertSame(JobStatus::Success, $result->status);
        self::assertCount(1, $storedIncidents);
        self::assertSame('database', $storedIncidents[0]->checkName);
    }

    #[Test]
    public function executeUpdatesResolvedIncidents(): void
    {
        $report = new HealthReport(
            overallStatus: HealthStatus::Healthy,
            results: [
                HealthCheckResult::healthy('database', 'OK', 1.0),
            ],
            generatedAt: new DateTimeImmutable(),
        );

        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('runAll')->willReturn($report);

        /** @var list<Incident> $updatedIncidents */
        $updatedIncidents = [];
        $store = $this->createStub(HealthHistoryStoreInterface::class);
        $store->method('updateIncident')->willReturnCallback(
            static function (Incident $i) use (&$updatedIncidents): void {
                $updatedIncidents[] = $i;
            },
        );

        $resolvedIncident = new Incident(
            id: 'inc-resolved',
            checkName: 'database',
            severity: IncidentSeverity::Major,
            status: IncidentStatus::Resolved,
            message: 'Connection refused',
            startedAt: new DateTimeImmutable('2026-03-27T10:00:00Z'),
            resolvedAt: new DateTimeImmutable('2026-03-27T10:10:00Z'),
        );

        $detector = $this->createStub(IncidentDetectorInterface::class);
        $detector->method('detect')->willReturn([$resolvedIncident]);

        $job = new HealthCheckSnapshotJob($runner, $store, $detector);
        $context = new JobContext(
            scheduledAt: new DateTimeImmutable(),
            startedAt: new DateTimeImmutable(),
        );

        $result = $job->execute($context);

        self::assertSame(JobStatus::Success, $result->status);
        self::assertCount(1, $updatedIncidents);
        self::assertSame(IncidentStatus::Resolved, $updatedIncidents[0]->status);
    }

    #[Test]
    public function executeReturnsFailureOnException(): void
    {
        $runner = $this->createStub(HealthCheckRunnerInterface::class);
        $runner->method('runAll')->willThrowException(new RuntimeException('Runner failed'));

        $store = $this->createStub(HealthHistoryStoreInterface::class);
        $detector = $this->createStub(IncidentDetectorInterface::class);

        $job = new HealthCheckSnapshotJob($runner, $store, $detector);
        $context = new JobContext(
            scheduledAt: new DateTimeImmutable(),
            startedAt: new DateTimeImmutable(),
        );

        $result = $job->execute($context);

        self::assertSame(JobStatus::Failure, $result->status);
        self::assertNotNull($result->exception);
    }

    private function createJob(): HealthCheckSnapshotJob
    {
        return new HealthCheckSnapshotJob(
            $this->createStub(HealthCheckRunnerInterface::class),
            $this->createStub(HealthHistoryStoreInterface::class),
            $this->createStub(IncidentDetectorInterface::class),
        );
    }
}
