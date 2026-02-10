<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Forum\Subscription\ThreadSubscription;
use Pulsar\Extension\Forum\Subscription\ThreadSubscriptionRepositoryInterface;

#[Internal(reason: 'Raw-DB repository — use ThreadSubscriptionRepositoryInterface for public API')]
final readonly class DbThreadSubscriptionRepository implements ThreadSubscriptionRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT s.*
        FROM forum_thread_subscriptions s
        WHERE s.id = :id
        SQL;

    private const string SQL_FIND_BY_USER_AND_THREAD = <<<'SQL'
        SELECT s.*
        FROM forum_thread_subscriptions s
        WHERE s.user_id = :user_id AND s.thread_id = :thread_id
        SQL;

    private const string SQL_FIND_BY_THREAD = <<<'SQL'
        SELECT s.*
        FROM forum_thread_subscriptions s
        WHERE s.thread_id = :thread_id
        ORDER BY s.created_at ASC
        LIMIT 10000
        SQL;

    private const string SQL_FIND_BY_USER = <<<'SQL'
        SELECT s.*
        FROM forum_thread_subscriptions s
        WHERE s.user_id = :user_id
            AND s.tenant_id IS NOT DISTINCT FROM :tenant_id
        ORDER BY s.created_at DESC
        LIMIT 10000
        SQL;

    private const string SQL_IS_SUBSCRIBED = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM forum_thread_subscriptions s
        WHERE s.user_id = :user_id AND s.thread_id = :thread_id
        SQL;

    private const string SQL_UPSERT = <<<'SQL'
        INSERT INTO forum_thread_subscriptions (
            id, tenant_id, user_id, thread_id, created_at
        ) VALUES (
            :id, :tenant_id, :user_id, :thread_id, :created_at
        )
        ON CONFLICT (id) DO NOTHING
        SQL;

    private const string SQL_DELETE = <<<'SQL'
        DELETE FROM forum_thread_subscriptions WHERE id = :id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
        private ?string $tenantId,
    ) {}

    public function findById(string $id): ?ThreadSubscription
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findByUserAndThread(string $userId, string $threadId): ?ThreadSubscription
    {
        $result = $this->connection->query(self::SQL_FIND_BY_USER_AND_THREAD, [
            'user_id' => $userId,
            'thread_id' => $threadId,
        ]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findByThread(string $threadId): array
    {
        $result = $this->connection->query(self::SQL_FIND_BY_THREAD, [
            'thread_id' => $threadId,
        ]);

        return $result->map(self::hydrate(...));
    }

    public function findByUser(string $userId, ?string $tenantId = null): array
    {
        $result = $this->connection->query(self::SQL_FIND_BY_USER, [
            'user_id' => $userId,
            'tenant_id' => $tenantId ?? $this->tenantId,
        ]);

        return $result->map(self::hydrate(...));
    }

    public function isSubscribed(string $userId, string $threadId): bool
    {
        $result = $this->connection->query(self::SQL_IS_SUBSCRIBED, [
            'user_id' => $userId,
            'thread_id' => $threadId,
        ]);

        return ($result->first()?->getInt('total') ?? 0) > 0;
    }

    public function save(ThreadSubscription $subscription): void
    {
        $this->connection->execute(self::SQL_UPSERT, [
            'id' => $subscription->id,
            'tenant_id' => $subscription->tenantId,
            'user_id' => $subscription->userId,
            'thread_id' => $subscription->threadId,
            'created_at' => $subscription->createdAt->format('c'),
        ]);
    }

    public function delete(ThreadSubscription $subscription): void
    {
        $this->connection->execute(self::SQL_DELETE, ['id' => $subscription->id]);
    }

    private static function hydrate(Row $row): ThreadSubscription
    {
        return new ThreadSubscription(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            userId: $row->getString('user_id'),
            threadId: $row->getString('thread_id'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
        );
    }
}
