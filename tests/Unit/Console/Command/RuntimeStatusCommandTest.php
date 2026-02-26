<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Console\Command\RuntimeStatusCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Runtime\RuntimeResolver;

#[CoversClass(RuntimeStatusCommand::class)]
final class RuntimeStatusCommandTest extends TestCase
{
    #[Test]
    public function configured_correctly(): void
    {
        $resolver = $this->createResolver();
        $command = new RuntimeStatusCommand($resolver);

        self::assertSame('runtime:status', $command->name);
    }

    #[Test]
    public function execute_shows_available_runtimes(): void
    {
        $resolver = $this->createResolver(sockets: true);
        $command = new RuntimeStatusCommand($resolver);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('runtime:status'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('fpm: available', $output->buffer);
        self::assertStringContainsString('persistent: available', $output->buffer);
        self::assertStringContainsString('frankenphp: not available', $output->buffer);
        self::assertStringContainsString('roadrunner: not available', $output->buffer);
    }

    #[Test]
    public function execute_shows_resolved_runtime(): void
    {
        $resolver = $this->createResolver(sockets: true);
        $command = new RuntimeStatusCommand($resolver);
        $output = new BufferedOutput();

        $command->execute(new ArrayInput('runtime:status'), $output);

        self::assertStringContainsString('Resolved runtime: persistent', $output->buffer);
        self::assertStringContainsString('(active)', $output->buffer);
    }

    #[Test]
    public function execute_falls_back_to_fpm_when_nothing_available(): void
    {
        $resolver = $this->createResolver();
        $command = new RuntimeStatusCommand($resolver);
        $output = new BufferedOutput();

        $command->execute(new ArrayInput('runtime:status'), $output);

        self::assertStringContainsString('Resolved runtime: fpm', $output->buffer);
    }

    private function createResolver(
        bool $frankenPhp = false,
        bool $roadRunner = false,
        bool $sockets = false,
    ): RuntimeResolver {
        return new RuntimeResolver(
            environment: Environment::load(null),
            frankenPhpDetector: static fn(): bool => $frankenPhp,
            roadRunnerDetector: static fn(): bool => $roadRunner,
            socketsDetector: static fn(): bool => $sockets,
        );
    }
}
