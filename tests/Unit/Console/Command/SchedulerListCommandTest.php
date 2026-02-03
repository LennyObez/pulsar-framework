<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\SchedulerListCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Scheduler\JobInterface;
use Pulsar\Scheduler\JobRegistry;
use Pulsar\Scheduler\Schedule;

#[CoversClass(SchedulerListCommand::class)]
final class SchedulerListCommandTest extends TestCase
{
    #[Test]
    public function listsJobs(): void
    {
        $job = $this->createStub(JobInterface::class);
        $job->method('getName')->willReturn('cleanup');
        $job->method('getSchedule')->willReturn(new Schedule('*/5 * * * *'));
        $job->method('getDescription')->willReturn('Clean up old records');

        $registry = new JobRegistry();
        $registry->register($job);

        $command = new SchedulerListCommand($registry);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('scheduler:list'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('cleanup', $output->buffer);
        self::assertStringContainsString('*/5 * * * *', $output->buffer);
        self::assertStringContainsString('Clean up old records', $output->buffer);
    }

    #[Test]
    public function noJobsRegistered(): void
    {
        $registry = new JobRegistry();

        $command = new SchedulerListCommand($registry);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('scheduler:list'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('No scheduled jobs', $output->buffer);
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $command = new SchedulerListCommand(new JobRegistry());

        self::assertSame('scheduler:list', $command->name);
    }
}
