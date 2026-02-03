<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Supervisor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Supervisor\StuckJobDetector;
use Pulsar\Supervisor\StuckJobPolicy;

use function time;

#[CoversClass(StuckJobDetector::class)]
final class StuckJobDetectorTest extends TestCase
{
    #[Test]
    public function it_detects_jobs_exceeding_timeout(): void
    {
        $now = time();
        $stuckJob = new JobRecord(
            id: 'job-1',
            queue: 'default',
            jobClass: 'App\\Jobs\\SlowJob',
            payload: '{}',
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: $now - 600,
            availableAt: $now - 600,
        );

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('findByStatus')
            ->with(JobRecordStatus::Processing)
            ->willReturn([$stuckJob]);

        $policy = new StuckJobPolicy(
            timeoutSeconds: 300,
            checkIntervalSeconds: 60,
            moveToDeadLetter: true,
        );

        $detector = new StuckJobDetector($policy, $driver);
        $result = $detector->detect();

        self::assertCount(1, $result);
        self::assertSame('job-1', $result[0]->id);
    }

    #[Test]
    public function it_ignores_jobs_within_timeout(): void
    {
        $now = time();
        $recentJob = new JobRecord(
            id: 'job-2',
            queue: 'default',
            jobClass: 'App\\Jobs\\QuickJob',
            payload: '{}',
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: $now - 10,
            availableAt: $now - 10,
        );

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('findByStatus')
            ->with(JobRecordStatus::Processing)
            ->willReturn([$recentJob]);

        $policy = new StuckJobPolicy(
            timeoutSeconds: 300,
            checkIntervalSeconds: 60,
            moveToDeadLetter: true,
        );

        $detector = new StuckJobDetector($policy, $driver);
        $result = $detector->detect();

        self::assertSame([], $result);
    }

    #[Test]
    public function it_returns_empty_array_when_no_processing_jobs(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('findByStatus')
            ->with(JobRecordStatus::Processing)
            ->willReturn([]);

        $policy = new StuckJobPolicy(
            timeoutSeconds: 300,
            checkIntervalSeconds: 60,
            moveToDeadLetter: true,
        );

        $detector = new StuckJobDetector($policy, $driver);
        $result = $detector->detect();

        self::assertSame([], $result);
    }

    #[Test]
    public function it_filters_mix_of_stuck_and_healthy_jobs(): void
    {
        $now = time();

        $stuckJob = new JobRecord(
            id: 'job-stuck',
            queue: 'default',
            jobClass: 'App\\Jobs\\SlowJob',
            payload: '{}',
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: $now - 600,
            availableAt: $now - 600,
        );

        $healthyJob = new JobRecord(
            id: 'job-healthy',
            queue: 'default',
            jobClass: 'App\\Jobs\\FastJob',
            payload: '{}',
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: $now - 10,
            availableAt: $now - 10,
        );

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('findByStatus')
            ->with(JobRecordStatus::Processing)
            ->willReturn([$stuckJob, $healthyJob]);

        $policy = new StuckJobPolicy(
            timeoutSeconds: 300,
            checkIntervalSeconds: 60,
            moveToDeadLetter: true,
        );

        $detector = new StuckJobDetector($policy, $driver);
        $result = $detector->detect();

        self::assertCount(1, $result);
        self::assertSame('job-stuck', $result[0]->id);
    }

    #[Test]
    public function it_includes_job_exactly_at_timeout_boundary(): void
    {
        $now = time();
        $boundaryJob = new JobRecord(
            id: 'job-boundary',
            queue: 'default',
            jobClass: 'App\\Jobs\\BoundaryJob',
            payload: '{}',
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: $now - 300,
            availableAt: $now - 300,
        );

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('findByStatus')
            ->with(JobRecordStatus::Processing)
            ->willReturn([$boundaryJob]);

        $policy = new StuckJobPolicy(
            timeoutSeconds: 300,
            checkIntervalSeconds: 60,
            moveToDeadLetter: true,
        );

        $detector = new StuckJobDetector($policy, $driver);
        $result = $detector->detect();

        self::assertCount(1, $result);
        self::assertSame('job-boundary', $result[0]->id);
    }

    #[Test]
    public function it_detects_multiple_stuck_jobs(): void
    {
        $now = time();
        $jobs = [];

        for ($i = 1; $i <= 5; $i++) {
            $jobs[] = new JobRecord(
                id: "stuck-{$i}",
                queue: 'default',
                jobClass: 'App\\Jobs\\StuckJob',
                payload: '{}',
                attempts: $i,
                status: JobRecordStatus::Processing,
                createdAt: $now - 600,
                availableAt: $now - 600,
            );
        }

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('findByStatus')
            ->with(JobRecordStatus::Processing)
            ->willReturn($jobs);

        $policy = new StuckJobPolicy(
            timeoutSeconds: 300,
            checkIntervalSeconds: 60,
            moveToDeadLetter: true,
        );

        $detector = new StuckJobDetector($policy, $driver);
        $result = $detector->detect();

        self::assertCount(5, $result);
    }
}
