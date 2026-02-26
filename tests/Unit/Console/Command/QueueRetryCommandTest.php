<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\QueueRetryCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Queue\DeadLetterQueue;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueDriverInterface;

use function time;

#[CoversClass(QueueRetryCommand::class)]
final class QueueRetryCommandTest extends TestCase
{
    #[Test]
    public function configuredCorrectly(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $dlq = new DeadLetterQueue($driver);
        $command = new QueueRetryCommand($dlq);

        self::assertSame('queue:retry', $command->name);
    }

    #[Test]
    public function emptyIdReturnsError(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $dlq = new DeadLetterQueue($driver);
        $command = new QueueRetryCommand($dlq);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('queue:retry', ['']), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('required', $output->errorBuffer);
    }

    #[Test]
    public function retryAllNoJobs(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $dlq = new DeadLetterQueue($driver);
        $command = new QueueRetryCommand($dlq);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('queue:retry', ['all']), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('No failed jobs to retry', $output->buffer);
    }

    #[Test]
    public function retryAllWithJobs(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('push')->willReturn('new-id');
        $dlq = new DeadLetterQueue($driver);

        $dlq->store(
            new JobRecord(
                id: 'job-001',
                queue: 'default',
                jobClass: 'App\\Jobs\\SendEmail',
                payload: '{}',
                attempts: 1,
                status: JobRecordStatus::Failed,
                createdAt: time(),
                availableAt: time(),
            ),
            'Connection error',
        );
        $dlq->store(
            new JobRecord(
                id: 'job-002',
                queue: 'default',
                jobClass: 'App\\Jobs\\ProcessPayment',
                payload: '{}',
                attempts: 2,
                status: JobRecordStatus::Failed,
                createdAt: time(),
                availableAt: time(),
            ),
            'Timeout',
        );

        $command = new QueueRetryCommand($dlq);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('queue:retry', ['all']), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Retried 2 failed job(s)', $output->buffer);
    }

    #[Test]
    public function retrySingleSuccess(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('push')->willReturn('new-id');
        $dlq = new DeadLetterQueue($driver);

        $dlq->store(
            new JobRecord(
                id: 'job-abc',
                queue: 'default',
                jobClass: 'App\\Jobs\\SendEmail',
                payload: '{}',
                attempts: 1,
                status: JobRecordStatus::Failed,
                createdAt: time(),
                availableAt: time(),
            ),
            'Connection error',
        );

        $command = new QueueRetryCommand($dlq);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('queue:retry', ['job-abc']), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('job-abc', $output->buffer);
        self::assertStringContainsString('re-dispatched', $output->buffer);
    }

    #[Test]
    public function retrySingleNotFound(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $dlq = new DeadLetterQueue($driver);
        $command = new QueueRetryCommand($dlq);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('queue:retry', ['nonexistent-id']), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('nonexistent-id', $output->errorBuffer);
    }
}
