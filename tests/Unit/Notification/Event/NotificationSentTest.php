<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\Event\NotificationEvent;
use Pulsar\Notification\Event\NotificationSent;

#[CoversClass(NotificationSent::class)]
#[CoversClass(NotificationEvent::class)]
final class NotificationSentTest extends TestCase
{
    #[Test]
    public function it_constructs_with_all_fields(): void
    {
        $event = new NotificationSent(
            notificationId: 'notif-001',
            notifiableId: 'user-1',
            occurredAt: 1700000000,
            channel: 'mail',
        );

        self::assertSame('notif-001', $event->notificationId);
        self::assertSame('user-1', $event->notifiableId);
        self::assertSame(1700000000, $event->occurredAt);
        self::assertSame('mail', $event->channel);
    }

    #[Test]
    public function it_extends_notification_event(): void
    {
        $event = new NotificationSent('n-1', 'u-1', 1700000000, 'sms');

        self::assertInstanceOf(NotificationEvent::class, $event);
    }
}
