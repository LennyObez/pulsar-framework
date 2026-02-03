<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Supervisor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\SupervisorConfig;
use Pulsar\Supervisor\WorkerRecyclePolicy;
use ReflectionClass;

#[CoversClass(WorkerRecyclePolicy::class)]
final class WorkerRecyclePolicyTest extends TestCase
{
    #[Test]
    public function it_stores_constructor_values(): void
    {
        $policy = new WorkerRecyclePolicy(
            maxRequests: 5000,
            memoryThresholdMb: 128,
            timeLimitSeconds: 3600,
        );

        self::assertSame(5000, $policy->maxRequests);
        self::assertSame(128, $policy->memoryThresholdMb);
        self::assertSame(3600, $policy->timeLimitSeconds);
    }

    #[Test]
    public function it_creates_from_config_with_default_values(): void
    {
        $config = new SupervisorConfig(enabled: true);
        $policy = WorkerRecyclePolicy::fromConfig($config);

        self::assertSame(10000, $policy->maxRequests);
        self::assertSame(256, $policy->memoryThresholdMb);
        self::assertSame(7200, $policy->timeLimitSeconds);
    }

    #[Test]
    public function it_creates_from_config_with_custom_values(): void
    {
        $config = new SupervisorConfig(
            enabled: true,
            recycleMaxRequests: 500,
            recycleMemoryThresholdMb: 64,
            recycleTimeLimitSeconds: 1800,
        );
        $policy = WorkerRecyclePolicy::fromConfig($config);

        self::assertSame(500, $policy->maxRequests);
        self::assertSame(64, $policy->memoryThresholdMb);
        self::assertSame(1800, $policy->timeLimitSeconds);
    }

    #[Test]
    public function it_is_readonly(): void
    {
        $policy = new WorkerRecyclePolicy(
            maxRequests: 100,
            memoryThresholdMb: 64,
            timeLimitSeconds: 600,
        );

        $reflection = new ReflectionClass($policy);
        self::assertTrue($reflection->isReadOnly());
    }
}
