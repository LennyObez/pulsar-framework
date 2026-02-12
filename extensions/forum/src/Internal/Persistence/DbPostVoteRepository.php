<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Forum\Domain\VoteDirection;
use Pulsar\Extension\Forum\Vote\PostVote;
use Pulsar\Extension\Forum\Vote\PostVoteRepositoryInterface;

#[Internal(reason: 'Raw-DB repository — use PostVoteRepositoryInterface for public API')]
final readonly class DbPostVoteRepository implements PostVoteRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT v.*
        FROM forum_post_votes v
        WHERE v.id = :id AND v.tenant_id IS NOT DISTINCT FROM :tenant_id
        SQL;

    private const string SQL_FIND_BY_USER_AND_POST = <<<'SQL'
        SELECT v.*
        FROM forum_post_votes v
        WHERE v.user_id = :user_id AND v.post_id = :post_id
            AND v.tenant_id IS NOT DISTINCT FROM :tenant_id
        SQL;

    private const string SQL_SCORE_FOR_POST = <<<'SQL'
        SELECT COALESCE(SUM(v.value), 0) AS score
        FROM forum_post_votes v
        WHERE v.post_id = :post_id
            AND v.tenant_id IS NOT DISTINCT FROM :tenant_id
        SQL;

    private const string SQL_UPSERT = <<<'SQL'
        INSERT INTO forum_post_votes (
            id, tenant_id, user_id, post_id, value, created_at
        ) VALUES (
            :id, :tenant_id, :user_id, :post_id, :value, :created_at
        )
        ON CONFLICT (id) DO UPDATE SET
            value = EXCLUDED.value
        SQL;

    private const string SQL_DELETE = <<<'SQL'
        DELETE FROM forum_post_votes WHERE id = :id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
        private ?string $tenantId,
    ) {}

    public function findById(string $id): ?PostVote
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id, 'tenant_id' => $this->tenantId]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findByUserAndPost(string $userId, string $postId): ?PostVote
    {
        $result = $this->connection->query(self::SQL_FIND_BY_USER_AND_POST, [
            'user_id' => $userId,
            'post_id' => $postId,
            'tenant_id' => $this->tenantId,
        ]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function scoreForPost(string $postId): int
    {
        $result = $this->connection->query(self::SQL_SCORE_FOR_POST, [
            'post_id' => $postId,
            'tenant_id' => $this->tenantId,
        ]);

        return $result->first()?->getInt('score') ?? 0;
    }

    public function save(PostVote $vote): void
    {
        $this->connection->execute(self::SQL_UPSERT, [
            'id' => $vote->id,
            'tenant_id' => $vote->tenantId,
            'user_id' => $vote->userId,
            'post_id' => $vote->postId,
            'value' => $vote->value->value,
            'created_at' => $vote->createdAt->format('c'),
        ]);
    }

    public function delete(PostVote $vote): void
    {
        $this->connection->execute(self::SQL_DELETE, ['id' => $vote->id]);
    }

    private static function hydrate(Row $row): PostVote
    {
        return new PostVote(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            userId: $row->getString('user_id'),
            postId: $row->getString('post_id'),
            value: VoteDirection::from($row->getInt('value')),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
        );
    }
}
