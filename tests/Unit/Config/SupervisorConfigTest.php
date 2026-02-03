<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\SupervisorConfig;

#[CoversClass(SupervisorConfig::class)]
final class SupervisorConfigTest extends TestCase
{
    private Environment $environment;

    protected function setUp(): void
    {
        putenv('SUPERVISOR_ENABLED');
        $this->environment = Environment::load();
    }

    protected function tearDown(): void
    {
        putenv('SUPERVISOR_ENABLED');
    }

    #[Test]
    public function fromArrayWithFullData(): void
    {
        $data = [
            'enabled' => true,
            'recycle' => [
                'max_requests' => 5000,
                'memory_threshold_mb' => 512,
                'time_limit_seconds' => 3600,
            ],
            'stuck_job' => [
                'timeout_seconds' => 600,
                'check_interval_seconds' => 120,
                'move_to_dead_letter' => false,
            ],
        ];

        $config = SupervisorConfig::fromArray($data, $this->environment);

        self::assertTrue($config->enabled);
        self::assertSame(5000, $config->recycleMaxRequests);
        self::assertSame(512, $config->recycleMemoryThresholdMb);
        self::assertSame(3600, $config->recycleTimeLimitSeconds);
        self::assertSame(600, $config->stuckJobTimeoutSeconds);
        self::assertSame(120, $config->stuckJobCheckIntervalSeconds);
        self::assertFalse($config->stuckJobMoveToDeadLetter);
    }

    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = SupervisorConfig::fromArray([], $this->environment);

        self::assertFalse($config->enabled);
        self::assertSame(10000, $config->recycleMaxRequests);
        self::assertSame(256, $config->recycleMemoryThresholdMb);
        self::assertSame(7200, $config->recycleTimeLimitSeconds);
        self::assertSame(300, $config->stuckJobTimeoutSeconds);
        self::assertSame(60, $config->stuckJobCheckIntervalSeconds);
        self::assertTrue($config->stuckJobMoveToDeadLetter);
    }

    #[Test]
    public function environmentVariableOverridesEnabledToTrue(): void
    {
        putenv('SUPERVISOR_ENABLED=true');
        $environment = Environment::load();

        $config = SupervisorConfig::fromArray([
            'enabled' => false,
        ], $environment);

        self::assertTrue($config->enabled);
    }

    #[Test]
    public function environmentVariableOverridesEnabledToFalse(): void
    {
        putenv('SUPERVISOR_ENABLED=false');
        $environment = Environment::load();

        $config = SupervisorConfig::fromArray([
            'enabled' => true,
        ], $environment);

        self::assertFalse($config->enabled);
    }

    #[Test]
    public function configArrayEnabledUsedWhenNoEnvironmentVariable(): void
    {
        $config = SupervisorConfig::fromArray([
            'enabled' => true,
        ], $this->environment);

        self::assertTrue($config->enabled);
    }

    #[Test]
    public function partialDataFillsRemainingWithDefaults(): void
    {
        $data = [
            'recycle' => [
                'max_requests' => 20000,
            ],
        ];

        $config = SupervisorConfig::fromArray($data, $this->environment);

        self::assertFalse($config->enabled);
        self::assertSame(20000, $config->recycleMaxRequests);
        self::assertSame(256, $config->recycleMemoryThresholdMb);
        self::assertSame(7200, $config->recycleTimeLimitSeconds);
        self::assertSame(300, $config->stuckJobTimeoutSeconds);
        self::assertSame(60, $config->stuckJobCheckIntervalSeconds);
        self::assertTrue($config->stuckJobMoveToDeadLetter);
    }

    #[Test]
    public function constructorDefaults(): void
    {
        $config = new SupervisorConfig();

        self::assertFalse($config->enabled);
        self::assertSame(10000, $config->recycleMaxRequests);
        self::assertSame(256, $config->recycleMemoryThresholdMb);
        self::assertSame(7200, $config->recycleTimeLimitSeconds);
        self::assertSame(300, $config->stuckJobTimeoutSeconds);
        self::assertSame(60, $config->stuckJobCheckIntervalSeconds);
        self::assertTrue($config->stuckJobMoveToDeadLetter);
    }
}
