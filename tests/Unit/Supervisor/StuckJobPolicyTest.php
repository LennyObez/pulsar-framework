<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Supervisor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\SupervisorConfig;
use Pulsar\Supervisor\StuckJobPolicy;
use ReflectionClass;

#[CoversClass(StuckJobPolicy::class)]
final class StuckJobPolicyTest extends TestCase
{
    #[Test]
    public function it_stores_constructor_values(): void
    {
        $policy = new StuckJobPolicy(
            timeoutSeconds: 600,
            checkIntervalSeconds: 30,
            moveToDeadLetter: false,
        );

        self::assertSame(600, $policy->timeoutSeconds);
        self::assertSame(30, $policy->checkIntervalSeconds);
        self::assertFalse($policy->moveToDeadLetter);
    }

    #[Test]
    public function it_creates_from_config_with_default_values(): void
    {
        $config = new SupervisorConfig(enabled: true);
        $policy = StuckJobPolicy::fromConfig($config);

        self::assertSame(300, $policy->timeoutSeconds);
        self::assertSame(60, $policy->checkIntervalSeconds);
        self::assertTrue($policy->moveToDeadLetter);
    }

    #[Test]
    public function it_creates_from_config_with_custom_values(): void
    {
        $config = new SupervisorConfig(
            enabled: true,
            stuckJobTimeoutSeconds: 120,
            stuckJobCheckIntervalSeconds: 15,
            stuckJobMoveToDeadLetter: false,
        );
        $policy = StuckJobPolicy::fromConfig($config);

        self::assertSame(120, $policy->timeoutSeconds);
        self::assertSame(15, $policy->checkIntervalSeconds);
        self::assertFalse($policy->moveToDeadLetter);
    }

    #[Test]
    public function it_is_readonly(): void
    {
        $policy = new StuckJobPolicy(
            timeoutSeconds: 300,
            checkIntervalSeconds: 60,
            moveToDeadLetter: true,
        );

        $reflection = new ReflectionClass($policy);
        self::assertTrue($reflection->isReadOnly());
    }
}
