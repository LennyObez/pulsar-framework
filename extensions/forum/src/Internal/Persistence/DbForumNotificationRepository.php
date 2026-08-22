<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Forum\Notification\ForumNotification;
use Pulsar\Extension\Forum\Notification\ForumNotificationRepositoryInterface;

use function ceil;
use function json_decode;
use function json_encode;
use function max;
use function min;

use const JSON_THROW_ON_ERROR;

#[Internal(reason: 'Raw-DB repository; use ForumNotificationRepositoryInterface for public API')]
final readonly class DbForumNotificationRepository implements ForumNotificationRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT n.*
        FROM forum_notifications n
        WHERE n.id = :id
        SQL;

    private const string SQL_COUNT_BY_USER = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM forum_notifications n
        WHERE n.user_id = :user_id
        SQL;

    private const string SQL_FIND_BY_USER = <<<'SQL'
        SELECT n.*
        FROM forum_notifications n
        WHERE n.user_id = :user_id
        ORDER BY n.created_at DESC
        LIMIT :limit OFFSET :offset
        SQL;

    private const string SQL_COUNT_UNREAD = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM forum_notifications n
        WHERE n.user_id = :user_id AND n.is_read = :is_read_false
        SQL;

    private const string SQL_MARK_READ = <<<'SQL'
        UPDATE forum_notifications
        SET is_read = :is_read_true
        WHERE id = :id
        SQL;

    private const string SQL_MARK_ALL_READ = <<<'SQL'
        UPDATE forum_notifications
        SET is_read = :is_read_true
        WHERE user_id = :user_id AND is_read = :is_read_false
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'user_id', 'type', 'title', 'body', 'url',
        'is_read', 'data', 'created_at',
    ];

    private const array UPSERT_UPDATE = [
        'title', 'body', 'url', 'is_read', 'data',
    ];

    private const string SQL_DELETE = <<<'SQL'
        DELETE FROM forum_notifications WHERE id = :id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findById(string $id): ?ForumNotification
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findByUser(string $userId, int $page = 1, int $perPage = 20): PaginationResult
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $countResult = $this->connection->query(self::SQL_COUNT_BY_USER, [
            'user_id' => $userId,
        ]);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $dataResult = $this->connection->query(self::SQL_FIND_BY_USER, [
            'user_id' => $userId,
            'limit' => $perPage,
            'offset' => $offset,
        ]);
        $items = $dataResult->map(self::hydrate(...));
        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;

        return new PaginationResult(
            items: $items,
            total: $total,
            hasMore: $page < $lastPage,
            perPage: $perPage,
            currentPage: $page,
            lastPage: $lastPage,
        );
    }

    public function countUnread(string $userId): int
    {
        $result = $this->connection->query(self::SQL_COUNT_UNREAD, [
            'user_id' => $userId,
            'is_read_false' => false,
        ]);

        return $result->first()?->getInt('total') ?? 0;
    }

    public function markRead(string $id): void
    {
        $this->connection->execute(self::SQL_MARK_READ, [
            'id' => $id,
            'is_read_true' => true,
        ]);
    }

    public function markAllRead(string $userId): void
    {
        $this->connection->execute(self::SQL_MARK_ALL_READ, [
            'user_id' => $userId,
            'is_read_true' => true,
            'is_read_false' => false,
        ]);
    }

    public function save(ForumNotification $notification): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'forum_notifications',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $notification->id,
            'user_id' => $notification->userId,
            'type' => $notification->type,
            'title' => $notification->title,
            'body' => $notification->body,
            'url' => $notification->url,
            'is_read' => $notification->isRead,
            'data' => json_encode($notification->data, JSON_THROW_ON_ERROR),
            'created_at' => $notification->createdAt->format('c'),
        ]);
    }

    public function delete(ForumNotification $notification): void
    {
        $this->connection->execute(self::SQL_DELETE, ['id' => $notification->id]);
    }

    private static function hydrate(Row $row): ForumNotification
    {
        $rawData = $row->getNullableString('data');

        /** @var array<string, mixed> $data */
        $data = $rawData !== null && $rawData !== ''
            ? (array) json_decode($rawData, true, flags: JSON_THROW_ON_ERROR)
            : [];

        return new ForumNotification(
            id: $row->getString('id'),
            userId: $row->getString('user_id'),
            type: $row->getString('type'),
            title: $row->getString('title'),
            body: $row->getString('body'),
            url: $row->getString('url'),
            isRead: $row->getBool('is_read'),
            data: $data,
            createdAt: new DateTimeImmutable($row->getString('created_at')),
        );
    }
}
