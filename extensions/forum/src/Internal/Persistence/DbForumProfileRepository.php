<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;

use function ceil;
use function max;
use function min;

#[Internal(reason: 'Raw-DB repository — use ForumProfileRepositoryInterface for public API')]
final readonly class DbForumProfileRepository implements ForumProfileRepositoryInterface
{
    private const string SENTINEL_TENANT = '00000000-0000-0000-0000-000000000000';

    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT p.*
        FROM forum_profiles p
        WHERE p.id = :id
        SQL;

    private const string SQL_FIND_BY_USER = <<<'SQL'
        SELECT p.*
        FROM forum_profiles p
        WHERE p.user_id = :user_id
            AND COALESCE(p.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
        SQL;

    private const string SQL_COUNT_TOP_CONTRIBUTORS = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM forum_profiles p
        WHERE COALESCE(p.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
        SQL;

    private const string SQL_FIND_TOP_CONTRIBUTORS = <<<'SQL'
        SELECT p.*
        FROM forum_profiles p
        WHERE COALESCE(p.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
        ORDER BY p.reputation_score DESC
        LIMIT :limit OFFSET :offset
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'tenant_id', 'user_id', 'reputation_score',
        'post_count', 'thread_count', 'is_banned', 'ban_reason',
        'banned_at', 'ban_expires_at', 'created_at', 'updated_at',
    ];

    private const array UPSERT_UPDATE = [
        'reputation_score', 'post_count', 'thread_count',
        'is_banned', 'ban_reason', 'banned_at', 'ban_expires_at', 'updated_at',
    ];

    private const string SQL_INCREMENT_REPUTATION = <<<'SQL'
        UPDATE forum_profiles
        SET reputation_score = reputation_score + :delta,
            updated_at = :updated_at
        WHERE user_id = :user_id
            AND COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
        SQL;

    private const string SQL_INCREMENT_POST_COUNT = <<<'SQL'
        UPDATE forum_profiles
        SET post_count = GREATEST(0, post_count + :delta),
            updated_at = :updated_at
        WHERE user_id = :user_id
            AND COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
        SQL;

    private const string SQL_INCREMENT_THREAD_COUNT = <<<'SQL'
        UPDATE forum_profiles
        SET thread_count = GREATEST(0, thread_count + :delta),
            updated_at = :updated_at
        WHERE user_id = :user_id
            AND COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
        SQL;

    private const string SQL_DELETE = <<<'SQL'
        DELETE FROM forum_profiles WHERE id = :id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
        private ?string $tenantId,
    ) {}

    public function findById(string $id): ?ForumProfile
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findByUser(string $userId, ?string $tenantId = null): ?ForumProfile
    {
        $result = $this->connection->query(self::SQL_FIND_BY_USER, [
            'user_id' => $userId,
            'tenant_key' => $tenantId ?? $this->tenantId ?? self::SENTINEL_TENANT,
        ]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findTopContributors(
        int $page = 1,
        int $perPage = 20,
        ?string $tenantId = null,
    ): PaginationResult {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $tenantKey = $tenantId ?? $this->tenantId ?? self::SENTINEL_TENANT;

        $countResult = $this->connection->query(self::SQL_COUNT_TOP_CONTRIBUTORS, [
            'tenant_key' => $tenantKey,
        ]);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $dataResult = $this->connection->query(self::SQL_FIND_TOP_CONTRIBUTORS, [
            'tenant_key' => $tenantKey,
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

    public function save(ForumProfile $profile): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'forum_profiles',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $profile->id,
            'tenant_id' => $profile->tenantId,
            'user_id' => $profile->userId,
            'reputation_score' => $profile->reputationScore,
            'post_count' => $profile->postCount,
            'thread_count' => $profile->threadCount,
            'is_banned' => $profile->isBanned,
            'ban_reason' => $profile->banReason,
            'banned_at' => $profile->bannedAt?->format('c'),
            'ban_expires_at' => $profile->banExpiresAt?->format('c'),
            'created_at' => $profile->createdAt->format('c'),
            'updated_at' => $profile->updatedAt->format('c'),
        ]);
    }

    public function delete(ForumProfile $profile): void
    {
        $this->connection->execute(self::SQL_DELETE, ['id' => $profile->id]);
    }

    public function incrementReputation(string $userId, int $delta, ?string $tenantId = null): void
    {
        $now = new DateTimeImmutable();

        $this->connection->execute(self::SQL_INCREMENT_REPUTATION, [
            'user_id' => $userId,
            'tenant_key' => $tenantId ?? $this->tenantId ?? self::SENTINEL_TENANT,
            'delta' => $delta,
            'updated_at' => $now->format('c'),
        ]);
    }

    public function incrementPostCount(string $userId, ?string $tenantId = null, int $delta = 1): void
    {
        $now = new DateTimeImmutable();

        $this->connection->execute(self::SQL_INCREMENT_POST_COUNT, [
            'user_id' => $userId,
            'tenant_key' => $tenantId ?? $this->tenantId ?? self::SENTINEL_TENANT,
            'delta' => $delta,
            'updated_at' => $now->format('c'),
        ]);
    }

    public function incrementThreadCount(string $userId, ?string $tenantId = null, int $delta = 1): void
    {
        $now = new DateTimeImmutable();

        $this->connection->execute(self::SQL_INCREMENT_THREAD_COUNT, [
            'user_id' => $userId,
            'tenant_key' => $tenantId ?? $this->tenantId ?? self::SENTINEL_TENANT,
            'delta' => $delta,
            'updated_at' => $now->format('c'),
        ]);
    }

    private static function hydrate(Row $row): ForumProfile
    {
        return new ForumProfile(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            userId: $row->getString('user_id'),
            reputationScore: $row->getInt('reputation_score'),
            postCount: $row->getInt('post_count'),
            threadCount: $row->getInt('thread_count'),
            isBanned: $row->getBool('is_banned'),
            banReason: $row->getNullableString('ban_reason'),
            bannedAt: self::toDateTime($row->getNullableString('banned_at')),
            banExpiresAt: self::toDateTime($row->getNullableString('ban_expires_at')),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            updatedAt: new DateTimeImmutable($row->getString('updated_at')),
        );
    }

    private static function toDateTime(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        return new DateTimeImmutable($value);
    }
}
