<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Internal\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Admin\Domain\SavedView;
use Pulsar\Extension\Admin\Internal\Storage\DbSavedViewStore;

#[CoversClass(DbSavedViewStore::class)]
final class DbSavedViewStoreTest extends TestCase
{
    private ConnectionInterface&MockObject $connection;
    private DbSavedViewStore $store;

    /**
     * Creates a mock connection and store for tests that need call expectations.
     */
    private function setUpMock(): void
    {
        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->store = new DbSavedViewStore($this->connection);
    }

    private function makeRow(
        string $id = 'v1',
        string $resourceName = 'users',
        string $label = 'Active Users',
        string $filters = '{"status":"active"}',
        string $sort = '{"name":"asc"}',
        int $perPage = 25,
        string $createdBy = 'admin',
        int $isDefault = 0,
        int $createdAt = 1700000000,
    ): Row {
        return new Row([
            'id' => $id,
            'resource_name' => $resourceName,
            'label' => $label,
            'filters' => $filters,
            'sort' => $sort,
            'per_page' => $perPage,
            'created_by' => $createdBy,
            'is_default' => $isDefault,
            'created_at' => $createdAt,
        ]);
    }

    #[Test]
    public function listForResourceReturnsHydratedViews(): void
    {
        $this->setUpMock();
        $row1 = $this->makeRow(id: 'v1', label: 'Active Users', isDefault: 1);
        $row2 = $this->makeRow(id: 'v2', label: 'Inactive Users', isDefault: 0);

        $this->connection->expects($this->once())
            ->method('query')
            ->with(
                'SELECT * FROM admin_saved_views WHERE resource_name = :resource ORDER BY is_default DESC, label ASC',
                ['resource' => 'users'],
            )
            ->willReturn(new Result([$row1, $row2]));

        $views = $this->store->listForResource('users');

        self::assertCount(2, $views);
        self::assertSame('v1', $views[0]->id);
        self::assertSame('Active Users', $views[0]->label);
        self::assertTrue($views[0]->isDefault);
        self::assertSame('v2', $views[1]->id);
        self::assertSame('Inactive Users', $views[1]->label);
        self::assertFalse($views[1]->isDefault);
    }

    #[Test]
    public function listForResourceReturnsEmptyForUnknownResource(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([]));
        $store = new DbSavedViewStore($connection);

        $views = $store->listForResource('nonexistent');

