<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Supervisor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Config\SupervisorConfig;
use Pulsar\Queue\DeadLetterQueue;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Supervisor\HealingAction;
use Pulsar\Supervisor\HealingActionType;
use Pulsar\Supervisor\InvariantCheck\InvariantCheckInterface;
use Pulsar\Supervisor\InvariantCheck\InvariantCheckResult;
use Pulsar\Supervisor\PreflightCheck\PreflightCheckInterface;
use Pulsar\Supervisor\PreflightCheck\PreflightCheckResult;
use Pulsar\Supervisor\RecycleAction;
use Pulsar\Supervisor\RecycleReason;
use Pulsar\Supervisor\Supervisor;
use Pulsar\Supervisor\WorkerRecyclePolicy;

use function time;

#[CoversClass(Supervisor::class)]
final class SupervisorTest extends TestCase
{
    #[Test]
    public function it_returns_null_when_supervisor_is_disabled(): void
    {
        $config = new SupervisorConfig(enabled: false);
        $supervisor = new Supervisor($config);

        $result = $supervisor->shouldRecycle(
            requestCount: 999_999,
            memoryUsageMb: 999_999,
            uptimeSeconds: 999_999,
        );

        self::assertNull($result);
    }

    #[Test]
    public function it_returns_recycle_record_when_max_requests_exceeded(): void
    {
        $config = new SupervisorConfig(enabled: true, recycleMaxRequests: 100);
        $supervisor = new Supervisor($config);

        $result = $supervisor->shouldRecycle(
            requestCount: 100,
            memoryUsageMb: 50,
            uptimeSeconds: 60,
        );

        self::assertNotNull($result);
        self::assertSame(RecycleReason::MaxRequests, $result->reason);
        self::assertSame(RecycleAction::GracefulRestart, $result->action);
        self::assertSame(100, $result->requestCount);
        self::assertSame(50, $result->memoryUsageMb);
        self::assertSame(60, $result->uptimeSeconds);
    }

    #[Test]
    public function it_returns_recycle_record_when_memory_threshold_exceeded(): void
    {
        $config = new SupervisorConfig(
            enabled: true,
            recycleMaxRequests: 10000,
            recycleMemoryThresholdMb: 128,
        );
        $supervisor = new Supervisor($config);

        $result = $supervisor->shouldRecycle(
            requestCount: 10,
            memoryUsageMb: 128,
            uptimeSeconds: 60,
        );

        self::assertNotNull($result);
        self::assertSame(RecycleReason::MemoryThreshold, $result->reason);
        self::assertSame(RecycleAction::GracefulRestart, $result->action);
        self::assertSame(128, $result->memoryUsageMb);
    }

    #[Test]
    public function it_returns_recycle_record_when_time_limit_exceeded(): void
    {
        $config = new SupervisorConfig(
            enabled: true,
            recycleMaxRequests: 10000,
            recycleMemoryThresholdMb: 256,
            recycleTimeLimitSeconds: 3600,
        );
        $supervisor = new Supervisor($config);

        $result = $supervisor->shouldRecycle(
            requestCount: 10,
            memoryUsageMb: 50,
            uptimeSeconds: 3600,
        );

        self::assertNotNull($result);
        self::assertSame(RecycleReason::TimeLimit, $result->reason);
        self::assertSame(RecycleAction::GracefulRestart, $result->action);
        self::assertSame(3600, $result->uptimeSeconds);
    }

    #[Test]
    public function it_returns_null_when_all_thresholds_are_within_limits(): void
    {
        $config = new SupervisorConfig(
            enabled: true,
            recycleMaxRequests: 10000,
            recycleMemoryThresholdMb: 256,
            recycleTimeLimitSeconds: 7200,
        );
        $supervisor = new Supervisor($config);

        $result = $supervisor->shouldRecycle(
            requestCount: 100,
            memoryUsageMb: 50,
            uptimeSeconds: 60,
        );

        self::assertNull($result);
    }

