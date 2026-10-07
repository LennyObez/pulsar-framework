<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\ScheduleRunCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Scheduler\JobInterface;
use Pulsar\Scheduler\JobRegistry;
use Pulsar\Scheduler\JobResult;
use Pulsar\Scheduler\Schedule;
use Pulsar\Scheduler\Scheduler;
use RuntimeException;

#[CoversClass(ScheduleRunCommand::class)]
final class ScheduleRunCommandTest extends TestCase
{
    #[Test]
    public function configuredWithCorrectName(): void
    {
        $scheduler = new Scheduler(new JobRegistry());
        $command = new ScheduleRunCommand($scheduler);

        self::assertSame('schedule:run', $command->name);
        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function noTasksDueReturnsSuccessWithMessage(): void
    {
        $scheduler = new Scheduler(new JobRegistry());
        $command = new ScheduleRunCommand($scheduler);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('schedule:run'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('No scheduled tasks are due', $output->buffer);
    }

    #[Test]
    public function successfulTaskExecution(): void
    {
        $registry = new JobRegistry();
        $registry->register($this->makeJob(
            'daily-backup',
            Schedule::everyMinute(),
            JobResult::success('daily-backup', new DateTimeImmutable()),
        ));

        $scheduler = new Scheduler($registry);
        $command = new ScheduleRunCommand($scheduler);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('schedule:run'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('daily-backup', $output->buffer);
        self::assertStringContainsString('Ran 1 task(s) successfully', $output->buffer);
    }

    #[Test]
    public function failedTaskReturnsErrorExitCode(): void
    {
        $registry = new JobRegistry();
        $registry->register($this->makeJob(
            'cache-clear',
            Schedule::everyMinute(),
            JobResult::failure('cache-clear', new DateTimeImmutable(), new RuntimeException('disk full')),
        ));

        $scheduler = new Scheduler($registry);
        $command = new ScheduleRunCommand($scheduler);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('schedule:run'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('1/1 task(s) failed', $output->errorBuffer);
    }

    #[Test]
    public function multipleTasksPartialFailure(): void
    {
        $registry = new JobRegistry();
        $registry->register($this->makeJob(
            'task-a',
            Schedule::everyMinute(),
            JobResult::success('task-a', new DateTimeImmutable()),
        ));
        $registry->register($this->makeJob(
            'task-b',
            Schedule::everyMinute(),
            JobResult::failure('task-b', new DateTimeImmutable(), new RuntimeException('timeout')),
        ));

        $scheduler = new Scheduler($registry);
        $command = new ScheduleRunCommand($scheduler);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('schedule:run'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('task-a', $output->buffer);
        self::assertStringContainsString('task-b', $output->buffer);
        self::assertStringContainsString('1/2 task(s) failed', $output->errorBuffer);
    }

    private function makeJob(string $name, Schedule $schedule, JobResult $result): JobInterface
    {
        $job = $this->createStub(JobInterface::class);
        $job->method('getName')->willReturn($name);
        $job->method('getSchedule')->willReturn($schedule);
        $job->method('getDescription')->willReturn($name);
        $job->method('execute')->willReturn($result);

        return $job;
    }
}
