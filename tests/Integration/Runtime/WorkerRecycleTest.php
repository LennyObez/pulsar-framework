<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\SupervisorConfig;
use Pulsar\Supervisor\RecycleAction;
use Pulsar\Supervisor\RecycleReason;
use Pulsar\Supervisor\Supervisor;
use Pulsar\Supervisor\WorkerRecyclePolicy;

#[CoversClass(Supervisor::class)]
final class WorkerRecycleTest extends TestCase
{
    #[Test]
    public function it_triggers_recycle_on_max_requests(): void
    {
        $config = new SupervisorConfig(
            enabled: true,
            recycleMaxRequests: 100,
            recycleMemoryThresholdMb: 999,
            recycleTimeLimitSeconds: 99999,
        );

        $supervisor = new Supervisor($config);

        $result = $supervisor->shouldRecycle(
            requestCount: 100,
            memoryUsageMb: 50,
            uptimeSeconds: 60,
        );

        self::assertNotNull($result);
        self::assertSame(RecycleReason::MaxRequests, $result->reason);
        self::assertSame(RecycleAction::GracefulRestart, $result->action);
    }

    #[Test]
    public function it_triggers_recycle_on_memory_threshold(): void
    {
        $config = new SupervisorConfig(
            enabled: true,
            recycleMaxRequests: 99999,
            recycleMemoryThresholdMb: 256,
            recycleTimeLimitSeconds: 99999,
        );

        $supervisor = new Supervisor($config);

        $result = $supervisor->shouldRecycle(
            requestCount: 50,
            memoryUsageMb: 256,
            uptimeSeconds: 60,
        );

        self::assertNotNull($result);
        self::assertSame(RecycleReason::MemoryThreshold, $result->reason);
    }

    #[Test]
    public function it_triggers_recycle_on_time_limit(): void
    {
        $config = new SupervisorConfig(
            enabled: true,
            recycleMaxRequests: 99999,
            recycleMemoryThresholdMb: 999,
            recycleTimeLimitSeconds: 7200,
        );

        $supervisor = new Supervisor($config);

        $result = $supervisor->shouldRecycle(
            requestCount: 50,
            memoryUsageMb: 100,
            uptimeSeconds: 7200,
        );

        self::assertNotNull($result);
        self::assertSame(RecycleReason::TimeLimit, $result->reason);
    }

    #[Test]
    public function it_does_not_recycle_when_within_thresholds(): void
    {
        $config = new SupervisorConfig(
            enabled: true,
            recycleMaxRequests: 10000,
            recycleMemoryThresholdMb: 256,
            recycleTimeLimitSeconds: 7200,
        );

        $supervisor = new Supervisor($config);

        $result = $supervisor->shouldRecycle(
            requestCount: 500,
            memoryUsageMb: 100,
            uptimeSeconds: 300,
        );

        self::assertNull($result);
    }

    #[Test]
    public function recycle_policy_is_built_from_config(): void
    {
        $config = new SupervisorConfig(
            enabled: true,
            recycleMaxRequests: 5000,
            recycleMemoryThresholdMb: 128,
            recycleTimeLimitSeconds: 3600,
        );

        $policy = WorkerRecyclePolicy::fromConfig($config);

        self::assertSame(5000, $policy->maxRequests);
        self::assertSame(128, $policy->memoryThresholdMb);
        self::assertSame(3600, $policy->timeLimitSeconds);
    }
}
