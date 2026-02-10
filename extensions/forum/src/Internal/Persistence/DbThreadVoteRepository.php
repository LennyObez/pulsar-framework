<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Forum\Domain\VoteDirection;
use Pulsar\Extension\Forum\Vote\ThreadVote;
use Pulsar\Extension\Forum\Vote\ThreadVoteRepositoryInterface;

#[Internal(reason: 'Raw-DB repository — use ThreadVoteRepositoryInterface for public API')]
final readonly class DbThreadVoteRepository implements ThreadVoteRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT v.*
        FROM forum_thread_votes v
        WHERE v.id = :id AND v.tenant_id IS NOT DISTINCT FROM :tenant_id
        SQL;

    private const string SQL_FIND_BY_USER_AND_THREAD = <<<'SQL'
        SELECT v.*
        FROM forum_thread_votes v
        WHERE v.user_id = :user_id AND v.thread_id = :thread_id
            AND v.tenant_id IS NOT DISTINCT FROM :tenant_id
        SQL;

    private const string SQL_SCORE_FOR_THREAD = <<<'SQL'
        SELECT COALESCE(SUM(v.value), 0) AS score
        FROM forum_thread_votes v
        WHERE v.thread_id = :thread_id
            AND v.tenant_id IS NOT DISTINCT FROM :tenant_id
        SQL;

    private const string SQL_UPSERT = <<<'SQL'
        INSERT INTO forum_thread_votes (
            id, tenant_id, user_id, thread_id, value, created_at
        ) VALUES (
            :id, :tenant_id, :user_id, :thread_id, :value, :created_at
        )
        ON CONFLICT (id) DO UPDATE SET
            value = EXCLUDED.value
        SQL;

    private const string SQL_DELETE = <<<'SQL'
        DELETE FROM forum_thread_votes WHERE id = :id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
        private ?string $tenantId,
    ) {}

    public function findById(string $id): ?ThreadVote
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id, 'tenant_id' => $this->tenantId]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findByUserAndThread(string $userId, string $threadId): ?ThreadVote
    {
        $result = $this->connection->query(self::SQL_FIND_BY_USER_AND_THREAD, [
            'user_id' => $userId,
            'thread_id' => $threadId,
            'tenant_id' => $this->tenantId,
        ]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function scoreForThread(string $threadId): int
    {
        $result = $this->connection->query(self::SQL_SCORE_FOR_THREAD, [
            'thread_id' => $threadId,
            'tenant_id' => $this->tenantId,
        ]);

        return $result->first()?->getInt('score') ?? 0;
    }

    public function save(ThreadVote $vote): void
    {
        $this->connection->execute(self::SQL_UPSERT, [
            'id' => $vote->id,
            'tenant_id' => $vote->tenantId,
            'user_id' => $vote->userId,
            'thread_id' => $vote->threadId,
            'value' => $vote->value->value,
            'created_at' => $vote->createdAt->format('c'),
        ]);
    }

    public function delete(ThreadVote $vote): void
    {
        $this->connection->execute(self::SQL_DELETE, ['id' => $vote->id]);
    }

    private static function hydrate(Row $row): ThreadVote
    {
        return new ThreadVote(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            userId: $row->getString('user_id'),
            threadId: $row->getString('thread_id'),
            value: VoteDirection::from($row->getInt('value')),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
        );
    }
}
