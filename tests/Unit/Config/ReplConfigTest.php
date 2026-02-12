<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\ReplConfig;

#[CoversClass(ReplConfig::class)]
final class ReplConfigTest extends TestCase
{
    private Environment $environment;

    protected function setUp(): void
    {
        putenv('REPL_ENABLED');
        $this->environment = Environment::load();
    }

    protected function tearDown(): void
    {
        putenv('REPL_ENABLED');
    }

    #[Test]
    public function constructorDefaults(): void
    {
        $config = new ReplConfig();

        self::assertFalse($config->enabled);
        self::assertTrue($config->safeMode);
        self::assertFalse($config->audit);
        self::assertSame('.pulsar_repl_history', $config->historyFile);
        self::assertSame([], $config->startupCommands);
    }

    #[Test]
    public function fromArrayWithFullData(): void
    {
        $data = [
            'enabled' => true,
            'safe_mode' => false,
            'audit' => true,
            'history_file' => '/tmp/repl_history',
            'startup_commands' => ['use Pulsar\\Config\\Environment;'],
        ];

        $config = ReplConfig::fromArray($data, $this->environment);

        self::assertTrue($config->enabled);
        self::assertFalse($config->safeMode);
        self::assertTrue($config->audit);
        self::assertSame('/tmp/repl_history', $config->historyFile);
        self::assertSame(['use Pulsar\\Config\\Environment;'], $config->startupCommands);
    }

    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = ReplConfig::fromArray([], $this->environment);

        self::assertFalse($config->enabled);
        self::assertTrue($config->safeMode);
        self::assertFalse($config->audit);
        self::assertSame('.pulsar_repl_history', $config->historyFile);
        self::assertSame([], $config->startupCommands);
    }

    #[Test]
    public function environmentVariableOverridesEnabledToTrue(): void
    {
        putenv('REPL_ENABLED=true');
        $environment = Environment::load();

        $config = ReplConfig::fromArray([
            'enabled' => false,
        ], $environment);

        self::assertTrue($config->enabled);
    }

    #[Test]
    public function environmentVariableOverridesEnabledToFalse(): void
    {
        putenv('REPL_ENABLED=false');
        $environment = Environment::load();

        $config = ReplConfig::fromArray([
            'enabled' => true,
        ], $environment);

        self::assertFalse($config->enabled);
    }

    #[Test]
    public function configArrayEnabledUsedWhenNoEnvironmentVariable(): void
    {
        $config = ReplConfig::fromArray([
            'enabled' => true,
        ], $this->environment);

        self::assertTrue($config->enabled);
    }

    #[Test]
    public function invalidStartupCommandsDefaultsToEmpty(): void
    {
        $config = ReplConfig::fromArray([
            'startup_commands' => 'not-an-array',
        ], $this->environment);

        self::assertSame([], $config->startupCommands);
    }

    #[Test]
    public function invalidHistoryFileDefaultsToDefault(): void
    {
        $config = ReplConfig::fromArray([
            'history_file' => 123,
        ], $this->environment);

        self::assertSame('.pulsar_repl_history', $config->historyFile);
    }
}