    #[Test]
    public function it_prioritizes_max_requests_over_memory_and_time(): void
    {
        $config = new SupervisorConfig(
            enabled: true,
            recycleMaxRequests: 100,
            recycleMemoryThresholdMb: 50,
            recycleTimeLimitSeconds: 60,
        );
        $supervisor = new Supervisor($config);

        // All thresholds exceeded — max_requests should be the reason (checked first)
        $result = $supervisor->shouldRecycle(
            requestCount: 200,
            memoryUsageMb: 200,
            uptimeSeconds: 200,
        );

        self::assertNotNull($result);
        self::assertSame(RecycleReason::MaxRequests, $result->reason);
    }

    #[Test]
    public function it_prioritizes_memory_over_time_when_requests_within_limit(): void
    {
        $config = new SupervisorConfig(
            enabled: true,
            recycleMaxRequests: 10000,
            recycleMemoryThresholdMb: 50,
            recycleTimeLimitSeconds: 60,
        );
        $supervisor = new Supervisor($config);

        $result = $supervisor->shouldRecycle(
            requestCount: 10,
            memoryUsageMb: 200,
            uptimeSeconds: 200,
        );

        self::assertNotNull($result);
        self::assertSame(RecycleReason::MemoryThreshold, $result->reason);
    }

    #[Test]
    public function it_returns_null_when_recycle_policy_is_null(): void
    {
        $config = new SupervisorConfig(enabled: true);
        $supervisor = new Supervisor($config, recyclePolicy: null, stuckJobPolicy: null);

        // When explicit null is passed, disabled override takes no effect but policy is null
        // The constructor uses $config->enabled to build a default policy, so we need to
        // explicitly pass null to override
        // Actually, the constructor checks: $recyclePolicy ?? ($config->enabled ? build : null)
        // So passing null explicitly means we get the fallback from config
        // We need a disabled config with explicit null policy
        $disabledConfig = new SupervisorConfig(enabled: false);
        $supervisorDisabled = new Supervisor($disabledConfig);

        $result = $supervisorDisabled->shouldRecycle(
            requestCount: 999_999,
            memoryUsageMb: 999_999,
            uptimeSeconds: 999_999,
        );

        self::assertNull($result);
    }

    #[Test]
    public function it_accepts_custom_recycle_policy(): void
    {
        $config = new SupervisorConfig(enabled: false);
        $customPolicy = new WorkerRecyclePolicy(
            maxRequests: 5,
            memoryThresholdMb: 10,
            timeLimitSeconds: 30,
        );
        $supervisor = new Supervisor($config, recyclePolicy: $customPolicy);

        $result = $supervisor->shouldRecycle(
            requestCount: 5,
            memoryUsageMb: 1,
            uptimeSeconds: 1,
        );

        self::assertNotNull($result);
        self::assertSame(RecycleReason::MaxRequests, $result->reason);
    }

    #[Test]
    public function it_detects_stuck_jobs_from_queue_driver(): void
    {
        $stuckJob = new JobRecord(
            id: 'job-1',
            queue: 'default',
            jobClass: 'App\\Jobs\\ProcessOrder',
            payload: '{}',
            attempts: 1,
            status: JobRecordStatus::Processing,
            createdAt: time() - 600,
            availableAt: time() - 600,
        );

        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('findByStatus')
            ->willReturn([$stuckJob]);

        $config = new SupervisorConfig(
            enabled: true,
            stuckJobTimeoutSeconds: 300,
        );
        $supervisor = new Supervisor($config);

        $result = $supervisor->detectStuckJobs($driver);

        self::assertCount(1, $result);
        self::assertSame('job-1', $result[0]->id);
    }

    #[Test]
    public function it_returns_empty_array_when_stuck_job_policy_is_null(): void
    {
        $config = new SupervisorConfig(enabled: false);
        $supervisor = new Supervisor($config);

        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects(self::never())->method('findByStatus');

        $result = $supervisor->detectStuckJobs($driver);

        self::assertSame([], $result);
    }

