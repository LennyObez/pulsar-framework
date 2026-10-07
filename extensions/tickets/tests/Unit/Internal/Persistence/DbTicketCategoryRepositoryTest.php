<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Internal\Persistence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Tickets\Domain\TicketCategory;
use Pulsar\Extension\Tickets\Internal\Persistence\DbTicketCategoryRepository;

#[CoversClass(DbTicketCategoryRepository::class)]
final class DbTicketCategoryRepositoryTest extends TestCase
{
    #[Test]
    public function findByIdReturnsCategoryWhenFound(): void
    {
        $row = new Row([
            'id' => 'cat-1',
            'name' => 'Billing',
            'slug' => 'billing',
            'description' => 'Billing issues',
            'parent_id' => null,
            'sort_order' => 0,
            'created_at' => '2026-01-01T00:00:00+00:00',
            'updated_at' => '2026-01-01T00:00:00+00:00',
        ]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([$row]));

        $repo = new DbTicketCategoryRepository($connection);
        $result = $repo->findById('cat-1');

        self::assertNotNull($result);
        self::assertSame('cat-1', $result->id);
        self::assertSame('Billing', $result->name);
        self::assertSame('billing', $result->slug);
        self::assertSame('Billing issues', $result->description);
        self::assertNull($result->parentId);
        self::assertSame(0, $result->sortOrder);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([]));

        $repo = new DbTicketCategoryRepository($connection);

        self::assertNull($repo->findById('nonexistent'));
    }

    #[Test]
    public function findBySlugReturnsCategoryWhenFound(): void
    {
        $row = new Row([
            'id' => 'cat-2',
            'name' => 'Technical',
            'slug' => 'technical',
            'description' => null,
            'parent_id' => null,
            'sort_order' => 1,
            'created_at' => '2026-02-01T00:00:00+00:00',
            'updated_at' => '2026-02-01T00:00:00+00:00',
        ]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([$row]));

        $repo = new DbTicketCategoryRepository($connection);
        $result = $repo->findBySlug('technical');

        self::assertNotNull($result);
        self::assertSame('technical', $result->slug);
        self::assertNull($result->description);
    }

    #[Test]
    public function findBySlugReturnsNullWhenNotFound(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([]));

        $repo = new DbTicketCategoryRepository($connection);

        self::assertNull($repo->findBySlug('nonexistent'));
    }

    #[Test]
    public function findAllReturnsCategories(): void
    {
        $row1 = new Row([
            'id' => 'cat-1', 'name' => 'A', 'slug' => 'a', 'description' => null,
            'parent_id' => null, 'sort_order' => 0,
            'created_at' => '2026-01-01T00:00:00+00:00', 'updated_at' => '2026-01-01T00:00:00+00:00',
        ]);
        $row2 = new Row([
            'id' => 'cat-2', 'name' => 'B', 'slug' => 'b', 'description' => null,
            'parent_id' => null, 'sort_order' => 1,
            'created_at' => '2026-01-01T00:00:00+00:00', 'updated_at' => '2026-01-01T00:00:00+00:00',
        ]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([$row1, $row2]));

        $repo = new DbTicketCategoryRepository($connection);
        $all = $repo->findAll();

        self::assertCount(2, $all);
        self::assertSame('A', $all[0]->name);
        self::assertSame('B', $all[1]->name);
    }

    #[Test]
    public function findByParentReturnsChildCategories(): void
    {
        $row = new Row([
            'id' => 'cat-3', 'name' => 'Refunds', 'slug' => 'refunds', 'description' => null,
            'parent_id' => 'cat-1', 'sort_order' => 0,
            'created_at' => '2026-01-01T00:00:00+00:00', 'updated_at' => '2026-01-01T00:00:00+00:00',
        ]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([$row]));

        $repo = new DbTicketCategoryRepository($connection);
        $children = $repo->findByParent('cat-1');

        self::assertCount(1, $children);
        self::assertSame('cat-1', $children[0]->parentId);
    }

    #[Test]
    public function findByParentWithNullReturnsRootCategories(): void
    {
        $row = new Row([
            'id' => 'cat-1', 'name' => 'Root', 'slug' => 'root', 'description' => null,
            'parent_id' => null, 'sort_order' => 0,
            'created_at' => '2026-01-01T00:00:00+00:00', 'updated_at' => '2026-01-01T00:00:00+00:00',
        ]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([$row]));

        $repo = new DbTicketCategoryRepository($connection);
        $roots = $repo->findByParent(null);

        self::assertCount(1, $roots);
        self::assertNull($roots[0]->parentId);
    }

    #[Test]
    public function saveExecutesUpsert(): void
    {
        $category = TicketCategory::create('cat-1', 'Billing', 'billing', 'Billing issues');

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->method('execute')->willReturn(1);

        $repo = new DbTicketCategoryRepository($connection);
        $repo->save($category);

        self::assertSame('cat-1', $category->id);
    }

    #[Test]
    public function deleteExecutesDeleteStatement(): void
    {
        $category = TicketCategory::create('cat-del', 'Obsolete', 'obsolete');

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('execute')->willReturn(1);

        $repo = new DbTicketCategoryRepository($connection);
        $repo->delete($category);

        self::assertSame('cat-del', $category->id);
    }
}
