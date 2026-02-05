<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\QueueFailedCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Queue\DeadLetterQueue;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueDriverInterface;

use function json_decode;
use function time;

use const JSON_THROW_ON_ERROR;

#[CoversClass(QueueFailedCommand::class)]
final class QueueFailedCommandTest extends TestCase
{
    #[Test]
    public function configuredCorrectly(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $dlq = new DeadLetterQueue($driver);
        $command = new QueueFailedCommand($dlq);

        self::assertSame('queue:failed', $command->name);
    }

    #[Test]
    public function noFailedJobs(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $dlq = new DeadLetterQueue($driver);
        $command = new QueueFailedCommand($dlq);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('queue:failed'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('No failed jobs', $output->buffer);
    }

    #[Test]
    public function tableOutput(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $dlq = new DeadLetterQueue($driver);

        $record = new JobRecord(
            id: 'job-001',
            queue: 'default',
            jobClass: 'App\\Jobs\\SendEmail',
            payload: '{}',
            attempts: 3,
            status: JobRecordStatus::Failed,
            createdAt: time(),
            availableAt: time(),
        );
        $dlq->store($record, 'Connection timed out');

        $command = new QueueFailedCommand($dlq);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('queue:failed'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Failed Jobs (1)', $output->buffer);
        self::assertStringContainsString('job-001', $output->buffer);
        self::assertStringContainsString('default', $output->buffer);
        self::assertStringContainsString('App\\Jobs\\SendEmail', $output->buffer);
        self::assertStringContainsString('Connection timed out', $output->buffer);
    }

    #[Test]
    public function jsonOutput(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $dlq = new DeadLetterQueue($driver);

        $record = new JobRecord(
            id: 'job-002',
            queue: 'emails',
            jobClass: 'App\\Jobs\\ProcessPayment',
            payload: '{"amount":100}',
            attempts: 2,
            status: JobRecordStatus::Failed,
            createdAt: time(),
            availableAt: time(),
        );
        $dlq->store($record, 'Insufficient funds');

        $command = new QueueFailedCommand($dlq);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('queue:failed', [], ['json' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var list<array{id: string, queue: string, job_class: string, exception: string, attempts: int}> $data */
        $data = json_decode($output->buffer, true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $data);
        self::assertSame('job-002', $data[0]['id']);
        self::assertSame('emails', $data[0]['queue']);
        self::assertSame('App\\Jobs\\ProcessPayment', $data[0]['job_class']);
        self::assertSame('Insufficient funds', $data[0]['exception']);
        self::assertSame(2, $data[0]['attempts']);
    }
}
