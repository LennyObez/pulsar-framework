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
use Pulsar\Console\Repl\ReplCommand;
use Pulsar\Container\ContainerInterface;

#[CoversClass(ReplCommand::class)]
final class ReplCommandTest extends TestCase
{
    protected function setUp(): void
    {
        // Ensure no CI env var interferes
        putenv('CI');
    }

    protected function tearDown(): void
    {
        putenv('CI');
    }

    #[Test]
    public function commandNameIsRepl(): void
    {
        $command = $this->makeCommand();

        self::assertSame('repl', $command->name);
    }

    #[Test]
    public function commandHasDescription(): void
    {
        $command = $this->makeCommand();

        self::assertNotSame('', $command->description);
    }

    #[Test]
    public function commandHasSandboxOption(): void
    {
        $command = $this->makeCommand();

        self::assertArrayHasKey('sandbox', $command->options);
    }

    #[Test]
    public function commandHasReadonlyOption(): void
    {
        $command = $this->makeCommand();

        self::assertArrayHasKey('readonly', $command->options);
    }

    #[Test]
    public function commandHasNoSafeModeOption(): void
    {
        $command = $this->makeCommand();

        self::assertArrayHasKey('no-safe-mode', $command->options);
    }

    #[Test]
    public function commandHasIKnowWhatImDoingOption(): void
    {
        $command = $this->makeCommand();

        self::assertArrayHasKey('i-know-what-im-doing', $command->options);
    }

    #[Test]
    public function commandHasNoAuditOption(): void
    {
        $command = $this->makeCommand();

        self::assertArrayHasKey('no-audit', $command->options);
    }

    #[Test]
    public function executeReturnsErrorWhenGuardDeniesAccess(): void
    {
        // Guard denies access when config is disabled
        $guard = new EnvironmentGuard(
            EnvironmentMode::Local,
            Environment::load(),
            configEnabled: false,
        );

        $command = $this->makeCommand(guard: $guard);

        $input = $this->createStub(InputInterface::class);
        $input->method('hasOption')->willReturn(false);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
    }

    #[Test]
    public function executeReturnsErrorWhenProductionWithoutForceFlag(): void
    {
        $guard = new EnvironmentGuard(
            EnvironmentMode::Production,
            Environment::load(),
            configEnabled: true,
        );

        $command = $this->makeCommand(guard: $guard);

        $input = $this->createStub(InputInterface::class);
        $input->method('hasOption')->willReturn(false);

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
    }

    #[Test]
    public function executeReturnsErrorWhenReadonlyAndSandboxCombined(): void
    {
        $guard = new EnvironmentGuard(
            EnvironmentMode::Local,
            Environment::load(),
            configEnabled: true,
        );

        $command = $this->makeCommand(guard: $guard);

        $input = $this->createStub(InputInterface::class);
        $input->method('hasOption')->willReturnCallback(
            static fn(string $name): bool => $name === 'readonly' || $name === 'sandbox',
        );

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::atLeastOnce())->method('errorln');

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
    }

    private function makeCommand(
        ?EnvironmentGuard $guard = null,
    ): ReplCommand {
        $container = $this->createStub(ContainerInterface::class);
        $config = new ReplConfig(enabled: true);

        $guard ??= new EnvironmentGuard(
            EnvironmentMode::Local,
            Environment::load(),
            configEnabled: true,
        );

        return new ReplCommand(
            container: $container,
            mode: EnvironmentMode::Local,
            guard: $guard,
            config: $config,
        );
    }
}
