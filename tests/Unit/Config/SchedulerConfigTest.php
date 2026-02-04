<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\SchedulerConfig;

#[CoversClass(SchedulerConfig::class)]
final class SchedulerConfigTest extends TestCase
{
    private Environment $environment;

    protected function setUp(): void
    {
        putenv('SCHEDULER_ENABLED');
        $this->environment = Environment::load();
    }

    protected function tearDown(): void
    {
        putenv('SCHEDULER_ENABLED');
    }

    #[Test]
    public function fromArrayWithFullData(): void
    {
        $data = [
            'enabled' => true,
            'timezone' => 'America/New_York',
            'max_execution_time' => 7200,
            'lock_timeout' => 600,
            'log_output' => false,
        ];

        $config = SchedulerConfig::fromArray($data, $this->environment);

        self::assertTrue($config->enabled);
        self::assertSame('America/New_York', $config->timezone);
        self::assertSame(7200, $config->maxExecutionTime);
        self::assertSame(600, $config->lockTimeout);
        self::assertFalse($config->logOutput);
    }

    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = SchedulerConfig::fromArray([], $this->environment);

        self::assertFalse($config->enabled);
        self::assertSame('UTC', $config->timezone);
        self::assertSame(3600, $config->maxExecutionTime);
        self::assertSame(300, $config->lockTimeout);
        self::assertTrue($config->logOutput);
    }

    #[Test]
    public function environmentVariableOverridesEnabledToTrue(): void
    {
        putenv('SCHEDULER_ENABLED=true');
        $environment = Environment::load();

        $config = SchedulerConfig::fromArray([
            'enabled' => false,
        ], $environment);

        self::assertTrue($config->enabled);
    }

    #[Test]
    public function environmentVariableOverridesEnabledToFalse(): void
    {
        putenv('SCHEDULER_ENABLED=false');
        $environment = Environment::load();

        $config = SchedulerConfig::fromArray([
            'enabled' => true,
        ], $environment);

        self::assertFalse($config->enabled);
    }

    #[Test]
    public function configArrayEnabledUsedWhenNoEnvironmentVariable(): void
    {
        $config = SchedulerConfig::fromArray([
            'enabled' => true,
        ], $this->environment);

        self::assertTrue($config->enabled);
    }

    #[Test]
    public function partialDataFillsRemainingWithDefaults(): void
    {
        $data = [
            'timezone' => 'Europe/London',
            'log_output' => false,
        ];

        $config = SchedulerConfig::fromArray($data, $this->environment);

        self::assertFalse($config->enabled);
        self::assertSame('Europe/London', $config->timezone);
        self::assertSame(3600, $config->maxExecutionTime);
        self::assertSame(300, $config->lockTimeout);
        self::assertFalse($config->logOutput);
    }

    #[Test]
    public function constructorDefaults(): void
    {
        $config = new SchedulerConfig();

        self::assertFalse($config->enabled);
        self::assertSame('UTC', $config->timezone);
        self::assertSame(3600, $config->maxExecutionTime);
        self::assertSame(300, $config->lockTimeout);
        self::assertTrue($config->logOutput);
    }
}
