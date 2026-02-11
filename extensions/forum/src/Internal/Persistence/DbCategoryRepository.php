<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Forum\Category\Category;
use Pulsar\Extension\Forum\Category\CategoryRepositoryInterface;

#[Internal(reason: 'Raw-DB repository — use CategoryRepositoryInterface for public API')]
final readonly class DbCategoryRepository implements CategoryRepositoryInterface
{
    private const string SENTINEL_TENANT = '00000000-0000-0000-0000-000000000000';

    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT c.*
        FROM forum_categories c
        WHERE c.id = :id
        SQL;

    private const string SQL_FIND_BY_SLUG = <<<'SQL'
        SELECT c.*
        FROM forum_categories c
        WHERE c.slug = :slug
            AND COALESCE(c.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
        SQL;

    private const string SQL_FIND_ROOTS = <<<'SQL'
        SELECT c.*
        FROM forum_categories c
        WHERE c.parent_id IS NULL
            AND COALESCE(c.tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
        ORDER BY c.sort_order ASC
        SQL;

    private const string SQL_FIND_BY_PARENT = <<<'SQL'
        SELECT c.*
        FROM forum_categories c
        WHERE c.parent_id = :parent_id
        ORDER BY c.sort_order ASC
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'tenant_id', 'parent_id', 'slug', 'sort_order',
        'is_locked', 'created_at', 'updated_at',
    ];

    private const array UPSERT_UPDATE = [
        'parent_id', 'slug', 'sort_order', 'is_locked', 'updated_at',
    ];

    private const string SQL_DELETE = <<<'SQL'
        DELETE FROM forum_categories WHERE id = :id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
        private ?string $tenantId,
    ) {}

    public function findById(string $id): ?Category
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findBySlug(string $slug, ?string $tenantId = null): ?Category
    {
        $result = $this->connection->query(self::SQL_FIND_BY_SLUG, [
            'slug' => $slug,
            'tenant_key' => $tenantId ?? $this->tenantId ?? self::SENTINEL_TENANT,
        ]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findRoots(?string $tenantId = null): array
    {
        $result = $this->connection->query(self::SQL_FIND_ROOTS, [
            'tenant_key' => $tenantId ?? $this->tenantId ?? self::SENTINEL_TENANT,
        ]);

        return $result->map(self::hydrate(...));
    }

    public function findByParent(string $parentId): array
    {
        $result = $this->connection->query(self::SQL_FIND_BY_PARENT, [
            'parent_id' => $parentId,
        ]);

        return $result->map(self::hydrate(...));
    }

    public function save(Category $category): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'forum_categories',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $category->id,
            'tenant_id' => $category->tenantId,
            'parent_id' => $category->parentId,
            'slug' => $category->slug,
            'sort_order' => $category->sortOrder,
            'is_locked' => $category->isLocked,
            'created_at' => $category->createdAt->format('c'),
            'updated_at' => $category->updatedAt->format('c'),
        ]);
    }

    public function delete(Category $category): void
    {
        $this->connection->execute(self::SQL_DELETE, ['id' => $category->id]);
    }

    private static function hydrate(Row $row): Category
    {
        return new Category(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            parentId: $row->getNullableString('parent_id'),
            slug: $row->getString('slug'),
            sortOrder: $row->getInt('sort_order'),
            isLocked: $row->getBool('is_locked'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            updatedAt: new DateTimeImmutable($row->getString('updated_at')),
        );
    }
}
