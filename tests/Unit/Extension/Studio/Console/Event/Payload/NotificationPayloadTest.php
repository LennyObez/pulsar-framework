<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\NotificationPayload;

#[CoversClass(NotificationPayload::class)]
final class NotificationPayloadTest extends TestCase
{
    #[Test]
    public function eventTypeReturnsNotification(): void
    {
        $payload = new NotificationPayload(
            channel: 'email',
            recipient: 'user@example.com',
            status: 'sent',
        );

        self::assertSame(EventType::Notification, $payload->eventType());
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        $payload = new NotificationPayload(
            channel: 'sms',
            recipient: '+123',
            status: 'sent',
        );

        self::assertSame(EventVersion::V1, $payload->schemaVersion());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $payload = new NotificationPayload(
            channel: 'slack',
            recipient: '#general',
            status: 'failed',
            durationMs: 500.0,
            errorMessage: 'Rate limited',
        );

        $data = $payload->toArray();

        self::assertSame('slack', $data['channel']);
        self::assertSame('#general', $data['recipient']);
        self::assertSame('failed', $data['status']);
        self::assertSame(500.0, $data['duration_ms']);
        self::assertSame('Rate limited', $data['error_message']);
    }
}
