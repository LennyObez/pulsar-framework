<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Command\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Extension\Studio\Command\Console\ConsoleJobsCommand;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(ConsoleJobsCommand::class)]
final class ConsoleJobsCommandTest extends TestCase
{
    #[Test]
    public function configuredCorrectly(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $command = new ConsoleJobsCommand($store);

        self::assertSame('studio:console:jobs', $command->name);
        self::assertArrayHasKey('json', $command->options);
        self::assertArrayHasKey('limit', $command->options);
    }

    #[Test]
    public function noJobEventsShowsEmptyMessage(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $command = new ConsoleJobsCommand($store);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:console:jobs'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Job Activity', $output->buffer);
        self::assertStringContainsString('No job events recorded', $output->buffer);
    }

    #[Test]
    public function displaysJobEvents(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([
            [
                'timestamp' => '2026-02-25T10:30:00',
                'type' => 'job.completed',
                'payload' => ['job_class' => 'App\\Jobs\\SendEmail'],
            ],
            [
                'timestamp' => '2026-02-25T10:31:00',
                'type' => 'job.failed',
                'payload' => ['job_class' => 'App\\Jobs\\ProcessPayment'],
            ],
        ]);

        $command = new ConsoleJobsCommand($store);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:console:jobs'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Job Activity', $output->buffer);
        self::assertStringContainsString('job.completed', $output->buffer);
        self::assertStringContainsString('App\\Jobs\\SendEmail', $output->buffer);
        self::assertStringContainsString('job.failed', $output->buffer);
        self::assertStringContainsString('App\\Jobs\\ProcessPayment', $output->buffer);
        self::assertStringContainsString('Total: 2 events', $output->buffer);
    }

    #[Test]
    public function jsonOutput(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([
            [
                'timestamp' => '2026-02-25T10:30:00',
                'type' => 'job.queued',
                'payload' => ['job_class' => 'App\\Jobs\\SendEmail'],
            ],
        ]);

        $command = new ConsoleJobsCommand($store);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('studio:console:jobs', [], ['json' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{command: string, success: bool, data: array{events: list<mixed>, count: int}} $decoded */
        $decoded = json_decode($output->buffer, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('studio:console:jobs', $decoded['command']);
        self::assertTrue($decoded['success']);
        self::assertSame(1, $decoded['data']['count']);
    }

    #[Test]
    public function missingPayloadUsesDefaultJobClass(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([
            [
                'timestamp' => '2026-02-25T10:30:00',
                'type' => 'job.processing',
                'payload' => [],
            ],
        ]);

        $command = new ConsoleJobsCommand($store);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:console:jobs'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('unknown', $output->buffer);
    }

    #[Test]
    public function customLimitOption(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $command = new ConsoleJobsCommand($store);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('studio:console:jobs', [], ['limit' => '10']),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('No job events recorded', $output->buffer);
    }
}
