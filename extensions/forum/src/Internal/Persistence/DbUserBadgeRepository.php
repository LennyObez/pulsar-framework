<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Forum\Badge\UserBadge;
use Pulsar\Extension\Forum\Badge\UserBadgeRepositoryInterface;
use Pulsar\Extension\Forum\Domain\Badge;

#[Internal(reason: 'Raw-DB repository — use UserBadgeRepositoryInterface for public API')]
final readonly class DbUserBadgeRepository implements UserBadgeRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT b.*
        FROM forum_user_badges b
        WHERE b.id = :id
        SQL;

    private const string SQL_FIND_BY_USER = <<<'SQL'
        SELECT b.*
        FROM forum_user_badges b
        WHERE b.user_id = :user_id
            AND b.tenant_id IS NOT DISTINCT FROM :tenant_id
        ORDER BY b.awarded_at DESC
        SQL;

    private const string SQL_HAS_BADGE = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM forum_user_badges b
        WHERE b.user_id = :user_id
            AND b.badge = :badge
            AND b.tenant_id IS NOT DISTINCT FROM :tenant_id
        SQL;

    private const string SQL_UPSERT = <<<'SQL'
        INSERT INTO forum_user_badges (
            id, tenant_id, user_id, badge, awarded_at
        ) VALUES (
            :id, :tenant_id, :user_id, :badge, :awarded_at
        )
        ON CONFLICT (id) DO NOTHING
        SQL;

    private const string SQL_DELETE = <<<'SQL'
        DELETE FROM forum_user_badges WHERE id = :id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
        private ?string $tenantId,
    ) {}

    public function findById(string $id): ?UserBadge
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findByUser(string $userId, ?string $tenantId = null): array
    {
        $result = $this->connection->query(self::SQL_FIND_BY_USER, [
            'user_id' => $userId,
            'tenant_id' => $tenantId ?? $this->tenantId,
        ]);

        return $result->map(self::hydrate(...));
    }

    public function hasBadge(string $userId, Badge $badge, ?string $tenantId = null): bool
    {
        $result = $this->connection->query(self::SQL_HAS_BADGE, [
            'user_id' => $userId,
            'badge' => $badge->value,
            'tenant_id' => $tenantId ?? $this->tenantId,
        ]);

        return ($result->first()?->getInt('total') ?? 0) > 0;
    }

    public function save(UserBadge $userBadge): void
    {
        $this->connection->execute(self::SQL_UPSERT, [
            'id' => $userBadge->id,
            'tenant_id' => $userBadge->tenantId,
            'user_id' => $userBadge->userId,
            'badge' => $userBadge->badge->value,
            'awarded_at' => $userBadge->awardedAt->format('c'),
        ]);
    }

    public function delete(UserBadge $userBadge): void
    {
        $this->connection->execute(self::SQL_DELETE, ['id' => $userBadge->id]);
    }

    private static function hydrate(Row $row): UserBadge
    {
        return new UserBadge(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            userId: $row->getString('user_id'),
            badge: Badge::from($row->getString('badge')),
            awardedAt: new DateTimeImmutable($row->getString('awarded_at')),
        );
    }
}
