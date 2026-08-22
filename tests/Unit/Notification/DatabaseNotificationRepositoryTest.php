<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Notification\DatabaseNotificationRepository;
use Pulsar\Notification\StoredNotification;

use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(DatabaseNotificationRepository::class)]
final class DatabaseNotificationRepositoryTest extends TestCase
{
    #[Test]
    public function storeExecutesInsertWithCorrectParameters(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('INSERT INTO notifications'),
                self::callback(static function (array $params): bool {
                    return $params['notifiable_id'] === 'user-42'
                        && $params['type'] === 'App\\WelcomeNotification'
                        && $params['data'] === '{"greeting":"hello"}'
                        && isset($params['id'], $params['created_at']);
                }),
            );

        $repo = new DatabaseNotificationRepository($connection);
        $repo->store('user-42', 'App\\WelcomeNotification', ['greeting' => 'hello']);
    }

    #[Test]
    public function markAsReadUpdatesReadAtForNotification(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('UPDATE notifications SET read_at'),
                self::callback(static fn(array $params): bool => $params['id'] === 'notif-abc' && isset($params['read_at'])),
            );

        $repo = new DatabaseNotificationRepository($connection);
        $repo->markAsRead('notif-abc');
    }

    #[Test]
    public function markAllAsReadUpdatesAllUnreadForNotifiable(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::logicalAnd(
                    self::stringContains('UPDATE notifications SET read_at'),
                    self::stringContains('read_at IS NULL'),
                ),
                self::callback(static fn(array $params): bool => $params['notifiable_id'] === 'user-7'),
            );

        $repo = new DatabaseNotificationRepository($connection);
        $repo->markAllAsRead('user-7');
    }

    #[Test]
    public function forNotifiableReturnsHydratedStoredNotifications(): void
    {
        $rows = [
            new Row([
                'id' => 'n1',
                'notifiable_id' => 'user-1',
                'type' => 'App\\OrderShipped',
                'data' => json_encode(['order_id' => 99], JSON_THROW_ON_ERROR),
                'read_at' => null,
                'created_at' => 1700000000,
            ]),
            new Row([
                'id' => 'n2',
                'notifiable_id' => 'user-1',
                'type' => 'App\\Welcome',
                'data' => json_encode(['msg' => 'hi'], JSON_THROW_ON_ERROR),
                'read_at' => 1700000100,
                'created_at' => 1699999000,
            ]),
        ];

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result($rows));

        $repo = new DatabaseNotificationRepository($connection);
        $results = $repo->forNotifiable('user-1');

        self::assertCount(2, $results);
        self::assertInstanceOf(StoredNotification::class, $results[0]);
        self::assertSame('n1', $results[0]->id);
        self::assertSame('user-1', $results[0]->notifiableId);
        self::assertSame('App\\OrderShipped', $results[0]->type);
        self::assertSame(['order_id' => 99], $results[0]->data);
        self::assertNull($results[0]->readAt);
        self::assertSame(1700000000, $results[0]->createdAt);
        self::assertTrue($results[0]->isUnread());

        self::assertSame('n2', $results[1]->id);
        self::assertSame(1700000100, $results[1]->readAt);
        self::assertTrue($results[1]->isRead());
    }

    #[Test]
    public function forNotifiableRespectsCustomLimit(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('LIMIT'),
                self::callback(static fn(array $params): bool => $params['limit'] === 10),
            )
            ->willReturn(new Result([]));

        $repo = new DatabaseNotificationRepository($connection);
        (void) $repo->forNotifiable('user-1', 10);
    }

    #[Test]
    public function unreadForNotifiableFiltersOnNullReadAt(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('query')
            ->with(
                self::logicalAnd(
                    self::stringContains('read_at IS NULL'),
                    self::stringContains('ORDER BY created_at DESC'),
                ),
                self::callback(static fn(array $params): bool => $params['notifiable_id'] === 'user-5' && $params['limit'] === 50),
            )
            ->willReturn(new Result([]));

        $repo = new DatabaseNotificationRepository($connection);
        $result = $repo->unreadForNotifiable('user-5');

        self::assertSame([], $result);
    }

    #[Test]
    public function unreadCountReturnsZeroWhenNoRows(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([]));

        $repo = new DatabaseNotificationRepository($connection);

        self::assertSame(0, $repo->unreadCount('user-1'));
    }

    #[Test]
    public function unreadCountReturnsCountFromQuery(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([new Row(['cnt' => 5])]));

        $repo = new DatabaseNotificationRepository($connection);

        self::assertSame(5, $repo->unreadCount('user-1'));
    }

    #[Test]
    public function deleteExecutesDeleteStatement(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('DELETE FROM notifications WHERE id'),
                self::callback(static fn(array $params): bool => $params['id'] === 'notif-xyz'),
            );

        $repo = new DatabaseNotificationRepository($connection);
        $repo->delete('notif-xyz');
    }

    #[Test]
    public function pruneReadDeletesOldReadNotifications(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::logicalAnd(
                    self::stringContains('DELETE FROM'),
                    self::stringContains('read_at IS NOT NULL'),
                    self::stringContains('read_at <'),
                ),
                self::callback(static fn(array $params): bool => $params['threshold'] === 1600000000),
            )
            ->willReturn(3);

        $repo = new DatabaseNotificationRepository($connection);

        self::assertSame(3, $repo->pruneRead(1600000000));
    }

    #[Test]
    public function customTableNameIsUsedInQueries(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('INSERT INTO custom_notifications'),
                self::anything(),
            );

        $repo = new DatabaseNotificationRepository($connection, 'custom_notifications');
        $repo->store('user-1', 'App\\Notif', []);
    }
}
