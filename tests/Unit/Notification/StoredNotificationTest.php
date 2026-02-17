<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\StoredNotification;

#[CoversClass(StoredNotification::class)]
final class StoredNotificationTest extends TestCase
{
    #[Test]
    public function isReadReturnsTrueWhenReadAtSet(): void
    {
        $notification = new StoredNotification('id1', 'user1', 'App\\OrderNotification', ['msg' => 'test'], 1700000000, 1700000000);

        self::assertTrue($notification->isRead());
        self::assertFalse($notification->isUnread());
    }

    #[Test]
    public function isUnreadReturnsTrueWhenReadAtNull(): void
    {
        $notification = new StoredNotification('id2', 'user1', 'App\\OrderNotification', ['msg' => 'test'], null, 1700000000);

        self::assertTrue($notification->isUnread());
        self::assertFalse($notification->isRead());
    }

    #[Test]
    public function propertiesAreAccessible(): void
    {
        $notification = new StoredNotification('abc', 'user-42', 'Notification\\Welcome', ['k' => 'v'], null, 1710000000);

        self::assertSame('abc', $notification->id);
        self::assertSame('user-42', $notification->notifiableId);
        self::assertSame('Notification\\Welcome', $notification->type);
        self::assertSame(['k' => 'v'], $notification->data);
        self::assertNull($notification->readAt);
        self::assertSame(1710000000, $notification->createdAt);
    }
}
