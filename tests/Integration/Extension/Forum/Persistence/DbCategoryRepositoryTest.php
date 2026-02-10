<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Forum\Persistence;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\Forum\Category\Category;
use Pulsar\Extension\Forum\Internal\Persistence\DbCategoryRepository;

#[CoversClass(DbCategoryRepository::class)]
final class DbCategoryRepositoryTest extends TestCase
{
    private PdoConnection $connection;
    private DbCategoryRepository $repository;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $this->connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS forum_categories (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                tenant_id VARCHAR(36) DEFAULT NULL,
                parent_id VARCHAR(36) DEFAULT NULL,
                name VARCHAR(200) NOT NULL DEFAULT '',
                slug VARCHAR(200) NOT NULL,
                description TEXT DEFAULT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                is_locked INTEGER NOT NULL DEFAULT 0,
                thread_count INTEGER NOT NULL DEFAULT 0,
                post_count INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                deleted_at TEXT DEFAULT NULL
            )
            SQL);

        $this->repository = new DbCategoryRepository($this->connection, null);
    }

    #[Test]
    public function saveAndFindById(): void
    {
        $category = Category::create(id: 'cat-001', slug: 'general');
        $this->repository->save($category);

        $found = $this->repository->findById('cat-001');

        self::assertNotNull($found);
        self::assertSame('cat-001', $found->id);
        self::assertSame('general', $found->slug);
        self::assertFalse($found->isLocked);
        self::assertSame(0, $found->sortOrder);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findById('nonexistent'));
    }

    #[Test]
    public function findBySlugReturnsCategory(): void
    {
        $category = Category::create(id: 'cat-002', slug: 'php-help');
        $this->repository->save($category);

        $found = $this->repository->findBySlug('php-help');

        self::assertNotNull($found);
        self::assertSame('cat-002', $found->id);
        self::assertSame('php-help', $found->slug);
    }

    #[Test]
    public function findBySlugReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findBySlug('nonexistent'));
    }

    #[Test]
    public function findRootsReturnsOnlyTopLevelCategories(): void
    {
        $root1 = Category::create(id: 'root-1', slug: 'general', sortOrder: 1);
        $root2 = Category::create(id: 'root-2', slug: 'support', sortOrder: 2);
        $child = new Category(
            id: 'child-1',
            tenantId: null,
            parentId: 'root-1',
            slug: 'sub-general',
            sortOrder: 0,
            isLocked: false,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        $this->repository->save($root1);
        $this->repository->save($root2);
        $this->repository->save($child);

        $roots = $this->repository->findRoots();

        self::assertCount(2, $roots);
        self::assertSame('root-1', $roots[0]->id);
        self::assertSame('root-2', $roots[1]->id);
    }

    #[Test]
    public function findRootsOrdersBySortOrder(): void
    {
        $cat1 = Category::create(id: 'cat-a', slug: 'first', sortOrder: 10);
        $cat2 = Category::create(id: 'cat-b', slug: 'second', sortOrder: 5);
        $cat3 = Category::create(id: 'cat-c', slug: 'third', sortOrder: 20);

        $this->repository->save($cat1);
        $this->repository->save($cat2);
        $this->repository->save($cat3);

        $roots = $this->repository->findRoots();

        self::assertCount(3, $roots);
        self::assertSame('cat-b', $roots[0]->id);
        self::assertSame('cat-a', $roots[1]->id);
        self::assertSame('cat-c', $roots[2]->id);
    }

    #[Test]
    public function findByParentReturnsChildren(): void
    {
        $root = Category::create(id: 'root-1', slug: 'general');
        $child1 = new Category(
            id: 'child-1',
            tenantId: null,
            parentId: 'root-1',
            slug: 'sub-a',
            sortOrder: 2,
            isLocked: false,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );
        $child2 = new Category(
            id: 'child-2',
            tenantId: null,
            parentId: 'root-1',
            slug: 'sub-b',
            sortOrder: 1,
            isLocked: false,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        $this->repository->save($root);
        $this->repository->save($child1);
        $this->repository->save($child2);

        $children = $this->repository->findByParent('root-1');

        self::assertCount(2, $children);
        self::assertSame('child-2', $children[0]->id);
        self::assertSame('child-1', $children[1]->id);
    }

    #[Test]
    public function saveUpdatesExistingCategory(): void
    {
        $category = Category::create(id: 'cat-u', slug: 'original');
        $this->repository->save($category);

        $updated = new Category(
            id: 'cat-u',
            tenantId: null,
            parentId: null,
            slug: 'renamed',
            sortOrder: 5,
            isLocked: true,
            createdAt: $category->createdAt,
            updatedAt: new DateTimeImmutable(),
        );
        $this->repository->save($updated);

        $found = $this->repository->findById('cat-u');

        self::assertNotNull($found);
        self::assertSame('renamed', $found->slug);
        self::assertSame(5, $found->sortOrder);
        self::assertTrue($found->isLocked);
    }

    #[Test]
    public function deleteRemovesCategory(): void
    {
        $category = Category::create(id: 'cat-del', slug: 'to-delete');
        $this->repository->save($category);

        self::assertNotNull($this->repository->findById('cat-del'));

        $this->repository->delete($category);

        self::assertNull($this->repository->findById('cat-del'));
    }

    #[Test]
    public function findByParentReturnsEmptyForNoChildren(): void
    {
        $root = Category::create(id: 'lonely', slug: 'lonely');
        $this->repository->save($root);

        $children = $this->repository->findByParent('lonely');

        self::assertCount(0, $children);
    }
}
