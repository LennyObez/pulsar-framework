<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\SchedulerTickCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Scheduler\JobInterface;
use Pulsar\Scheduler\JobRegistry;
use Pulsar\Scheduler\JobResult;
use Pulsar\Scheduler\Schedule;
use Pulsar\Scheduler\Scheduler;
use RuntimeException;

#[CoversClass(SchedulerTickCommand::class)]
final class SchedulerTickCommandTest extends TestCase
{
    #[Test]
    public function noDueJobs(): void
    {
        $scheduler = new Scheduler(new JobRegistry());
        $command = new SchedulerTickCommand($scheduler);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('scheduler:tick'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('No jobs due', $output->buffer);
    }

    #[Test]
    public function successfulJobExecution(): void
    {
        $registry = new JobRegistry();
        $registry->register($this->makeJob('cleanup', Schedule::everyMinute(), JobResult::success('cleanup', new DateTimeImmutable())));

        $scheduler = new Scheduler($registry);
        $command = new SchedulerTickCommand($scheduler);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('scheduler:tick'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('cleanup', $output->buffer);
        self::assertStringContainsString('Ran 1 job(s)', $output->buffer);
    }

    #[Test]
    public function failedJobReturnsError(): void
    {
        $registry = new JobRegistry();
        $registry->register($this->makeJob(
            'cleanup',
            Schedule::everyMinute(),
            JobResult::failure('cleanup', new DateTimeImmutable(), new RuntimeException('failed')),
        ));

        $scheduler = new Scheduler($registry);
        $command = new SchedulerTickCommand($scheduler);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('scheduler:tick'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('1/1 job(s) failed', $output->errorBuffer);
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $scheduler = new Scheduler(new JobRegistry());
        $command = new SchedulerTickCommand($scheduler);

        self::assertSame('scheduler:tick', $command->name);
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
