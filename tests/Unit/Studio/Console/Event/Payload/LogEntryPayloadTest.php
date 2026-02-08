<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\LogEntryPayload;

#[CoversClass(LogEntryPayload::class)]
final class LogEntryPayloadTest extends TestCase
{
    #[Test]
    public function implementsConsoleEventInterface(): void
    {
        $payload = new LogEntryPayload(
            level: 'info',
            message: 'Application started',
            channel: 'app',
            context: [],
        );

        self::assertInstanceOf(ConsoleEvent::class, $payload);
    }

    #[Test]
    public function eventTypeReturnsLogEntry(): void
    {
        $payload = new LogEntryPayload(
            level: 'info',
            message: 'Test message',
            channel: 'app',
            context: [],
        );

        self::assertSame(EventType::LogEntry, $payload->eventType());
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        $payload = new LogEntryPayload(
            level: 'debug',
            message: 'Debug message',
            channel: 'app',
            context: [],
        );

        self::assertSame(EventVersion::V1, $payload->schemaVersion());
    }

    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $context = ['user_id' => 123, 'action' => 'login'];

        $payload = new LogEntryPayload(
            level: 'warning',
            message: 'User login attempt failed',
            channel: 'security',
            context: $context,
        );

        self::assertSame('warning', $payload->level);
        self::assertSame('User login attempt failed', $payload->message);
        self::assertSame('security', $payload->channel);
        self::assertSame($context, $payload->context);
    }

    #[Test]
    public function toArrayContainsAllExpectedKeys(): void
    {
        $payload = new LogEntryPayload(
            level: 'error',
            message: 'Error occurred',
            channel: 'app',
            context: [],
        );

        $array = $payload->toArray();

        self::assertArrayHasKey('level', $array);
        self::assertArrayHasKey('message', $array);
        self::assertArrayHasKey('channel', $array);
        self::assertArrayHasKey('context', $array);
    }

    #[Test]
    public function toArrayReturnsCorrectValues(): void
    {
        $context = ['request_id' => 'abc-123', 'duration_ms' => 150.5];

        $payload = new LogEntryPayload(
            level: 'info',
            message: 'Request completed',
            channel: 'http',
            context: $context,
        );

        $array = $payload->toArray();

        self::assertSame('info', $array['level']);
        self::assertSame('Request completed', $array['message']);
        self::assertSame('http', $array['channel']);
        self::assertSame($context, $array['context']);
    }

    #[Test]
    public function toArrayHandlesEmptyContext(): void
    {
        $payload = new LogEntryPayload(
            level: 'info',
            message: 'Simple log message',
            channel: 'app',
            context: [],
        );

        $array = $payload->toArray();

        self::assertSame([], $array['context']);
    }

    #[Test]
    public function handlesAllStandardLogLevels(): void
    {
        $levels = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

        foreach ($levels as $level) {
            $payload = new LogEntryPayload(
                level: $level,
                message: "Message at {$level} level",
                channel: 'app',
                context: [],
            );

            self::assertSame($level, $payload->level);
            self::assertSame($level, $payload->toArray()['level']);
        }
    }

    #[Test]
    public function handlesVariousChannels(): void
    {
        $channels = ['app', 'http', 'database', 'security', 'scheduler', 'queue', 'mail'];

        foreach ($channels as $channel) {
            $payload = new LogEntryPayload(
                level: 'info',
                message: 'Test message',
                channel: $channel,
                context: [],
            );

            self::assertSame($channel, $payload->channel);
            self::assertSame($channel, $payload->toArray()['channel']);
        }
    }

    #[Test]
    public function handlesNestedContext(): void
    {
        $context = [
            'user' => [
                'id' => 42,
                'name' => 'John Doe',
                'roles' => ['admin', 'editor'],
            ],
            'request' => [
                'method' => 'POST',
                'path' => '/api/users',
            ],
        ];

        $payload = new LogEntryPayload(
            level: 'info',
            message: 'User action logged',
            channel: 'audit',
            context: $context,
        );

        self::assertSame($context, $payload->context);
        self::assertSame($context, $payload->toArray()['context']);
    }

    #[Test]
    public function handlesContextWithMixedTypes(): void
    {
        $context = [
            'string' => 'value',
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
            'null' => null,
            'array' => [1, 2, 3],
        ];

        $payload = new LogEntryPayload(
            level: 'debug',
            message: 'Mixed context types',
            channel: 'app',
            context: $context,
        );

        self::assertSame($context, $payload->context);
        self::assertSame($context, $payload->toArray()['context']);
    }

    #[Test]
    public function handlesLongMessages(): void
    {
        $longMessage = str_repeat('Log entry text. ', 100);

        $payload = new LogEntryPayload(
            level: 'info',
            message: $longMessage,
            channel: 'app',
            context: [],
        );

        self::assertSame($longMessage, $payload->message);
        self::assertSame($longMessage, $payload->toArray()['message']);
    }

    #[Test]
    public function handlesEmptyMessage(): void
    {
        $payload = new LogEntryPayload(
            level: 'info',
            message: '',
            channel: 'app',
            context: [],
        );

        self::assertSame('', $payload->message);
        self::assertSame('', $payload->toArray()['message']);
    }
}
