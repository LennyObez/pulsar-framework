<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\RuntimeReloadCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;

use function function_exists;

use const PHP_OS_FAMILY;

#[CoversClass(RuntimeReloadCommand::class)]
final class RuntimeReloadCommandTest extends TestCase
{
    #[Test]
    public function configured_correctly(): void
    {
        $command = new RuntimeReloadCommand();

        self::assertSame('runtime:reload', $command->name);
    }

    #[Test]
    public function execute_returns_error_on_windows(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('This test only runs on Windows');
        }

        $command = new RuntimeReloadCommand();
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('runtime:reload'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('not supported on Windows', $output->errorBuffer);
    }

    #[Test]
    public function execute_returns_error_without_pid_on_non_windows(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('This test only runs on non-Windows');
        }

        if (!function_exists('posix_kill')) {
            self::markTestSkipped('posix extension required');
        }

        $command = new RuntimeReloadCommand();
        $output = new BufferedOutput();

        // No --pid and no pid file => should fail
        $exit = $command->execute(new ArrayInput('runtime:reload'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('Cannot detect runtime PID', $output->errorBuffer);
    }
}
