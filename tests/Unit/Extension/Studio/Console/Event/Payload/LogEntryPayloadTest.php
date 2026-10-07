<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\LogEntryPayload;

#[CoversClass(LogEntryPayload::class)]
final class LogEntryPayloadTest extends TestCase
{
    #[Test]
    public function eventTypeReturnsLogEntry(): void
    {
        $payload = new LogEntryPayload(
            level: 'error',
            message: 'Something failed',
            channel: 'app',
            context: [],
        );

        self::assertSame(EventType::LogEntry, $payload->eventType());
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        $payload = new LogEntryPayload(
            level: 'info',
            message: 'test',
            channel: 'default',
            context: [],
        );

        self::assertSame(EventVersion::V1, $payload->schemaVersion());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $payload = new LogEntryPayload(
            level: 'warning',
            message: 'Disk space low',
            channel: 'system',
            context: ['disk_free_mb' => 100],
        );

        $data = $payload->toArray();

        self::assertSame('warning', $data['level']);
        self::assertSame('Disk space low', $data['message']);
        self::assertSame('system', $data['channel']);
        self::assertSame(['disk_free_mb' => 100], $data['context']);
    }
}
