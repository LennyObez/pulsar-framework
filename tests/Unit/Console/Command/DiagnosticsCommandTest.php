<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\DiagnosticsCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Core\Kernel;

#[CoversClass(DiagnosticsCommand::class)]
final class DiagnosticsCommandTest extends TestCase
{
    #[Test]
    public function displaysDiagnostics(): void
    {
        $kernel = new Kernel();

        $command = new DiagnosticsCommand($kernel);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('diagnostics'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Pulsar Framework Diagnostics', $output->buffer);
        self::assertStringContainsString('PHP', $output->buffer);
        self::assertStringContainsString(PHP_VERSION, $output->buffer);
        self::assertStringContainsString('Not booted', $output->buffer);
        self::assertStringContainsString('0 registered', $output->buffer);
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $kernel = new Kernel();
        $command = new DiagnosticsCommand($kernel);

        self::assertSame('diagnostics', $command->name);
    }
}
