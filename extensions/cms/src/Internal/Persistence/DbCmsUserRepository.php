<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Users\CmsUser;
use Pulsar\Extension\Cms\Users\CmsUserRepositoryInterface;

use function ceil;
use function json_decode;
use function json_encode;
use function max;
use function str_replace;

/**
 * Database-backed CMS user repository.
 *
 * Queries the auth_users table joined with content/comment counts
 * and filtered to users who hold at least one CMS role.
 *
 * @psalm-api Bound to CmsUserRepositoryInterface in the CMS service provider;
 *            resolved from the DI container, never instantiated by name.
 */
#[Internal(reason: 'Database persistence; use CmsUserRepositoryInterface')]
final readonly class DbCmsUserRepository implements CmsUserRepositoryInterface
{
    private const string SQL_SELECT_USER = <<<'SQL'
        SELECT u.id, u.tenant_id, u.display_name, u.email, u.roles,
               u.two_factor_status, u.last_active_at, u.created_at, u.is_locked,
               COALESCE(cc.content_count, 0) AS content_count,
               COALESCE(cmt.comment_count, 0) AS comment_count
        FROM auth_users u
        LEFT JOIN (
            SELECT author_id, COUNT(*) AS content_count
            FROM cms_contents
            WHERE deleted_at IS NULL
            GROUP BY author_id
        ) cc ON cc.author_id = u.id
        LEFT JOIN (
            SELECT author_id, COUNT(*) AS comment_count
            FROM cms_comments
            WHERE deleted_at IS NULL AND author_id IS NOT NULL
            GROUP BY author_id
        ) cmt ON cmt.author_id = u.id
        SQL;

    private const string SQL_COUNT_USERS = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM auth_users u
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findById(string $id): ?CmsUser
    {
        $result = $this->connection->query(
            self::SQL_SELECT_USER . ' WHERE u.id = :id',
            ['id' => $id],
        );

        $row = $result->first();

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function listUsers(
        ?string $tenantId = null,
        ?string $role = null,
        int $page = 1,
        int $perPage = 20,
    ): PaginationResult {
        $where = [];
        $bindings = [];

        // Filter users who hold at least one cms.* role
        $where[] = "u.roles LIKE '%cms.%'";

        if ($tenantId !== null) {
            $where[] = 'u.tenant_id = :tenant_id';
            $bindings['tenant_id'] = $tenantId;
        }

        if ($role !== null) {
            $where[] = 'u.roles LIKE :role_pattern';
            $bindings['role_pattern'] = '%' . self::escapeLikePattern($role) . '%';
        }

        $whereClause = ' WHERE ' . implode(' AND ', $where);

        // Count
        $countResult = $this->connection->query(
            self::SQL_COUNT_USERS . $whereClause,
            $bindings,
        );

        $total = $countResult->first()?->getInt('total') ?? 0;
        $offset = ($page - 1) * $perPage;

        // Fetch
        $result = $this->connection->query(
            self::SQL_SELECT_USER . $whereClause . ' ORDER BY u.created_at DESC LIMIT :limit OFFSET :offset',
            [...$bindings, 'limit' => $perPage, 'offset' => $offset],
        );

        $items = $result->map($this->hydrate(...));
        $lastPage = max(1, (int) ceil($total / $perPage));

        return new PaginationResult(
            items: $items,
            total: $total,
            hasMore: $page < $lastPage,
            perPage: $perPage,
            currentPage: $page,
            lastPage: $lastPage,
        );
    }

    public function updateRoles(string $userId, array $roles): void
    {
        $rolesJson = json_encode($roles, JSON_THROW_ON_ERROR);

        $this->connection->execute(
            'UPDATE auth_users SET roles = :roles WHERE id = :id',
            ['roles' => $rolesJson, 'id' => $userId],
        );
    }

    public function resetTwoFactor(string $userId): void
    {
        $this->connection->execute(
            'UPDATE auth_users SET two_factor_status = :status WHERE id = :id',
            ['status' => TwoFactorStatus::Disabled->value, 'id' => $userId],
        );
    }

    private static function escapeLikePattern(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function hydrate(Row $row): CmsUser
    {
        $rolesRaw = $row->getNullableString('roles') ?? '[]';
        /** @var list<string> $roles */
        $roles = (array) json_decode($rolesRaw, true);

        $twoFactorRaw = $row->getNullableString('two_factor_status') ?? 'disabled';

        $lastActiveAt = $row->getNullableString('last_active_at');
        $createdAtRaw = $row->getNullableString('created_at') ?? 'now';

        return new CmsUser(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            displayName: $row->getString('display_name'),
            email: $row->getNullableString('email'),
            roles: $roles,
            twoFactorStatus: TwoFactorStatus::tryFrom($twoFactorRaw) ?? TwoFactorStatus::Disabled,
            contentCount: $row->getInt('content_count'),
            commentCount: $row->getInt('comment_count'),
            lastActiveAt: $lastActiveAt !== null ? new DateTimeImmutable($lastActiveAt) : null,
            createdAt: new DateTimeImmutable($createdAtRaw),
            isLocked: $row->getBool('is_locked'),
        );
    }
}
