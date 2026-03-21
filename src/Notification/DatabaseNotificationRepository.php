<?php

declare(strict_types=1);

namespace Pulsar\Notification;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;

use function bin2hex;
use function count;
use function json_decode;
use function json_encode;
use function random_bytes;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * Database-backed notification repository with read/unread tracking.
 *
 * Stores notifications in a `notifications` table and provides CRUD
 * operations for querying, marking as read, and deleting notifications.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DatabaseNotificationRepository implements DatabaseNotificationStoreInterface
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $table = 'notifications',
    ) {}

    /**
     * Store a notification record.
     */
    public function store(string $notifiableId, string $type, array $data): void
    {
        $id = bin2hex(random_bytes(16));
        $now = time();

        $this->connection->execute(
            "INSERT INTO {$this->table} (id, notifiable_id, type, data, read_at, created_at) VALUES (:id, :notifiable_id, :type, :data, NULL, :created_at)",
            [
                'id' => $id,
                'notifiable_id' => $notifiableId,
                'type' => $type,
                'data' => json_encode($data, JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ],
        );
    }

    /**
     * Mark a notification as read.
     */
    public function markAsRead(string $notificationId): void
    {
        $this->connection->execute(
            "UPDATE {$this->table} SET read_at = :read_at WHERE id = :id",
            ['read_at' => time(), 'id' => $notificationId],
        );
    }

    /**
     * Mark all notifications for a notifiable as read.
     */
    public function markAllAsRead(string $notifiableId): void
    {
        $this->connection->execute(
            "UPDATE {$this->table} SET read_at = :read_at WHERE notifiable_id = :notifiable_id AND read_at IS NULL",
            ['read_at' => time(), 'notifiable_id' => $notifiableId],
        );
    }

    /**
     * Get all notifications for a notifiable, newest first.
     *
     * @return list<StoredNotification>
     */
    #[NoDiscard]
    public function forNotifiable(string $notifiableId, int $limit = 50): array
    {
        $result = $this->connection->query(
            "SELECT * FROM {$this->table} WHERE notifiable_id = :notifiable_id ORDER BY created_at DESC LIMIT :limit",
            ['notifiable_id' => $notifiableId, 'limit' => $limit],
        );

        return $this->hydrateRows($result->rows);
    }

    /**
     * Get only unread notifications for a notifiable.
     *
     * @return list<StoredNotification>
     */
    #[NoDiscard]
    public function unreadForNotifiable(string $notifiableId, int $limit = 50): array
    {
        $result = $this->connection->query(
            "SELECT * FROM {$this->table} WHERE notifiable_id = :notifiable_id AND read_at IS NULL ORDER BY created_at DESC LIMIT :limit",
            ['notifiable_id' => $notifiableId, 'limit' => $limit],
        );

        return $this->hydrateRows($result->rows);
    }

    /**
     * Count unread notifications for a notifiable.
     */
    #[NoDiscard]
    public function unreadCount(string $notifiableId): int
    {
        $result = $this->connection->query(
            "SELECT COUNT(*) as cnt FROM {$this->table} WHERE notifiable_id = :notifiable_id AND read_at IS NULL",
            ['notifiable_id' => $notifiableId],
        );

        $row = $result->first();

        return $row !== null ? $row->getInt('cnt') : 0;
    }

    /**
     * Delete a specific notification.
     */
    public function delete(string $notificationId): void
    {
        $this->connection->execute(
            "DELETE FROM {$this->table} WHERE id = :id",
            ['id' => $notificationId],
        );
    }

    /**
     * Delete all read notifications older than the given timestamp.
     */
    public function pruneRead(int $olderThanTimestamp): int
    {
        return $this->connection->execute(
            "DELETE FROM {$this->table} WHERE read_at IS NOT NULL AND read_at < :threshold",
            ['threshold' => $olderThanTimestamp],
        );
    }

    /**
     * @param list<Row> $rows
     * @return list<StoredNotification>
     */
    private function hydrateRows(array $rows): array
    {
        $notifications = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $data */
            $data = json_decode($row->getString('data'), true, 64, JSON_THROW_ON_ERROR);

            $notifications[] = new StoredNotification(
                id: $row->getString('id'),
                notifiableId: $row->getString('notifiable_id'),
                type: $row->getString('type'),
                data: $data,
                readAt: $row->getNullableInt('read_at'),
                createdAt: $row->getInt('created_at'),
            );
        }

        return $notifications;
    }
}
