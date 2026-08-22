<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\QueueWorkCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Queue\Worker;
use Pulsar\Queue\WorkerFactoryInterface;
use Pulsar\Queue\WorkerOptions;

#[CoversClass(QueueWorkCommand::class)]
final class QueueWorkCommandTest extends TestCase
{
    #[Test]
    public function configuredCorrectly(): void
    {
        $command = new QueueWorkCommand($this->factory());

        self::assertSame('queue:work', $command->name);
        self::assertArrayHasKey('max-jobs', $command->options);
        self::assertArrayHasKey('memory', $command->options);
        self::assertArrayHasKey('timeout', $command->options);
        self::assertArrayHasKey('sleep', $command->options);
    }

    #[Test]
    public function startsWorkerWithOptions(): void
    {
        $command = new QueueWorkCommand($this->factory());
        $output = new BufferedOutput();

        $exit = $command->execute($this->input(), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Starting worker', $output->buffer);
        self::assertStringContainsString('high-priority', $output->buffer);
        self::assertStringContainsString('Worker stopped gracefully', $output->buffer);
    }

    #[Test]
    public function theWorkerIsBuiltByTheFactoryRatherThanByTheCommand(): void
    {
        // The defect this guards: the command used to construct its own Worker
        // from a driver and a logger, leaving the dead-letter queue, the retry
        // policy and the execution pipeline unset. The pipeline is where payload
        // decryption runs, so an encrypted job reached its handler as ciphertext.
        // The command now has no driver to build one from, and this proves it
        // asks the composition root instead.
        $factory = $this->factory();

        new QueueWorkCommand($factory)->execute($this->input(), new BufferedOutput());

        self::assertSame(1, $factory->creations);
    }

    #[Test]
    public function theCommandLineOptionsReachTheWorkerItRuns(): void
    {
        $factory = $this->factory();

        new QueueWorkCommand($factory)->execute($this->input(), new BufferedOutput());

        self::assertNotNull($factory->lastOptions);
        self::assertSame(1, $factory->lastOptions->maxJobs);
        self::assertSame(64, $factory->lastOptions->maxMemoryMb);
        self::assertSame(0, $factory->lastOptions->timeLimitSeconds);
    }

    private function input(): ArrayInput
    {
        return new ArrayInput('queue:work', ['high-priority'], [
            'max-jobs' => '1',
            'memory' => '64',
            'timeout' => '0',
            'sleep' => '0',
        ]);
    }

    private function factory(): RecordingWorkerFactory
    {
        $driver = $this->createStub(QueueDriverInterface::class);
        $driver->method('pop')->willReturn(null);

        return new RecordingWorkerFactory($driver);
    }
}

/**
 * Answers like the real factory and records what it was asked for.
 */
final class RecordingWorkerFactory implements WorkerFactoryInterface
{
    public int $creations = 0;

    public ?WorkerOptions $lastOptions = null;

    public function __construct(private readonly QueueDriverInterface $driver) {}

    #[Override]
    public function create(WorkerOptions $options): Worker
    {
        $this->creations++;
        $this->lastOptions = $options;

        return new Worker($this->driver, $options);
    }
}
