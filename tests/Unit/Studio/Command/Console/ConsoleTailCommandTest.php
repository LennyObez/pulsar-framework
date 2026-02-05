<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Command\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Studio\Command\Console\ConsoleTailCommand;
use Pulsar\Studio\Console\Storage\EventStoreInterface;
use ReflectionMethod;

#[CoversClass(ConsoleTailCommand::class)]
final class ConsoleTailCommandTest extends TestCase
{
    /** @var EventStoreInterface&Stub */
    private EventStoreInterface $store;
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->store = $this->createStub(EventStoreInterface::class);
        $this->output = new BufferedOutput();
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $command = new ConsoleTailCommand($this->store);

        self::assertSame('studio:console:tail', $command->name);
        self::assertSame('Stream Studio events in real-time', $command->description);
        self::assertArrayHasKey('type', $command->options);
        self::assertArrayHasKey('filter', $command->options);
        self::assertArrayHasKey('json', $command->options);
        self::assertArrayHasKey('lines', $command->options);
    }

    #[Test]
    public function outputEventFormatsTextCorrectly(): void
    {
        $command = new ConsoleTailCommand($this->store);
        $method = new ReflectionMethod($command, 'outputEvent');

        $event = [
            'id' => 1,
            'event_id' => 'evt-12345678901234567890',
            'event_type' => 'http.request',
            'timestamp_us' => 1704067200000000, // 2024-01-01 00:00:00 UTC
            'request_id' => 'req-abcdef123456',
        ];

        $method->invoke($command, $event, false, $this->output);

        $buffer = $this->output->buffer;
        self::assertStringContainsString('http.request', $buffer);
        self::assertStringContainsString('req=req-abcdef12', $buffer); // Truncated to 12 chars
    }

    #[Test]
    public function outputEventFormatsJsonCorrectly(): void
    {
        $command = new ConsoleTailCommand($this->store);
        $method = new ReflectionMethod($command, 'outputEvent');

        $event = [
            'id' => 1,
            'event_id' => 'evt-abc',
            'event_type' => 'http.request',
            'timestamp_us' => 1704067200000000,
            'request_id' => 'req-123',
        ];

        $method->invoke($command, $event, true, $this->output);

        /** @var array<string, mixed> $json */
        $json = json_decode(trim($this->output->buffer), true);
        self::assertIsArray($json);
        self::assertSame('evt-abc', $json['event_id']);
        self::assertSame('http.request', $json['event_type']);
        self::assertSame(1704067200000000, $json['timestamp_us']);
    }

    #[Test]
    public function outputEventHandlesUnknownEventType(): void
    {
        $command = new ConsoleTailCommand($this->store);
        $method = new ReflectionMethod($command, 'outputEvent');

        $event = [
            'id' => 1,
            'event_id' => 'evt-abc',
            'event_type' => null, // Will be converted to 'unknown'
            'timestamp_us' => 1704067200000000,
            'request_id' => '',
        ];

        $method->invoke($command, $event, false, $this->output);

        self::assertStringContainsString('unknown', $this->output->buffer);
    }

    #[Test]
    public function outputEventHandlesNonStringEventType(): void
    {
        $command = new ConsoleTailCommand($this->store);
        $method = new ReflectionMethod($command, 'outputEvent');

        $event = [
            'id' => 1,
            'event_id' => 'evt-abc',
            'event_type' => 12345, // Non-string
            'timestamp_us' => 1704067200000000,
            'request_id' => '',
        ];

        $method->invoke($command, $event, false, $this->output);

        self::assertStringContainsString('unknown', $this->output->buffer);
    }

    #[Test]
    public function outputEventHandlesNonStringEventId(): void
    {
        $command = new ConsoleTailCommand($this->store);
        $method = new ReflectionMethod($command, 'outputEvent');

        $event = [
            'id' => 1,
            'event_id' => 12345, // Non-string event_id
            'event_type' => 'http.request',
            'timestamp_us' => 1704067200000000,
            'request_id' => '',
        ];

        $method->invoke($command, $event, false, $this->output);

        // Should handle gracefully
        self::assertStringContainsString('http.request', $this->output->buffer);
    }

    #[Test]
    public function outputEventHandlesNonIntTimestamp(): void
    {
        $command = new ConsoleTailCommand($this->store);
        $method = new ReflectionMethod($command, 'outputEvent');

        $event = [
            'id' => 1,
            'event_id' => 'evt-abc',
            'event_type' => 'http.request',
            'timestamp_us' => 'not-a-number', // Non-int
            'request_id' => '',
        ];

        $method->invoke($command, $event, false, $this->output);

        // Should handle gracefully using 0 timestamp
        self::assertStringContainsString('http.request', $this->output->buffer);
    }

    #[Test]
    public function outputEventHandlesNumericStringTimestamp(): void
    {
        $command = new ConsoleTailCommand($this->store);
        $method = new ReflectionMethod($command, 'outputEvent');

        $event = [
            'id' => 1,
            'event_id' => 'evt-abc',
            'event_type' => 'http.request',
            'timestamp_us' => '1704067200000000', // Numeric string
            'request_id' => '',
        ];

        $method->invoke($command, $event, false, $this->output);

        // Should handle numeric string correctly
        self::assertStringContainsString('http.request', $this->output->buffer);
    }

    #[Test]
    public function outputEventHandlesEmptyRequestId(): void
    {
        $command = new ConsoleTailCommand($this->store);
        $method = new ReflectionMethod($command, 'outputEvent');

        $event = [
            'id' => 1,
            'event_id' => 'evt-abc',
            'event_type' => 'http.request',
            'timestamp_us' => 1704067200000000,
            'request_id' => '',
        ];

        $method->invoke($command, $event, false, $this->output);

        // Should not contain "req=" when request_id is empty
        self::assertStringNotContainsString('req=', $this->output->buffer);
    }

    #[Test]
    public function outputEventHandlesNonStringRequestId(): void
    {
        $command = new ConsoleTailCommand($this->store);
        $method = new ReflectionMethod($command, 'outputEvent');

        $event = [
            'id' => 1,
            'event_id' => 'evt-abc',
            'event_type' => 'http.request',
            'timestamp_us' => 1704067200000000,
            'request_id' => 12345, // Non-string
        ];

        $method->invoke($command, $event, false, $this->output);

        // Non-string request_id should be treated as empty
        self::assertStringNotContainsString('req=', $this->output->buffer);
    }

    #[Test]
    public function outputEventHandlesMissingFields(): void
    {
        $command = new ConsoleTailCommand($this->store);
        $method = new ReflectionMethod($command, 'outputEvent');

        $event = [
            'id' => 1,
            // Missing event_id, event_type, timestamp_us, request_id
        ];

        $method->invoke($command, $event, false, $this->output);

        // Should handle gracefully with defaults
        self::assertStringContainsString('unknown', $this->output->buffer);
    }

    #[Test]
    public function linesOptionConfiguredWithDefaultTwenty(): void
    {
        // Since execute() has an infinite loop, we test configuration instead
        $command = new ConsoleTailCommand($this->store);

        // Verify lines option is configured with default 20
        self::assertSame('20', $command->options['lines']['default']);
        self::assertSame('Number of past events to show', $command->options['lines']['description']);
    }

    #[Test]
    public function filterOptionsConfiguredCorrectly(): void
    {
        // Since execute() has an infinite loop, we test configuration instead
        $command = new ConsoleTailCommand($this->store);

        // Verify filter options are configured
        self::assertArrayHasKey('type', $command->options);
        self::assertArrayHasKey('filter', $command->options);
        self::assertSame('t', $command->options['type']['shortcut']);
        self::assertSame('f', $command->options['filter']['shortcut']);
        self::assertSame('Filter by event type (comma-separated)', $command->options['type']['description']);
        self::assertSame('Filter by correlation ID', $command->options['filter']['description']);
    }

    #[Test]
    public function outputEventHandlesIntIdInEvent(): void
    {
        $command = new ConsoleTailCommand($this->store);
        $method = new ReflectionMethod($command, 'outputEvent');

        $event = [
            'id' => 42, // Integer id
            'event_id' => 'evt-abc',
            'event_type' => 'http.request',
            'timestamp_us' => 1704067200000000,
            'request_id' => 'req-123',
        ];

        $method->invoke($command, $event, false, $this->output);

        self::assertStringContainsString('http.request', $this->output->buffer);
    }

    #[Test]
    public function outputEventHandlesNumericStringIdInEvent(): void
    {
        $command = new ConsoleTailCommand($this->store);
        $method = new ReflectionMethod($command, 'outputEvent');

        $event = [
            'id' => '42', // Numeric string id
            'event_id' => 'evt-abc',
            'event_type' => 'http.request',
            'timestamp_us' => 1704067200000000,
            'request_id' => 'req-123',
        ];

        $method->invoke($command, $event, false, $this->output);

        self::assertStringContainsString('http.request', $this->output->buffer);
    }

    #[Test]
    public function outputEventHandlesNonNumericIdInEvent(): void
    {
        $command = new ConsoleTailCommand($this->store);
        $method = new ReflectionMethod($command, 'outputEvent');

        $event = [
            'id' => 'not-a-number', // Non-numeric id
            'event_id' => 'evt-abc',
            'event_type' => 'http.request',
            'timestamp_us' => 1704067200000000,
            'request_id' => 'req-123',
        ];

        $method->invoke($command, $event, false, $this->output);

        // Should handle gracefully
        self::assertStringContainsString('http.request', $this->output->buffer);
    }

    #[Test]
    public function outputEventHandlesMissingIdInEvent(): void
    {
        $command = new ConsoleTailCommand($this->store);
        $method = new ReflectionMethod($command, 'outputEvent');

        $event = [
            // Missing 'id'
            'event_id' => 'evt-abc',
            'event_type' => 'http.request',
            'timestamp_us' => 1704067200000000,
            'request_id' => 'req-123',
        ];

        $method->invoke($command, $event, false, $this->output);

        // Should handle gracefully
        self::assertStringContainsString('http.request', $this->output->buffer);
    }

    #[Test]
    public function linesOptionDefaultsToTwenty(): void
    {
        $command = new ConsoleTailCommand($this->store);

        self::assertSame('20', $command->options['lines']['default']);
    }

    #[Test]
    public function typeOptionHasShortcutT(): void
    {
        $command = new ConsoleTailCommand($this->store);

        self::assertSame('t', $command->options['type']['shortcut']);
    }

    #[Test]
    public function filterOptionHasShortcutF(): void
    {
        $command = new ConsoleTailCommand($this->store);

        self::assertSame('f', $command->options['filter']['shortcut']);
    }

    #[Test]
    public function jsonOptionHasShortcutJ(): void
    {
        $command = new ConsoleTailCommand($this->store);

        self::assertSame('j', $command->options['json']['shortcut']);
    }

    #[Test]
    public function linesOptionHasShortcutN(): void
    {
        $command = new ConsoleTailCommand($this->store);

        self::assertSame('n', $command->options['lines']['shortcut']);
    }
}
