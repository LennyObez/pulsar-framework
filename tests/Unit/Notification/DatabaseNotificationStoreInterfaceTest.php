<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Notification\DatabaseNotificationStoreInterface;

#[CoversClass(DatabaseNotificationStoreInterface::class)]
final class DatabaseNotificationStoreInterfaceTest extends TestCase
{
    #[Test]
    public function storeRecordsNotification(): void
    {
        $store = new class implements DatabaseNotificationStoreInterface {
            /** @var list<array{notifiableId: string, type: string, data: array<string, mixed>}> */
            public array $stored = [];

            public function store(string $notifiableId, string $type, array $data): void
            {
                $this->stored[] = ['notifiableId' => $notifiableId, 'type' => $type, 'data' => $data];
            }

            public function markAsRead(string $notificationId): void {}
        };

        $store->store('user-1', 'App\\WelcomeNotification', ['message' => 'Hello']);

        self::assertCount(1, $store->stored);
        self::assertSame('user-1', $store->stored[0]['notifiableId']);
        self::assertSame('App\\WelcomeNotification', $store->stored[0]['type']);
        self::assertSame(['message' => 'Hello'], $store->stored[0]['data']);
    }

    #[Test]
    public function markAsReadTracksNotificationId(): void
    {
        $store = new class implements DatabaseNotificationStoreInterface {
            /** @var list<string> */
            public array $markedIds = [];

            public function store(string $notifiableId, string $type, array $data): void {}

            public function markAsRead(string $notificationId): void
            {
                $this->markedIds[] = $notificationId;
            }
        };

        $store->markAsRead('notif-abc');
        $store->markAsRead('notif-def');

        self::assertSame(['notif-abc', 'notif-def'], $store->markedIds);
    }

    #[Test]
    public function storeAcceptsEmptyDataArray(): void
    {
        $store = new class implements DatabaseNotificationStoreInterface {
            /** @var list<array<string, mixed>> */
            public array $storedData = [];

            public function store(string $notifiableId, string $type, array $data): void
            {
                $this->storedData[] = $data;
            }

            public function markAsRead(string $notificationId): void {}
        };

        $store->store('user-1', 'Type', []);

        self::assertSame([[]], $store->storedData);
    }
}
