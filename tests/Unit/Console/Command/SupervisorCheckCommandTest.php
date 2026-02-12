<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\SupervisorCheckCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Supervisor\PreflightCheck\PreflightCheckResult;
use Pulsar\Supervisor\PreflightCheck\PreflightRunnerInterface;

#[CoversClass(SupervisorCheckCommand::class)]
final class SupervisorCheckCommandTest extends TestCase
{
    #[Test]
    public function configuredCorrectly(): void
    {
        $runner = $this->createStub(PreflightRunnerInterface::class);
        $command = new SupervisorCheckCommand($runner);

        self::assertSame('supervisor:check', $command->name);
    }

    #[Test]
    public function noChecksRegistered(): void
    {
        $runner = $this->createStub(PreflightRunnerInterface::class);
        $runner->method('run')->willReturn([]);

        $command = new SupervisorCheckCommand($runner);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('supervisor:check'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('No preflight checks registered', $output->buffer);
    }

    #[Test]
    public function allChecksPassed(): void
    {
        $results = [
            new PreflightCheckResult(passed: true, message: 'Memory check OK'),
            new PreflightCheckResult(passed: true, message: 'Disk space check OK'),
        ];

        $runner = $this->createStub(PreflightRunnerInterface::class);
        $runner->method('run')->willReturn($results);

        $command = new SupervisorCheckCommand($runner);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('supervisor:check'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('[OK] Memory check OK', $output->buffer);
        self::assertStringContainsString('[OK] Disk space check OK', $output->buffer);
        self::assertStringContainsString('All preflight checks passed', $output->buffer);
    }

    #[Test]
    public function someChecksFailed(): void
    {
        $results = [
            new PreflightCheckResult(passed: true, message: 'Memory check OK'),
            new PreflightCheckResult(
                passed: false,
                message: 'Disk space check FAILED',
                findings: ['Only 100MB free', 'Minimum required: 500MB'],
            ),
        ];

        $runner = $this->createStub(PreflightRunnerInterface::class);
        $runner->method('run')->willReturn($results);

        $command = new SupervisorCheckCommand($runner);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('supervisor:check'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('[OK] Memory check OK', $output->buffer);
        self::assertStringContainsString('[FAIL] Disk space check FAILED', $output->buffer);
        self::assertStringContainsString('Only 100MB free', $output->buffer);
        self::assertStringContainsString('Minimum required: 500MB', $output->buffer);
        self::assertStringContainsString('One or more preflight checks failed', $output->errorBuffer);
    }
}