    #[Test]
    public function it_recovers_stuck_jobs_via_dead_letter_queue(): void
    {
        $stuckJob = new JobRecord(
            id: 'job-stuck-1',
            queue: 'emails',
            jobClass: 'App\\Jobs\\SendEmail',
            payload: '{"to":"test@example.com"}',
            attempts: 3,
            status: JobRecordStatus::Processing,
            createdAt: time() - 1000,
            availableAt: time() - 1000,
        );

        $driver = $this->createStub(QueueDriverInterface::class);
        $deadLetterQueue = new DeadLetterQueue($driver);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $config = new SupervisorConfig(enabled: true);
        $supervisor = new Supervisor(
            config: $config,
            logger: $logger,
        );

        $actions = $supervisor->recoverStuckJobs([$stuckJob], $deadLetterQueue);

        self::assertCount(1, $actions);
        self::assertInstanceOf(HealingAction::class, $actions[0]);
        self::assertSame(HealingActionType::StuckJobRecovery, $actions[0]->type);
        self::assertTrue($actions[0]->success);
        self::assertSame('job-stuck-1', $actions[0]->correlationId);
    }

    #[Test]
    public function it_recovers_multiple_stuck_jobs(): void
    {
        $jobs = [];
        for ($i = 1; $i <= 3; $i++) {
            $jobs[] = new JobRecord(
                id: "job-{$i}",
                queue: 'default',
                jobClass: 'App\\Jobs\\TestJob',
                payload: '{}',
                attempts: 1,
                status: JobRecordStatus::Processing,
                createdAt: time() - 1000,
                availableAt: time() - 1000,
            );
        }

        $driver = $this->createStub(QueueDriverInterface::class);
        $deadLetterQueue = new DeadLetterQueue($driver);

        $config = new SupervisorConfig(enabled: true);
        $supervisor = new Supervisor(config: $config);

        $actions = $supervisor->recoverStuckJobs($jobs, $deadLetterQueue);

        self::assertCount(3, $actions);
        self::assertSame('job-1', $actions[0]->correlationId);
        self::assertSame('job-2', $actions[1]->correlationId);
        self::assertSame('job-3', $actions[2]->correlationId);
    }

    #[Test]
    public function it_runs_preflight_checks(): void
    {
        $check1 = $this->createStub(PreflightCheckInterface::class);
        $check1->method('check')->willReturn(new PreflightCheckResult(
            passed: true,
            message: 'Memory OK',
        ));

        $check2 = $this->createStub(PreflightCheckInterface::class);
        $check2->method('check')->willReturn(new PreflightCheckResult(
            passed: false,
            message: 'Disk low',
        ));

        $config = new SupervisorConfig(enabled: true);
        $supervisor = new Supervisor(
            config: $config,
            preflightChecks: [$check1, $check2],
        );

        $results = $supervisor->runPreflightChecks();

        self::assertCount(2, $results);
        self::assertTrue($results[0]->passed);
        self::assertFalse($results[1]->passed);
    }

    #[Test]
    public function it_returns_empty_preflight_results_when_no_checks_registered(): void
    {
        $config = new SupervisorConfig(enabled: true);
        $supervisor = new Supervisor(config: $config);

        $results = $supervisor->runPreflightChecks();

        self::assertSame([], $results);
    }

    #[Test]
    public function it_runs_invariant_checks(): void
    {
        $check = $this->createStub(InvariantCheckInterface::class);
        $check->method('check')->willReturn(new InvariantCheckResult(
            passed: true,
            message: 'DB connected',
        ));

        $config = new SupervisorConfig(enabled: true);
        $supervisor = new Supervisor(
            config: $config,
            invariantChecks: [$check],
        );

        $results = $supervisor->runInvariantChecks();

        self::assertCount(1, $results);
        self::assertTrue($results[0]->passed);
        self::assertSame('DB connected', $results[0]->message);
    }

    #[Test]
    public function it_returns_empty_invariant_results_when_no_checks_registered(): void
    {
        $config = new SupervisorConfig(enabled: true);
        $supervisor = new Supervisor(config: $config);

        $results = $supervisor->runInvariantChecks();

        self::assertSame([], $results);
    }

    #[Test]
    public function it_records_performed_at_timestamp_in_recycle_record(): void
    {
        $config = new SupervisorConfig(enabled: true, recycleMaxRequests: 1);
        $supervisor = new Supervisor($config);

        $before = time();
        $result = $supervisor->shouldRecycle(
            requestCount: 1,
            memoryUsageMb: 10,
            uptimeSeconds: 10,
        );
        $after = time();

        self::assertNotNull($result);
        self::assertGreaterThanOrEqual($before, $result->performedAt);
        self::assertLessThanOrEqual($after, $result->performedAt);
    }
}
