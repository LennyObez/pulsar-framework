<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\SavedViews;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\SavedView;
use Pulsar\Extension\Admin\Features\SavedViews\SavedViewsHandler;
use Pulsar\Extension\Admin\Features\SavedViews\SavedViewsRequest;
use Pulsar\Extension\Admin\Features\SavedViews\SavedViewsResult;
use Pulsar\Extension\Admin\Internal\Storage\SavedViewStoreInterface;

final class SavedViewsHandlerTest extends TestCase
{
    private SavedViewStoreInterface&Stub $store;
    private SavedViewsHandler $handler;

    protected function setUp(): void
    {
        $this->store = $this->createStub(SavedViewStoreInterface::class);
        $this->handler = new SavedViewsHandler($this->store);
    }

    #[Test]
    public function list_returns_views_for_resource(): void
    {
        $view = new SavedView(
            id: 'v1',
            resourceName: 'users',
            label: 'Active Users',
            filters: ['status' => 'active'],
            sort: ['name' => 'asc'],
            perPage: 25,
            createdBy: 'admin',
        );

        $this->store->method('listForResource')->willReturn([$view]);

        $request = new SavedViewsRequest(operation: 'list', resourceName: 'users');
        $result = $this->handler->execute($request);

        self::assertInstanceOf(SavedViewsResult::class, $result);
        self::assertTrue($result->success);
        self::assertCount(1, $result->views);
        self::assertSame('v1', $result->views[0]->id);
    }

    #[Test]
    public function get_returns_view_when_found(): void
    {
        $view = new SavedView(
            id: 'v1',
            resourceName: 'users',
            label: 'Test View',
            filters: [],
            sort: [],
            perPage: 10,
            createdBy: 'admin',
        );

        $this->store->method('find')->willReturn($view);

        $request = new SavedViewsRequest(operation: 'get', viewId: 'v1');
        $result = $this->handler->execute($request);

        self::assertTrue($result->success);
        self::assertNotNull($result->view);
        self::assertSame('v1', $result->view->id);
    }

    #[Test]
    public function get_returns_unsuccessful_when_not_found(): void
    {
        $this->store->method('find')->willReturn(null);

        $request = new SavedViewsRequest(operation: 'get', viewId: 'missing');
        $result = $this->handler->execute($request);

        self::assertFalse($result->success);
        self::assertNull($result->view);
    }

    #[Test]
    public function save_persists_view_and_returns_it(): void
    {
        $view = new SavedView(
            id: 'v2',
            resourceName: 'orders',
            label: 'Recent Orders',
            filters: ['date' => '2026-01-01'],
            sort: ['created_at' => 'desc'],
            perPage: 50,
            createdBy: 'admin',
        );

        $request = new SavedViewsRequest(operation: 'save', view: $view);
        $result = $this->handler->execute($request);

        self::assertTrue($result->success);
        self::assertNotNull($result->view);
        self::assertSame('v2', $result->view->id);
    }

    #[Test]
    public function save_returns_unsuccessful_when_view_is_null(): void
    {
        $request = new SavedViewsRequest(operation: 'save');
        $result = $this->handler->execute($request);

        self::assertFalse($result->success);
    }

    #[Test]
    public function delete_succeeds(): void
    {
        $request = new SavedViewsRequest(operation: 'delete', viewId: 'v1');
        $result = $this->handler->execute($request);

        self::assertTrue($result->success);
    }
}
