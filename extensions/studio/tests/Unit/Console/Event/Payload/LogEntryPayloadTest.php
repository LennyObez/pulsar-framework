<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Event\Payload;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\LogEntryPayload;

final class LogEntryPayloadTest extends TestCase
{
    #[Test]
    public function eventTypeReturnsLogEntry(): void
    {
        $payload = new LogEntryPayload('info', 'Test message', 'app', []);

        self::assertSame(EventType::LogEntry, $payload->eventType());
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        $payload = new LogEntryPayload('info', 'Test', 'app', []);

        self::assertSame(EventVersion::V1, $payload->schemaVersion());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $payload = new LogEntryPayload(
            level: 'error',
            message: 'Database connection lost',
            channel: 'database',
            context: ['host' => 'db.example.com'],
        );

        $array = $payload->toArray();

        self::assertSame('error', $array['level']);
        self::assertSame('Database connection lost', $array['message']);
        self::assertSame('database', $array['channel']);
        self::assertSame(['host' => 'db.example.com'], $array['context']);
    }
}
