<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\QueueFlushCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Queue\QueueDriverInterface;

#[CoversClass(QueueFlushCommand::class)]
final class QueueFlushCommandTest extends TestCase
{
    #[Test]
    public function configuredCorrectly(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $command = new QueueFlushCommand($driver);

        self::assertSame('queue:flush', $command->name);
    }

    #[Test]
    public function emptyQueue(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('purge')->willReturn(0);

        $command = new QueueFlushCommand($driver);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('queue:flush', ['default']), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('already empty', $output->buffer);
    }

    #[Test]
    public function purgesJobs(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('purge')->willReturn(5);

        $command = new QueueFlushCommand($driver);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('queue:flush', ['emails']), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Purged 5 job(s)', $output->buffer);
        self::assertStringContainsString('emails', $output->buffer);
    }
}