        self::assertSame([], $views);
    }

    #[Test]
    public function findReturnsHydratedViewWhenFound(): void
    {
        $this->setUpMock();
        $row = $this->makeRow(
            id: 'v1',
            label: 'My View',
            filters: '{"role":"admin"}',
            sort: '{"email":"desc"}',
            perPage: 50,
            createdBy: 'jane',
            isDefault: 1,
            createdAt: 1700001000,
        );

        $this->connection->expects($this->once())
            ->method('query')
            ->with(
                'SELECT * FROM admin_saved_views WHERE id = :id',
                ['id' => 'v1'],
            )
            ->willReturn(new Result([$row]));

        $view = $this->store->find('v1');

        self::assertNotNull($view);
        self::assertSame('v1', $view->id);
        self::assertSame('users', $view->resourceName);
        self::assertSame('My View', $view->label);
        self::assertSame(['role' => 'admin'], $view->filters);
        self::assertSame(['email' => 'desc'], $view->sort);
        self::assertSame(50, $view->perPage);
        self::assertSame('jane', $view->createdBy);
        self::assertTrue($view->isDefault);
        self::assertSame(1700001000, $view->createdAt);
    }

    #[Test]
    public function findReturnsNullWhenNotFound(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([]));
        $store = new DbSavedViewStore($connection);

        $view = $store->find('nonexistent');

        self::assertNull($view);
    }

    #[Test]
    public function saveInsertsNewView(): void
    {
        $this->setUpMock();
        // find() will return empty result (no existing view)
        $this->connection->method('query')->willReturn(new Result([]));

        $view = new SavedView(
            id: 'v-new',
            resourceName: 'orders',
            label: 'Pending Orders',
            filters: ['status' => 'pending'],
            sort: ['created_at' => 'desc'],
            perPage: 30,
            createdBy: 'admin',
            isDefault: false,
            createdAt: 1700002000,
        );

        $this->connection->expects($this->once())
            ->method('execute')
            ->with(
                'INSERT INTO admin_saved_views (id, resource_name, label, filters, sort, per_page, created_by, is_default, created_at) VALUES (:id, :resource_name, :label, :filters, :sort, :per_page, :created_by, :is_default, :created_at)',
                [
                    'id' => 'v-new',
                    'resource_name' => 'orders',
                    'label' => 'Pending Orders',
                    'filters' => '{"status":"pending"}',
                    'sort' => '{"created_at":"desc"}',
                    'per_page' => 30,
                    'created_by' => 'admin',
                    'is_default' => 0,
                    'created_at' => 1700002000,
                ],
            )
            ->willReturn(1);

        $this->store->save($view);
    }

    #[Test]
    public function saveUpdatesExistingView(): void
    {
        $this->setUpMock();
        $existingRow = $this->makeRow(id: 'v-existing');

        // find() returns an existing row
        $this->connection->method('query')->willReturn(new Result([$existingRow]));

        $view = new SavedView(
            id: 'v-existing',
            resourceName: 'users',
            label: 'Updated Label',
            filters: ['active' => true],
            sort: ['name' => 'asc'],
            perPage: 50,
            createdBy: 'admin',
            isDefault: true,
            createdAt: 1700000000,
        );

        $this->connection->expects($this->once())
            ->method('execute')
            ->with(
                'UPDATE admin_saved_views SET label = :label, filters = :filters, sort = :sort, per_page = :per_page, is_default = :is_default WHERE id = :id',
                [
                    'id' => 'v-existing',
                    'label' => 'Updated Label',
                    'filters' => '{"active":true}',
                    'sort' => '{"name":"asc"}',
                    'per_page' => 50,
                    'is_default' => 1,
                ],
            )
            ->willReturn(1);

        $this->store->save($view);
    }

    #[Test]
    public function deleteExecutesDeleteQuery(): void
    {
        $this->setUpMock();
        $this->connection->expects($this->once())
            ->method('execute')
            ->with(
                'DELETE FROM admin_saved_views WHERE id = :id',
                ['id' => 'v1'],
            )
            ->willReturn(1);

        $this->store->delete('v1');
    }

    #[Test]
    public function hydrateDecodesJsonFieldsCorrectly(): void
    {
        $row = $this->makeRow(
            filters: '{"category":"electronics","price_min":100}',
            sort: '{"price":"asc","name":"desc"}',
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([$row]));
        $store = new DbSavedViewStore($connection);

        $view = $store->find('v1');

        self::assertNotNull($view);
        self::assertSame(['category' => 'electronics', 'price_min' => 100], $view->filters);
        self::assertSame(['price' => 'asc', 'name' => 'desc'], $view->sort);
    }

    #[Test]
    public function hydrateHandlesEmptyJsonObjects(): void
    {
        $row = $this->makeRow(
            filters: '{}',
            sort: '{}',
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([$row]));
        $store = new DbSavedViewStore($connection);

        $view = $store->find('v1');

        self::assertNotNull($view);
        self::assertSame([], $view->filters);
        self::assertSame([], $view->sort);
    }

    #[Test]
    public function saveInsertsWithDefaultFalseAsZero(): void
    {
        $this->setUpMock();
        $this->connection->method('query')->willReturn(new Result([]));

        $view = new SavedView(
            id: 'v-def',
            resourceName: 'items',
            label: 'Default Test',
            filters: [],
            sort: [],
            perPage: 25,
            createdBy: 'system',
            isDefault: false,
            createdAt: 1700003000,
        );

        $this->connection->expects($this->once())
            ->method('execute')
            ->with(
                self::stringContains('INSERT INTO'),
                self::callback(static function (array $params): bool {
                    return $params['is_default'] === 0;
                }),
            )
            ->willReturn(1);

        $this->store->save($view);
    }

    #[Test]
    public function saveInsertsWithDefaultTrueAsOne(): void
    {
        $this->setUpMock();
        $this->connection->method('query')->willReturn(new Result([]));

        $view = new SavedView(
            id: 'v-def',
            resourceName: 'items',
            label: 'Default Test',
            filters: [],
            sort: [],
            perPage: 25,
            createdBy: 'system',
            isDefault: true,
            createdAt: 1700003000,
        );

        $this->connection->expects($this->once())
            ->method('execute')
            ->with(
                self::stringContains('INSERT INTO'),
                self::callback(static function (array $params): bool {
                    return $params['is_default'] === 1;
                }),
            )
            ->willReturn(1);

        $this->store->save($view);
    }

    #[Test]
    public function listForResourceHydratesAllFields(): void
    {
        $row = $this->makeRow(
            id: 'v-full',
            resourceName: 'products',
            label: 'All Products',
            filters: '{"active":true}',
            sort: '{"name":"asc"}',
            perPage: 100,
            createdBy: 'manager',
            isDefault: 1,
            createdAt: 1700005000,
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(new Result([$row]));
        $store = new DbSavedViewStore($connection);

        $views = $store->listForResource('products');

        self::assertCount(1, $views);
        $view = $views[0];
        self::assertSame('v-full', $view->id);
        self::assertSame('products', $view->resourceName);
        self::assertSame('All Products', $view->label);
        self::assertSame(['active' => true], $view->filters);
        self::assertSame(['name' => 'asc'], $view->sort);
        self::assertSame(100, $view->perPage);
        self::assertSame('manager', $view->createdBy);
        self::assertTrue($view->isDefault);
        self::assertSame(1700005000, $view->createdAt);
    }
}
