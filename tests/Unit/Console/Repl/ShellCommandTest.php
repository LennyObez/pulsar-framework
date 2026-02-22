<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Repl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Config\ReplConfig;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Console\Repl\EnvironmentGuard;
use Pulsar\Console\Repl\ShellCommand;
use Pulsar\Container\ContainerInterface;

#[CoversClass(ShellCommand::class)]
final class ShellCommandTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('CI');
    }

    protected function tearDown(): void
    {
        putenv('CI');
    }

    #[Test]
    public function configuresCommandMetadata(): void
    {
        $command = $this->createShellCommand();

        self::assertSame('shell', $command->name);
        self::assertSame('Start an interactive REPL with framework context', $command->description);
        self::assertArrayHasKey('no-safe-mode', $command->options);
        self::assertArrayHasKey('i-know-what-im-doing', $command->options);
        self::assertArrayHasKey('no-audit', $command->options);
    }

    #[Test]
    public function executeReturnsErrorWhenGuardRejectsDisabledRepl(): void
    {
        $environment = Environment::load();
        $config = new ReplConfig(enabled: false);
        $guard = new EnvironmentGuard(EnvironmentMode::Local, $environment, $config->enabled);

        $command = $this->createShellCommand(guard: $guard, config: $config);

        $input = $this->createStub(InputInterface::class);
        $input->method('hasOption')->willReturn(false);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        $result = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $result);
    }

    #[Test]
    public function executeReturnsErrorInCiEnvironment(): void
    {
        putenv('CI=true');
        $environment = Environment::load();
        $config = new ReplConfig(enabled: true);
        $guard = new EnvironmentGuard(EnvironmentMode::Local, $environment, $config->enabled);

        $command = $this->createShellCommand(guard: $guard, config: $config);

        $input = $this->createStub(InputInterface::class);
        $input->method('hasOption')->willReturn(false);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        $result = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $result);
    }

    #[Test]
    public function executeReturnsErrorWhenProductionWithoutForceFlag(): void
    {
        $environment = Environment::load();
        $config = new ReplConfig(enabled: true);
        $guard = new EnvironmentGuard(EnvironmentMode::Production, $environment, $config->enabled);

        $command = $this->createShellCommand(guard: $guard, config: $config);

        $input = $this->createStub(InputInterface::class);
        $input->method('hasOption')->willReturn(false);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        $result = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $result);
    }

    private function createShellCommand(
        ?EnvironmentGuard $guard = null,
        ?ReplConfig $config = null,
    ): ShellCommand {
        $container = $this->createStub(ContainerInterface::class);
        $mode = EnvironmentMode::Local;
        $config ??= new ReplConfig();
        $environment = Environment::load();
        $guard ??= new EnvironmentGuard($mode, $environment, $config->enabled);

        return new ShellCommand($container, $mode, $guard, $config);
    }
}
