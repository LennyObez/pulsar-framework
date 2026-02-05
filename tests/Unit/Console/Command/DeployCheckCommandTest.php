<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\DeployCheckCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\DeployCheckRunnerInterface;
use Pulsar\Deploy\DeployReport;

#[CoversClass(DeployCheckCommand::class)]
final class DeployCheckCommandTest extends TestCase
{
    private BufferedOutput $output;

    #[Override]
    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
    }

    #[Test]
    public function nameIsDeployCheck(): void
    {
        $runner = $this->createStub(DeployCheckRunnerInterface::class);
        $command = new DeployCheckCommand($runner);

        self::assertSame('deploy:check', $command->name);
    }

    #[Test]
    public function descriptionIsSet(): void
    {
        $runner = $this->createStub(DeployCheckRunnerInterface::class);
        $command = new DeployCheckCommand($runner);

        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function allPassedRendersTable(): void
    {
        $report = new DeployReport(
            results: [
                CheckResult::pass('Cache', 'Cache is configured'),
                CheckResult::pass('DB', 'Database is reachable'),
            ],
            passed: 2,
            warnings: 0,
            errors: 0,
            environment: 'production',
        );

        $runner = $this->createStub(DeployCheckRunnerInterface::class);
        $runner->method('run')->willReturn($report);

        $command = new DeployCheckCommand($runner);
        $input = new ArrayInput(options: ['env' => 'production']);
        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('production', $this->output->buffer);
        self::assertStringContainsString('PASS', $this->output->buffer);
        self::assertStringContainsString('All deploy checks passed', $this->output->buffer);
    }

    #[Test]
    public function noChecksRegisteredRendersMessage(): void
    {
        $report = new DeployReport(
            results: [],
            passed: 0,
            warnings: 0,
            errors: 0,
            environment: 'production',
        );

        $runner = $this->createStub(DeployCheckRunnerInterface::class);
        $runner->method('run')->willReturn($report);

        $command = new DeployCheckCommand($runner);
        $input = new ArrayInput();
        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('No deploy checks registered', $this->output->buffer);
    }

    #[Test]
    public function warningsAndErrorsShowRecommendations(): void
    {
        $report = new DeployReport(
            results: [
                CheckResult::pass('Cache', 'OK'),
                CheckResult::warning('SSL', 'Certificate expires in 7 days', ['Renew the SSL certificate']),
                CheckResult::error('Disk', 'Disk usage at 95%', ['Free up disk space', 'Add more storage']),
            ],
            passed: 1,
            warnings: 1,
            errors: 1,
            environment: 'staging',
        );

        $runner = $this->createStub(DeployCheckRunnerInterface::class);
        $runner->method('run')->willReturn($report);

        $command = new DeployCheckCommand($runner);
        $input = new ArrayInput(options: ['env' => 'staging']);
        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Recommendations', $this->output->buffer);
        self::assertStringContainsString('Renew the SSL certificate', $this->output->buffer);
        self::assertStringContainsString('Free up disk space', $this->output->buffer);
        self::assertStringContainsString('1 error(s) detected', $this->output->buffer);
    }

    #[Test]
    public function strictModeFailsOnErrors(): void
    {
        $report = new DeployReport(
            results: [
                CheckResult::error('DB', 'Database unreachable'),
            ],
            passed: 0,
            warnings: 0,
            errors: 1,
            environment: 'production',
        );

        $runner = $this->createStub(DeployCheckRunnerInterface::class);
        $runner->method('run')->willReturn($report);

        $command = new DeployCheckCommand($runner);
        $input = new ArrayInput(options: ['strict' => true]);
        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('error(s) detected', $this->output->errorBuffer);
    }

    #[Test]
    public function strictModePassesWhenNoErrors(): void
    {
        $report = new DeployReport(
            results: [
                CheckResult::pass('Cache', 'OK'),
            ],
            passed: 1,
            warnings: 0,
            errors: 0,
            environment: 'production',
        );

        $runner = $this->createStub(DeployCheckRunnerInterface::class);
        $runner->method('run')->willReturn($report);

        $command = new DeployCheckCommand($runner);
        $input = new ArrayInput(options: ['strict' => true]);
        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
    }

    #[Test]
    public function jsonModeRendersJsonEnvelope(): void
    {
        $report = new DeployReport(
            results: [
                CheckResult::pass('Cache', 'OK'),
                CheckResult::warning('SSL', 'Expires soon', ['Renew cert']),
            ],
            passed: 1,
            warnings: 1,
            errors: 0,
            environment: 'production',
        );

        $runner = $this->createStub(DeployCheckRunnerInterface::class);
        $runner->method('run')->willReturn($report);

        $command = new DeployCheckCommand($runner);
        $input = new ArrayInput(options: ['json' => true]);
        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->output->buffer, true);
        self::assertIsArray($decoded);
        self::assertSame('deploy:check', $decoded['command']);
        self::assertTrue($decoded['success']);
        /** @var array<string, mixed> $data */
        $data = $decoded['data'];
        self::assertSame('production', $data['environment']);
        /** @var array<string, int> $summary */
        $summary = $data['summary'];
        self::assertSame(1, $summary['passed']);
        self::assertSame(1, $summary['warnings']);
        /** @var list<mixed> $results */
        $results = $data['results'];
        self::assertCount(2, $results);
    }

    #[Test]
    public function jsonModeStrictWithErrorsFails(): void
    {
        $report = new DeployReport(
            results: [
                CheckResult::error('DB', 'Unreachable'),
            ],
            passed: 0,
            warnings: 0,
            errors: 1,
            environment: 'production',
        );

        $runner = $this->createStub(DeployCheckRunnerInterface::class);
        $runner->method('run')->willReturn($report);

        $command = new DeployCheckCommand($runner);
        $input = new ArrayInput(options: ['json' => true, 'strict' => true]);
        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Error->value, $exit);

        $decoded = json_decode($this->output->buffer, true);
        self::assertIsArray($decoded);
        self::assertFalse($decoded['success']);
    }

    #[Test]
    public function defaultEnvironmentIsProduction(): void
    {
        $report = new DeployReport(
            results: [],
            passed: 0,
            warnings: 0,
            errors: 0,
            environment: 'production',
        );

        $runner = $this->createMock(DeployCheckRunnerInterface::class);
        $runner->expects(self::once())->method('run')->with('production')->willReturn($report);

        $command = new DeployCheckCommand($runner);
        $input = new ArrayInput();
        $command->execute($input, $this->output);
    }

    #[Test]
    public function customEnvironmentIsPassed(): void
    {
        $report = new DeployReport(
            results: [],
            passed: 0,
            warnings: 0,
            errors: 0,
            environment: 'staging',
        );

        $runner = $this->createMock(DeployCheckRunnerInterface::class);
        $runner->expects(self::once())->method('run')->with('staging')->willReturn($report);

        $command = new DeployCheckCommand($runner);
        $input = new ArrayInput(options: ['env' => 'staging']);
        $command->execute($input, $this->output);
    }

    #[Test]
    public function errorsWithoutStrictModeShowWarning(): void
    {
        $report = new DeployReport(
            results: [
                CheckResult::error('DB', 'Down'),
            ],
            passed: 0,
            warnings: 0,
            errors: 1,
            environment: 'production',
        );

        $runner = $this->createStub(DeployCheckRunnerInterface::class);
        $runner->method('run')->willReturn($report);

        $command = new DeployCheckCommand($runner);
        $input = new ArrayInput();
        $exit = $command->execute($input, $this->output);

        // Without strict, exits success but shows warning
        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('--strict', $this->output->buffer);
    }
}
