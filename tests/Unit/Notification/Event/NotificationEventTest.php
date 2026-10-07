<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\Event\NotificationEvent;
use Pulsar\Notification\Event\NotificationFailed;
use Pulsar\Notification\Event\NotificationSent;

#[CoversClass(NotificationEvent::class)]
#[CoversClass(NotificationSent::class)]
#[CoversClass(NotificationFailed::class)]
final class NotificationEventTest extends TestCase
{
    #[Test]
    public function sentEventExposesBaseAndChannelProperties(): void
    {
        $event = new NotificationSent('notif-1', 'user-42', 1700000000, 'mail');

        self::assertSame('notif-1', $event->notificationId);
        self::assertSame('user-42', $event->notifiableId);
        self::assertSame(1700000000, $event->occurredAt);
        self::assertSame('mail', $event->channel);
    }

    #[Test]
    public function failedEventExposesChannelAndReason(): void
    {
        $event = new NotificationFailed('notif-2', 'user-7', 1700001000, 'sms', 'Gateway timeout');

        self::assertSame('notif-2', $event->notificationId);
        self::assertSame('user-7', $event->notifiableId);
        self::assertSame(1700001000, $event->occurredAt);
        self::assertSame('sms', $event->channel);
        self::assertSame('Gateway timeout', $event->reason);
    }

    #[Test]
    public function sentEventIsInstanceOfNotificationEvent(): void
    {
        $event = new NotificationSent('n', 'u', 0, 'ch');

        self::assertInstanceOf(NotificationEvent::class, $event);
    }

    #[Test]
    public function failedEventIsInstanceOfNotificationEvent(): void
    {
        $event = new NotificationFailed('n', 'u', 0, 'ch', 'err');

        self::assertInstanceOf(NotificationEvent::class, $event);
    }

    #[Test]
    public function eventsWithEmptyStringsAreValid(): void
    {
        $sent = new NotificationSent('', '', 0, '');
        self::assertSame('', $sent->notificationId);
        self::assertSame('', $sent->channel);

        $failed = new NotificationFailed('', '', 0, '', '');
        self::assertSame('', $failed->reason);
    }
}
