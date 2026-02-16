<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Persistence;

use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Row;
use Pulsar\Extension\Forum\Notification\NotificationPreference;
use Pulsar\Extension\Forum\Notification\NotificationPreferenceRepositoryInterface;

#[Internal(reason: 'Raw-DB repository; use NotificationPreferenceRepositoryInterface for public API')]
final readonly class DbNotificationPreferenceRepository implements NotificationPreferenceRepositoryInterface
{
    private const string SQL_FIND_BY_USER = <<<'SQL'
        SELECT p.*
        FROM forum_notification_preferences p
        WHERE p.user_id = :user_id
        ORDER BY p.event_type ASC
        SQL;

    private const string SQL_FIND_BY_USER_AND_TYPE = <<<'SQL'
        SELECT p.*
        FROM forum_notification_preferences p
        WHERE p.user_id = :user_id AND p.event_type = :event_type
        SQL;

    private const string SQL_UPSERT_PG = <<<'SQL'
        INSERT INTO forum_notification_preferences (user_id, event_type, in_app, email, email_frequency)
        VALUES (:user_id, :event_type, :in_app, :email, :email_frequency)
        ON CONFLICT (user_id, event_type) DO UPDATE SET
            in_app = EXCLUDED.in_app,
            email = EXCLUDED.email,
            email_frequency = EXCLUDED.email_frequency
        SQL;

    private const string SQL_UPSERT_MYSQL = <<<'SQL'
        INSERT INTO forum_notification_preferences (user_id, event_type, in_app, email, email_frequency)
        VALUES (:user_id, :event_type, :in_app, :email, :email_frequency)
        ON DUPLICATE KEY UPDATE
            in_app = VALUES(in_app),
            email = VALUES(email),
            email_frequency = VALUES(email_frequency)
        SQL;

    private const string SQL_DELETE = <<<'SQL'
        DELETE FROM forum_notification_preferences
        WHERE user_id = :user_id AND event_type = :event_type
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findByUser(string $userId): array
    {
        $result = $this->connection->query(self::SQL_FIND_BY_USER, [
            'user_id' => $userId,
        ]);

        return $result->map(self::hydrate(...));
    }

    public function findByUserAndType(string $userId, string $eventType): ?NotificationPreference
    {
        $result = $this->connection->query(self::SQL_FIND_BY_USER_AND_TYPE, [
            'user_id' => $userId,
            'event_type' => $eventType,
        ]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function save(NotificationPreference $preference): void
    {
        $sql = match ($this->connection->driver()) {
            Driver::MySQL => self::SQL_UPSERT_MYSQL,
            Driver::PostgreSQL, Driver::SQLite => self::SQL_UPSERT_PG,
        };

        $this->connection->execute($sql, [
            'user_id' => $preference->userId,
            'event_type' => $preference->eventType,
            'in_app' => $preference->inApp,
            'email' => $preference->email,
            'email_frequency' => $preference->emailFrequency,
        ]);
    }

    public function delete(NotificationPreference $preference): void
    {
        $this->connection->execute(self::SQL_DELETE, [
            'user_id' => $preference->userId,
            'event_type' => $preference->eventType,
        ]);
    }

    private static function hydrate(Row $row): NotificationPreference
    {
        return new NotificationPreference(
            userId: $row->getString('user_id'),
            eventType: $row->getString('event_type'),
            inApp: $row->getBool('in_app'),
            email: $row->getBool('email'),
            emailFrequency: $row->getString('email_frequency'),
        );
    }
}
