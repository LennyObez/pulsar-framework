<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Forum\Domain\VoteDirection;
use Pulsar\Extension\Forum\Vote\ThreadVote;
use Pulsar\Extension\Forum\Vote\ThreadVoteRepositoryInterface;

#[Internal(reason: 'Raw-DB repository — use ThreadVoteRepositoryInterface for public API')]
final readonly class DbThreadVoteRepository implements ThreadVoteRepositoryInterface
{
    private const string SENTINEL_TENANT = '00000000-0000-0000-0000-000000000000';

    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT v.*
        FROM forum_thread_votes v
        WHERE v.id = :id AND COALESCE(v.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
        SQL;

    private const string SQL_FIND_BY_USER_AND_THREAD = <<<'SQL'
        SELECT v.*
        FROM forum_thread_votes v
        WHERE v.user_id = :user_id AND v.thread_id = :thread_id
            AND COALESCE(v.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
        SQL;

    private const string SQL_SCORE_FOR_THREAD = <<<'SQL'
        SELECT COALESCE(SUM(v.value), 0) AS score
        FROM forum_thread_votes v
        WHERE v.thread_id = :thread_id
            AND COALESCE(v.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'tenant_id', 'user_id', 'thread_id', 'value', 'created_at',
    ];

    private const array UPSERT_UPDATE = ['value'];

    private const string SQL_DELETE = <<<'SQL'
        DELETE FROM forum_thread_votes WHERE id = :id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
        private ?string $tenantId,
    ) {}

    public function findById(string $id): ?ThreadVote
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id, 'tenant_key' => $this->tenantId ?? self::SENTINEL_TENANT]);
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
            'tenant_key' => $this->tenantId ?? self::SENTINEL_TENANT,
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
            'tenant_key' => $this->tenantId ?? self::SENTINEL_TENANT,
        ]);

        return $result->first()?->getInt('score') ?? 0;
    }

    public function save(ThreadVote $vote): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'forum_thread_votes',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
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
