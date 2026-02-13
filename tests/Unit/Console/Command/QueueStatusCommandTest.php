<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\QueueConfig;
use Pulsar\Console\Command\QueueStatusCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Queue\DeadLetterQueue;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Queue\QueueManager;

use function json_decode;
use function time;

use const JSON_THROW_ON_ERROR;

#[CoversClass(QueueStatusCommand::class)]
final class QueueStatusCommandTest extends TestCase
{
    #[Test]
    public function configuredCorrectly(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $manager = new QueueManager(new QueueConfig(), $driver);
        $dlq = new DeadLetterQueue($driver);
        $command = new QueueStatusCommand($manager, $dlq);

        self::assertSame('queue:status', $command->name);
    }

    #[Test]
    public function textOutput(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('size')->willReturn(42);

        $manager = new QueueManager(new QueueConfig(), $driver);
        $dlq = new DeadLetterQueue($driver);

        $dlq->store(
            new JobRecord(
                id: 'fail-1',
                queue: 'default',
                jobClass: 'App\\Jobs\\SendEmail',
                payload: '{}',
                attempts: 1,
                status: JobRecordStatus::Failed,
                createdAt: time(),
                availableAt: time(),
            ),
            'Error',
        );

        $command = new QueueStatusCommand($manager, $dlq);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('queue:status'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Queue Status', $output->buffer);
        self::assertStringContainsString('Pending jobs:  42', $output->buffer);
        self::assertStringContainsString('Failed jobs:   1', $output->buffer);
    }

    #[Test]
    public function jsonOutput(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('size')->willReturn(10);

        $manager = new QueueManager(new QueueConfig(), $driver);
        $dlq = new DeadLetterQueue($driver);

        $command = new QueueStatusCommand($manager, $dlq);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('queue:status', [], ['json' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{pending: int, failed: int} $data */
        $data = json_decode($output->buffer, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(10, $data['pending']);
        self::assertSame(0, $data['failed']);
    }
}
