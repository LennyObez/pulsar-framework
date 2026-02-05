<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Command\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Studio\Command\Console\ConsoleQueryCommand;
use Pulsar\Studio\Console\Storage\EventStoreInterface;

#[CoversClass(ConsoleQueryCommand::class)]
final class ConsoleQueryCommandTest extends TestCase
{
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $command = new ConsoleQueryCommand($store);

        self::assertSame('studio:console:query', $command->name);
        self::assertSame('Query Studio events', $command->description);
        self::assertArrayHasKey('type', $command->options);
        self::assertArrayHasKey('request-id', $command->options);
        self::assertArrayHasKey('job-id', $command->options);
        self::assertArrayHasKey('trace-id', $command->options);
        self::assertArrayHasKey('limit', $command->options);
        self::assertArrayHasKey('offset', $command->options);
        self::assertArrayHasKey('json', $command->options);
    }

    #[Test]
    public function executeWithNoFiltersReturnsEvents(): void
    {
        $events = [
            [
                'event_id' => 'evt-123456789012345678901234',
                'event_type' => 'http.request',
                'timestamp_us' => 1704067200000000, // 2024-01-01 00:00:00
            ],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);
        $store->method('count')->willReturn(1);

        $command = new ConsoleQueryCommand($store);
        $input = new ArrayInput('studio:console:query');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Found 1 events', $this->output->buffer);
        self::assertStringContainsString('http.request', $this->output->buffer);
    }

    #[Test]
    public function executeWithJsonOutputReturnsValidJson(): void
    {
        $events = [
            [
                'event_id' => 'evt-abc',
                'event_type' => 'http.request',
                'timestamp_us' => 1704067200000000,
            ],
            [
                'event_id' => 'evt-def',
                'event_type' => 'http.response',
                'timestamp_us' => 1704067201000000,
            ],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);
        $store->method('count')->willReturn(10);

        $command = new ConsoleQueryCommand($store);
        $input = new ArrayInput('studio:console:query', [], ['json' => true]);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);

        /** @var array{events: list<array<string, mixed>>, total: int, limit: int, offset: int} $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertCount(2, $json['events']);
        self::assertSame(10, $json['total']);
        self::assertSame(50, $json['limit']);
        self::assertSame(0, $json['offset']);
    }

    #[Test]
    public function executeWithTypeFilter(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with(
                ['event_type' => ['http.request', 'http.response']],
                50,
                0,
            )
            ->willReturn([]);
        $store->method('count')->willReturn(0);

        $command = new ConsoleQueryCommand($store);
        $input = new ArrayInput('studio:console:query', [], ['type' => 'http.request,http.response']);

        $command->execute($input, $this->output);
    }

    #[Test]
    public function executeWithRequestIdFilter(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with(
                ['request_id' => 'req-12345'],
                50,
                0,
            )
            ->willReturn([]);
        $store->method('count')->willReturn(0);

        $command = new ConsoleQueryCommand($store);
        $input = new ArrayInput('studio:console:query', [], ['request-id' => 'req-12345']);

        $command->execute($input, $this->output);
    }

    #[Test]
    public function executeWithJobIdFilter(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with(
                ['job_id' => 'job-abc'],
                50,
                0,
            )
            ->willReturn([]);
        $store->method('count')->willReturn(0);

        $command = new ConsoleQueryCommand($store);
        $input = new ArrayInput('studio:console:query', [], ['job-id' => 'job-abc']);

        $command->execute($input, $this->output);
    }

    #[Test]
    public function executeWithTraceIdFilter(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with(
                ['trace_id' => 'trace-xyz'],
                50,
                0,
            )
            ->willReturn([]);
        $store->method('count')->willReturn(0);

        $command = new ConsoleQueryCommand($store);
        $input = new ArrayInput('studio:console:query', [], ['trace-id' => 'trace-xyz']);

        $command->execute($input, $this->output);
    }

    #[Test]
    public function executeWithCustomLimitAndOffset(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with(
                [],
                25,
                10,
            )
            ->willReturn([]);
        $store->method('count')->willReturn(0);

        $command = new ConsoleQueryCommand($store);
        $input = new ArrayInput('studio:console:query', [], ['limit' => '25', 'offset' => '10']);

        $command->execute($input, $this->output);
    }

    #[Test]
    public function executeWithMultipleFilters(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with(
                [
                    'event_type' => ['http.request'],
                    'request_id' => 'req-123',
                    'trace_id' => 'trace-abc',
                ],
                100,
                50,
            )
            ->willReturn([]);
        $store->method('count')->willReturn(0);

        $command = new ConsoleQueryCommand($store);
        $input = new ArrayInput('studio:console:query', [], [
            'type' => 'http.request',
            'request-id' => 'req-123',
            'trace-id' => 'trace-abc',
            'limit' => '100',
            'offset' => '50',
        ]);

        $command->execute($input, $this->output);
    }

    #[Test]
    public function executeHandlesNonNumericLimitGracefully(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with([], 50, 0) // Falls back to default 50
            ->willReturn([]);
        $store->method('count')->willReturn(0);

        $command = new ConsoleQueryCommand($store);
        $input = new ArrayInput('studio:console:query', [], ['limit' => 'invalid']);

        $command->execute($input, $this->output);
    }

    #[Test]
    public function executeHandlesNonNumericOffsetGracefully(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with([], 50, 0) // Falls back to default 0
            ->willReturn([]);
        $store->method('count')->willReturn(0);

        $command = new ConsoleQueryCommand($store);
        $input = new ArrayInput('studio:console:query', [], ['offset' => 'invalid']);

        $command->execute($input, $this->output);
    }

    #[Test]
    public function executeHandlesUnknownEventType(): void
    {
        $events = [
            [
                'event_id' => 'evt-123',
                'event_type' => null, // Will be converted to 'unknown'
                'timestamp_us' => 1704067200000000,
            ],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);
        $store->method('count')->willReturn(1);

        $command = new ConsoleQueryCommand($store);
        $input = new ArrayInput('studio:console:query');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('unknown', $this->output->buffer);
    }

    #[Test]
    public function executeHandlesNonStringEventId(): void
    {
        $events = [
            [
                'event_id' => 12345, // Non-string event_id
                'event_type' => 'http.request',
                'timestamp_us' => 1704067200000000,
            ],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);
        $store->method('count')->willReturn(1);

        $command = new ConsoleQueryCommand($store);
        $input = new ArrayInput('studio:console:query');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        // Non-string event_id should be handled gracefully (converted to empty string)
        self::assertStringContainsString('http.request', $this->output->buffer);
    }

    #[Test]
    public function executeHandlesNonIntTimestamp(): void
    {
        $events = [
            [
                'event_id' => 'evt-123',
                'event_type' => 'http.request',
                'timestamp_us' => 'not-a-number', // Non-int timestamp
            ],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);
        $store->method('count')->willReturn(1);

        $command = new ConsoleQueryCommand($store);
        $input = new ArrayInput('studio:console:query');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        // Should handle gracefully and use 0 as timestamp
        self::assertStringContainsString('1970-01-01', $this->output->buffer);
    }

    #[Test]
    public function executeHandlesNumericStringTimestamp(): void
    {
        $events = [
            [
                'event_id' => 'evt-123',
                'event_type' => 'http.request',
                'timestamp_us' => '1704067200000000', // Numeric string timestamp
            ],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);
        $store->method('count')->willReturn(1);

        $command = new ConsoleQueryCommand($store);
        $input = new ArrayInput('studio:console:query');

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        // Should handle numeric string correctly
        self::assertStringContainsString('2024-01-01', $this->output->buffer);
    }

    #[Test]
    public function executeShowsCorrectPaginationInfo(): void
    {
        $events = [
            ['event_id' => 'evt-1', 'event_type' => 'http.request', 'timestamp_us' => 1704067200000000],
            ['event_id' => 'evt-2', 'event_type' => 'http.request', 'timestamp_us' => 1704067201000000],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);
        $store->method('count')->willReturn(100);

        $command = new ConsoleQueryCommand($store);
        $input = new ArrayInput('studio:console:query', [], ['offset' => '10', 'limit' => '50']);

        $exit = $command->execute($input, $this->output);

        self::assertSame(ExitCode::Success->value, $exit);
        // Shows "showing 11-12 of 100" (offset 10, 2 results)
        self::assertStringContainsString('showing 11-12 of 100', $this->output->buffer);
    }

    #[Test]
    public function executeWithNonStringTypeFilter(): void
    {
        // When type filter is not a string, it should use empty array
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with(
                ['event_type' => []], // Empty array when type is not string
                50,
                0,
            )
            ->willReturn([]);
        $store->method('count')->willReturn(0);

        $command = new ConsoleQueryCommand($store);
        // Simulate a non-string option value (e.g., boolean true when just --type is passed)
        $input = new ArrayInput('studio:console:query', [], ['type' => true]);

        $command->execute($input, $this->output);
    }
}
