<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\Event\NotificationEvent;
use Pulsar\Notification\Event\NotificationFailed;

#[CoversClass(NotificationFailed::class)]
#[CoversClass(NotificationEvent::class)]
final class NotificationFailedTest extends TestCase
{
    #[Test]
    public function it_constructs_with_all_fields(): void
    {
        $event = new NotificationFailed(
            notificationId: 'notif-001',
            notifiableId: 'user-1',
            occurredAt: 1700000000,
            channel: 'sms',
            reason: 'Gateway timeout',
        );

        self::assertSame('notif-001', $event->notificationId);
        self::assertSame('user-1', $event->notifiableId);
        self::assertSame(1700000000, $event->occurredAt);
        self::assertSame('sms', $event->channel);
        self::assertSame('Gateway timeout', $event->reason);
    }

    #[Test]
    public function it_extends_notification_event(): void
    {
        $event = new NotificationFailed('n-1', 'u-1', 1700000000, 'mail', 'Error');

        self::assertInstanceOf(NotificationEvent::class, $event);
    }
}
