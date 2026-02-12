<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Command\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Extension\Studio\Command\Console\ConsoleQueryCommand;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(ConsoleQueryCommand::class)]
final class ConsoleQueryCommandTest extends TestCase
{
    #[Test]
    public function configuredCorrectly(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->method('count')->willReturn(0);
        $command = new ConsoleQueryCommand($store);

        self::assertSame('studio:console:query', $command->name);
        self::assertArrayHasKey('json', $command->options);
        self::assertArrayHasKey('type', $command->options);
        self::assertArrayHasKey('limit', $command->options);
        self::assertArrayHasKey('offset', $command->options);
    }

    #[Test]
    public function noEventsShowsZeroCount(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->method('count')->willReturn(0);

        $command = new ConsoleQueryCommand($store);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:console:query'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Found 0 events', $output->buffer);
    }

    #[Test]
    public function jsonOutputWithEvents(): void
    {
        $events = [
            [
                'event_id' => 'abc123',
                'event_type' => 'http.request',
                'timestamp_us' => 1708872600000000,
            ],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);
        $store->method('count')->willReturn(1);

        $command = new ConsoleQueryCommand($store);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('studio:console:query', [], ['json' => true]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{events: list<mixed>, total: int, limit: int, offset: int} $decoded */
        $decoded = json_decode($output->buffer, true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $decoded['events']);
        self::assertSame(1, $decoded['total']);
        self::assertSame(50, $decoded['limit']);
        self::assertSame(0, $decoded['offset']);
    }

    #[Test]
    public function jsonOutputWithCustomLimitAndOffset(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->method('count')->willReturn(100);

        $command = new ConsoleQueryCommand($store);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('studio:console:query', [], [
                'json' => true,
                'limit' => '10',
                'offset' => '20',
            ]),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{events: list<mixed>, total: int, limit: int, offset: int} $decoded */
        $decoded = json_decode($output->buffer, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(10, $decoded['limit']);
        self::assertSame(20, $decoded['offset']);
        self::assertSame(100, $decoded['total']);
    }

    #[Test]
    public function textOutputDisplaysEvents(): void
    {
        $events = [
            [
                'event_id' => 'abc123def456abc123def456abc12345',
                'event_type' => 'http.request',
                'timestamp_us' => 1708872600000000,
            ],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);
        $store->method('count')->willReturn(1);

        $command = new ConsoleQueryCommand($store);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('studio:console:query'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Found 1 events', $output->buffer);
        self::assertStringContainsString('http.request', $output->buffer);
    }

    #[Test]
    public function typeFilterOption(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->method('count')->willReturn(0);

        $command = new ConsoleQueryCommand($store);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('studio:console:query', [], ['type' => 'http.request,http.response']),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Found 0 events', $output->buffer);
    }
}
