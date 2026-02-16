<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Content\Redirect;
use Pulsar\Extension\Cms\Content\RedirectRepositoryInterface;

use function max;

#[Internal(reason: 'Raw-DB repository; use RedirectRepositoryInterface for public API')]
final readonly class DbRedirectRepository implements RedirectRepositoryInterface
{
    private const string SENTINEL_TENANT = '00000000-0000-0000-0000-000000000000';

    private const string SQL_FIND_BY_PATH = <<<'SQL'
        SELECT * FROM cms_redirects
        WHERE from_path = :from_path
          AND COALESCE(locale, '') = COALESCE(:locale, '')
          AND COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
          AND deleted_at IS NULL
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'tenant_id', 'from_path', 'to_path', 'status_code',
        'locale', 'hits', 'last_hit_at', 'created_at', 'created_by', 'reason',
    ];

    private const array UPSERT_UPDATE = [
        'to_path', 'status_code', 'reason',
    ];

    private const string SQL_FIND_ALL = <<<'SQL'
        SELECT * FROM cms_redirects
        WHERE COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
          AND deleted_at IS NULL
        ORDER BY created_at DESC
        LIMIT :limit OFFSET :offset
        SQL;

    private const string SQL_INCREMENT_HITS = <<<'SQL'
        UPDATE cms_redirects
        SET hits = hits + 1, last_hit_at = :last_hit_at
        WHERE id = :id
        SQL;

    private const string SQL_DELETE = <<<'SQL'
        UPDATE cms_redirects SET deleted_at = :deleted_at WHERE id = :id
        SQL;

    private const string SQL_PURGE_DELETED = <<<'SQL'
        DELETE FROM cms_redirects WHERE deleted_at IS NOT NULL AND deleted_at < :cutoff
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
        private ?string $tenantId,
    ) {}

    public function findByPath(string $path, ?string $locale = null, ?string $tenantId = null): ?Redirect
    {
        $resolved = $tenantId ?? $this->tenantId;
        $tenantKey = $resolved ?? self::SENTINEL_TENANT;

        $result = $this->connection->query(self::SQL_FIND_BY_PATH, [
            'from_path' => $path,
            'locale' => $locale,
            'tenant_key' => $tenantKey,
        ]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function save(Redirect $redirect): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'cms_redirects',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $redirect->id,
            'tenant_id' => $redirect->tenantId,
            'from_path' => $redirect->fromPath,
            'to_path' => $redirect->toPath,
            'status_code' => $redirect->statusCode,
            'locale' => $redirect->locale,
            'hits' => $redirect->hits,
            'last_hit_at' => $redirect->lastHitAt?->format('c'),
            'created_at' => $redirect->createdAt->format('c'),
            'created_by' => $redirect->createdBy,
            'reason' => $redirect->reason,
        ]);
    }

    public function incrementHits(string $redirectId): void
    {
        $this->connection->execute(self::SQL_INCREMENT_HITS, [
            'id' => $redirectId,
            'last_hit_at' => new DateTimeImmutable()->format('c'),
        ]);
    }

    public function findAll(int $page = 1, int $perPage = 50, ?string $tenantId = null): array
    {
        $resolved = $tenantId ?? $this->tenantId;
        $tenantKey = $resolved ?? self::SENTINEL_TENANT;
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $result = $this->connection->query(self::SQL_FIND_ALL, [
            'tenant_key' => $tenantKey,
            'limit' => $perPage,
            'offset' => $offset,
        ]);

        $redirects = [];
        foreach ($result->rows as $row) {
            $redirects[] = self::hydrate($row);
        }

        return $redirects;
    }

    public function delete(string $redirectId): void
    {
        $this->connection->execute(self::SQL_DELETE, [
            'id' => $redirectId,
            'deleted_at' => new DateTimeImmutable()->format('c'),
        ]);
    }

    public function purgeDeleted(DateTimeImmutable $olderThan): int
    {
        return $this->connection->execute(self::SQL_PURGE_DELETED, [
            'cutoff' => $olderThan->format('c'),
        ]);
    }

    private static function hydrate(Row $row): Redirect
    {
        $lastHitRaw = $row->getNullableString('last_hit_at');
        $deletedAtRaw = $row->getNullableString('deleted_at');

        return new Redirect(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            fromPath: $row->getString('from_path'),
            toPath: $row->getString('to_path'),
            statusCode: $row->getInt('status_code'),
            locale: $row->getNullableString('locale'),
            hits: $row->getInt('hits'),
            lastHitAt: $lastHitRaw !== null ? new DateTimeImmutable($lastHitRaw) : null,
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            createdBy: $row->getString('created_by'),
            reason: $row->getString('reason'),
            deletedAt: $deletedAtRaw !== null ? new DateTimeImmutable($deletedAtRaw) : null,
        );
    }
}
