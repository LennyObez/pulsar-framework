<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\QueueWorkCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Queue\QueueDriverInterface;

#[CoversClass(QueueWorkCommand::class)]
final class QueueWorkCommandTest extends TestCase
{
    #[Test]
    public function configuredCorrectly(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $command = new QueueWorkCommand($driver);

        self::assertSame('queue:work', $command->name);
        self::assertArrayHasKey('max-jobs', $command->options);
        self::assertArrayHasKey('memory', $command->options);
        self::assertArrayHasKey('timeout', $command->options);
        self::assertArrayHasKey('sleep', $command->options);
    }

    #[Test]
    public function startsWorkerWithOptions(): void
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('pop')->willReturn(null);

        $command = new QueueWorkCommand($driver);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('queue:work', ['high-priority'], [
                'max-jobs' => '1',
                'memory' => '64',
                'timeout' => '0',
                'sleep' => '0',
            ]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Starting worker', $output->buffer);
        self::assertStringContainsString('high-priority', $output->buffer);
        self::assertStringContainsString('Worker stopped gracefully', $output->buffer);
    }
}
