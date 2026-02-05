<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Internal\Storage;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\SavedView;
use Pulsar\Extension\Admin\Internal\Storage\SqliteSavedViewStore;

#[CoversClass(SqliteSavedViewStore::class)]
final class SqliteSavedViewStoreTest extends TestCase
{
    private SqliteSavedViewStore $store;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->store = new SqliteSavedViewStore($pdo);
    }

    #[Test]
    public function saveAndFind(): void
    {
        $view = new SavedView(
            id: 'v1',
            resourceName: 'users',
            label: 'Active Users',
            filters: ['status' => 'active'],
            sort: ['name' => 'asc'],
            perPage: 50,
            createdBy: 'admin',
            isDefault: false,
            createdAt: 1700000000,
        );

        $this->store->save($view);

        $found = $this->store->find('v1');
        self::assertNotNull($found);
        self::assertSame('v1', $found->id);
        self::assertSame('users', $found->resourceName);
        self::assertSame('Active Users', $found->label);
        self::assertSame(['status' => 'active'], $found->filters);
        self::assertSame(['name' => 'asc'], $found->sort);
        self::assertSame(50, $found->perPage);
        self::assertSame('admin', $found->createdBy);
        self::assertFalse($found->isDefault);
        self::assertSame(1700000000, $found->createdAt);
    }

    #[Test]
    public function findReturnsNullForMissingView(): void
    {
        $result = $this->store->find('nonexistent');
        self::assertNull($result);
    }

    #[Test]
    public function listForResourceFilters(): void
    {
        $this->store->save(new SavedView('v1', 'users', 'View A', [], [], 25, 'admin', false, 100));
        $this->store->save(new SavedView('v2', 'orders', 'View B', [], [], 25, 'admin', false, 200));
        $this->store->save(new SavedView('v3', 'users', 'View C', [], [], 25, 'admin', false, 300));

        $userViews = $this->store->listForResource('users');
        self::assertCount(2, $userViews);

        $orderViews = $this->store->listForResource('orders');
        self::assertCount(1, $orderViews);
    }

    #[Test]
    public function listForResourceOrdersDefaultFirst(): void
    {
        $this->store->save(new SavedView('v1', 'users', 'Zebra View', [], [], 25, 'admin', false, 100));
        $this->store->save(new SavedView('v2', 'users', 'Alpha View', [], [], 25, 'admin', true, 200));
        $this->store->save(new SavedView('v3', 'users', 'Beta View', [], [], 25, 'admin', false, 300));

        $views = $this->store->listForResource('users');
        self::assertCount(3, $views);
        // Default views come first
        self::assertTrue($views[0]->isDefault);
        self::assertSame('Alpha View', $views[0]->label);
    }

    #[Test]
    public function deleteRemovesView(): void
    {
        $this->store->save(new SavedView('v1', 'users', 'View A', [], [], 25, 'admin', false, 100));

        self::assertNotNull($this->store->find('v1'));

        $this->store->delete('v1');

        self::assertNull($this->store->find('v1'));
    }

    #[Test]
    public function saveUpdatesExistingView(): void
    {
        $this->store->save(new SavedView('v1', 'users', 'Original', [], [], 25, 'admin', false, 100));

        $this->store->save(new SavedView('v1', 'users', 'Updated', ['status' => 'active'], [], 50, 'admin', true, 100));

        $found = $this->store->find('v1');
        self::assertNotNull($found);
        self::assertSame('Updated', $found->label);
        self::assertSame(['status' => 'active'], $found->filters);
        self::assertSame(50, $found->perPage);
        self::assertTrue($found->isDefault);
    }

    #[Test]
    public function deleteNonExistentViewDoesNotFail(): void
    {
        // Should not throw
        $this->store->delete('nonexistent');

        self::assertNull($this->store->find('nonexistent'));
    }

    #[Test]
    public function listForResourceReturnsEmptyForUnknownResource(): void
    {
        $views = $this->store->listForResource('nonexistent');
        self::assertSame([], $views);
    }
}
