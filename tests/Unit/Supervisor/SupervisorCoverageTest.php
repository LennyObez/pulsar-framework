<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Supervisor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\SupervisorConfig;
use Pulsar\Supervisor\RecycleAction;
use Pulsar\Supervisor\RecycleReason;
use Pulsar\Supervisor\RecycleRecord;
use Pulsar\Supervisor\Supervisor;
use Pulsar\Supervisor\WorkerRecyclePolicy;

#[CoversClass(Supervisor::class)]
#[CoversClass(WorkerRecyclePolicy::class)]
#[CoversClass(RecycleRecord::class)]
final class SupervisorCoverageTest extends TestCase
{
    #[Test]
    public function shouldRecycleReturnsNullWhenDisabled(): void
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
    public function shouldRecycleReturnsNullBelowThresholds(): void
    {
        $policy = new WorkerRecyclePolicy(
            maxRequests: 10_000,
            memoryThresholdMb: 256,
            timeLimitSeconds: 3600,
        );

        $config = new SupervisorConfig(enabled: true);
        $supervisor = new Supervisor($config, recyclePolicy: $policy);

        $result = $supervisor->shouldRecycle(
            requestCount: 100,
            memoryUsageMb: 50,
            uptimeSeconds: 60,
        );

        self::assertNull($result);
    }

    #[Test]
    public function shouldRecycleTriggersOnMaxRequests(): void
    {
        $policy = new WorkerRecyclePolicy(
            maxRequests: 1000,
            memoryThresholdMb: 256,
            timeLimitSeconds: 3600,
        );

        $config = new SupervisorConfig(enabled: true);
        $supervisor = new Supervisor($config, recyclePolicy: $policy);

        $record = $supervisor->shouldRecycle(
            requestCount: 1000,
            memoryUsageMb: 50,
            uptimeSeconds: 60,
        );

        self::assertNotNull($record);
        self::assertSame(RecycleReason::MaxRequests, $record->reason);
        self::assertSame(RecycleAction::GracefulRestart, $record->action);
        self::assertSame(1000, $record->requestCount);
        self::assertSame(50, $record->memoryUsageMb);
        self::assertSame(60, $record->uptimeSeconds);
    }

    #[Test]
    public function shouldRecycleTriggersOnMemoryThreshold(): void
    {
        $policy = new WorkerRecyclePolicy(
            maxRequests: 10_000,
            memoryThresholdMb: 256,
            timeLimitSeconds: 3600,
        );

        $config = new SupervisorConfig(enabled: true);
        $supervisor = new Supervisor($config, recyclePolicy: $policy);

        $record = $supervisor->shouldRecycle(
            requestCount: 100,
            memoryUsageMb: 256,
            uptimeSeconds: 60,
        );

        self::assertNotNull($record);
        self::assertSame(RecycleReason::MemoryThreshold, $record->reason);
    }

    #[Test]
    public function shouldRecycleTriggersOnTimeLimit(): void
    {
        $policy = new WorkerRecyclePolicy(
            maxRequests: 10_000,
            memoryThresholdMb: 512,
            timeLimitSeconds: 3600,
        );

        $config = new SupervisorConfig(enabled: true);
        $supervisor = new Supervisor($config, recyclePolicy: $policy);

        $record = $supervisor->shouldRecycle(
            requestCount: 100,
            memoryUsageMb: 50,
            uptimeSeconds: 3600,
        );

        self::assertNotNull($record);
        self::assertSame(RecycleReason::TimeLimit, $record->reason);
    }

    #[Test]
    public function shouldRecyclePrioritizesMaxRequestsOverMemory(): void
    {
        $policy = new WorkerRecyclePolicy(
            maxRequests: 100,
            memoryThresholdMb: 100,
            timeLimitSeconds: 100,
        );

        $config = new SupervisorConfig(enabled: true);
        $supervisor = new Supervisor($config, recyclePolicy: $policy);

        // All thresholds exceeded — MaxRequests is checked first
        $record = $supervisor->shouldRecycle(
            requestCount: 200,
            memoryUsageMb: 200,
            uptimeSeconds: 200,
        );

        self::assertNotNull($record);
        self::assertSame(RecycleReason::MaxRequests, $record->reason);
    }

    #[Test]
    public function recycleRecordStoresPerformedAt(): void
    {
        $record = new RecycleRecord(
            reason: RecycleReason::MemoryThreshold,
            action: RecycleAction::GracefulRestart,
            memoryUsageMb: 300,
            requestCount: 5000,
            uptimeSeconds: 1800,
            performedAt: 1710000000,
        );

        self::assertSame(1710000000, $record->performedAt);
        self::assertSame(RecycleAction::GracefulRestart, $record->action);
    }

    #[Test]
    public function workerRecyclePolicyFromConfig(): void
    {
        $config = new SupervisorConfig(
            enabled: true,
            recycleMaxRequests: 5000,
            recycleMemoryThresholdMb: 128,
            recycleTimeLimitSeconds: 7200,
        );

        $policy = WorkerRecyclePolicy::fromConfig($config);

        self::assertSame(5000, $policy->maxRequests);
        self::assertSame(128, $policy->memoryThresholdMb);
        self::assertSame(7200, $policy->timeLimitSeconds);
    }

    #[Test]
    public function runPreflightChecksWithNone(): void
    {
        $config = new SupervisorConfig(enabled: true);
        $supervisor = new Supervisor($config);

        $results = $supervisor->runPreflightChecks();

        self::assertSame([], $results);
    }

    #[Test]
    public function runInvariantChecksWithNone(): void
    {
        $config = new SupervisorConfig(enabled: true);
        $supervisor = new Supervisor($config);

        $results = $supervisor->runInvariantChecks();

        self::assertSame([], $results);
    }
}
