<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Event\Payload;

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
        self::assertSame(EventType::Notification, $this->createPayload()->eventType());
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        self::assertSame(EventVersion::V1, $this->createPayload()->schemaVersion());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createPayload()->toArray();

        self::assertSame('email', $array['channel']);
        self::assertSame('user@example.com', $array['recipient']);
        self::assertSame('sent', $array['status']);
        self::assertSame(45.2, $array['duration_ms']);
        self::assertNull($array['error_message']);
    }

    #[Test]
    public function toArrayIncludesErrorMessage(): void
    {
        $payload = new NotificationPayload(
            channel: 'sms',
            recipient: '+1234567890',
            status: 'failed',
            durationMs: 120.5,
            errorMessage: 'Gateway timeout',
        );

        $array = $payload->toArray();

        self::assertSame('sms', $array['channel']);
        self::assertSame('failed', $array['status']);
        self::assertSame('Gateway timeout', $array['error_message']);
        self::assertSame(120.5, $array['duration_ms']);
    }

    #[Test]
    public function nullableFieldsDefaultToNull(): void
    {
        $payload = new NotificationPayload(
            channel: 'push',
            recipient: 'device-token',
            status: 'queued',
        );

        $array = $payload->toArray();

        self::assertNull($array['duration_ms']);
        self::assertNull($array['error_message']);
    }

    private function createPayload(): NotificationPayload
    {
        return new NotificationPayload(
            channel: 'email',
            recipient: 'user@example.com',
            status: 'sent',
            durationMs: 45.2,
        );
    }
}
