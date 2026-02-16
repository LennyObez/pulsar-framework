<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Forum\Domain\BanType;
use Pulsar\Extension\Forum\Report\UserBan;
use Pulsar\Extension\Forum\Report\UserBanRepositoryInterface;

use function ceil;
use function max;
use function min;

#[Internal(reason: 'Raw-DB repository; use UserBanRepositoryInterface for public API')]
final readonly class DbUserBanRepository implements UserBanRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT b.*
        FROM forum_user_bans b
        WHERE b.id = :id
        SQL;

    private const string SQL_FIND_ACTIVE_BY_USER = <<<'SQL'
        SELECT b.*
        FROM forum_user_bans b
        WHERE b.user_id = :user_id
            AND b.revoked_at IS NULL
        ORDER BY b.created_at DESC
        LIMIT 1
        SQL;

    private const string SQL_FIND_BY_USER = <<<'SQL'
        SELECT b.*
        FROM forum_user_bans b
        WHERE b.user_id = :user_id
        ORDER BY b.created_at DESC
        LIMIT 100
        SQL;

    private const string SQL_COUNT_ACTIVE = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM forum_user_bans b
        WHERE b.revoked_at IS NULL
        SQL;

    private const string SQL_FIND_ACTIVE = <<<'SQL'
        SELECT b.*
        FROM forum_user_bans b
        WHERE b.revoked_at IS NULL
        ORDER BY b.created_at DESC
        LIMIT :limit OFFSET :offset
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'user_id', 'banned_by', 'reason', 'type',
        'expires_at', 'created_at', 'revoked_at',
    ];

    private const array UPSERT_UPDATE = [
        'reason', 'type', 'expires_at', 'revoked_at',
    ];

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findById(string $id): ?UserBan
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findActiveByUser(string $userId): ?UserBan
    {
        $result = $this->connection->query(self::SQL_FIND_ACTIVE_BY_USER, [
            'user_id' => $userId,
        ]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findByUser(string $userId): array
    {
        $result = $this->connection->query(self::SQL_FIND_BY_USER, [
            'user_id' => $userId,
        ]);

        return $result->map(self::hydrate(...));
    }

    public function findActive(int $page = 1, int $perPage = 20): PaginationResult
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $countResult = $this->connection->query(self::SQL_COUNT_ACTIVE);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $dataResult = $this->connection->query(self::SQL_FIND_ACTIVE, [
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

    public function save(UserBan $ban): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'forum_user_bans',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $ban->id,
            'user_id' => $ban->userId,
            'banned_by' => $ban->bannedBy,
            'reason' => $ban->reason,
            'type' => $ban->type->value,
            'expires_at' => $ban->expiresAt?->format('c'),
            'created_at' => $ban->createdAt->format('c'),
            'revoked_at' => $ban->revokedAt?->format('c'),
        ]);
    }

    private static function hydrate(Row $row): UserBan
    {
        return new UserBan(
            id: $row->getString('id'),
            userId: $row->getString('user_id'),
            bannedBy: $row->getString('banned_by'),
            reason: $row->getString('reason'),
            type: BanType::from($row->getString('type')),
            expiresAt: self::toDateTime($row->getNullableString('expires_at')),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            revokedAt: self::toDateTime($row->getNullableString('revoked_at')),
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
