<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\RepairCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Resilience\Repair\RepairDiagnosis;
use Pulsar\Resilience\Repair\RepairJobInterface;
use Pulsar\Resilience\Repair\RepairResult;
use Pulsar\Resilience\Repair\RepairRunner;
use RuntimeException;

#[CoversClass(RepairCommand::class)]
#[CoversClass(RepairDiagnosis::class)]
#[CoversClass(RepairResult::class)]
final class RepairCommandTest extends TestCase
{
    #[Test]
    public function noRepairsNeeded(): void
    {
        $runner = new RepairRunner();
        $runner->register($this->makeRepairJob('db-check', false, 'Database OK'));

        $command = new RepairCommand($runner);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('health:repair'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('[OK]', $output->buffer);
        self::assertStringContainsString('No repairs needed', $output->buffer);
    }

    #[Test]
    public function repairsSucceed(): void
    {
        $job = $this->createMock(RepairJobInterface::class);
        $job->method('getName')->willReturn('cache-repair');
        $job->method('getDescription')->willReturn('Cache repair');
        $job->method('diagnose')->willReturn(
            new RepairDiagnosis('cache-repair', true, 'Cache corrupted'),
        );
        $job->method('repair')->willReturn(
            new RepairResult('cache-repair', true, 'Cache rebuilt', ['Cleared cache', 'Rebuilt index']),
        );

        $runner = new RepairRunner();
        $runner->register($job);

        $command = new RepairCommand($runner);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('health:repair'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('[NEEDS REPAIR]', $output->buffer);
        self::assertStringContainsString('[FIXED]', $output->buffer);
        self::assertStringContainsString('Cleared cache', $output->buffer);
        self::assertStringContainsString('All 1 repair(s) completed', $output->buffer);
    }

    #[Test]
    public function repairsFail(): void
    {
        $job = $this->createMock(RepairJobInterface::class);
        $job->method('getName')->willReturn('db-repair');
        $job->method('getDescription')->willReturn('DB repair');
        $job->method('diagnose')->willReturn(
            new RepairDiagnosis('db-repair', true, 'DB corrupted'),
        );
        $job->method('repair')->willReturn(
            new RepairResult('db-repair', false, 'Could not repair DB'),
        );

        $runner = new RepairRunner();
        $runner->register($job);

        $command = new RepairCommand($runner);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('health:repair'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('[FAILED]', $output->buffer);
        self::assertStringContainsString('1/1 repair(s) failed', $output->errorBuffer);
    }

    #[Test]
    public function diagnosisWithFindings(): void
    {
        $diagnosis = new RepairDiagnosis('test', true, 'Issue found', ['finding1', 'finding2']);

        self::assertTrue($diagnosis->needsRepair);
        self::assertSame(['finding1', 'finding2'], $diagnosis->findings);
    }

    #[Test]
    public function repairResultWithException(): void
    {
        $exception = new RuntimeException('Failed');
        $result = new RepairResult('test', false, 'Failed', [], $exception);

        self::assertFalse($result->success);
        self::assertSame($exception, $result->exception);
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $runner = new RepairRunner();
        $command = new RepairCommand($runner);

        self::assertSame('health:repair', $command->name);
    }

    private function makeRepairJob(string $name, bool $needsRepair, string $description): RepairJobInterface
    {
        $job = $this->createMock(RepairJobInterface::class);
        $job->method('getName')->willReturn($name);
        $job->method('getDescription')->willReturn($description);
        $job->method('diagnose')->willReturn(
            new RepairDiagnosis($name, $needsRepair, $description),
        );

        return $job;
    }
}
