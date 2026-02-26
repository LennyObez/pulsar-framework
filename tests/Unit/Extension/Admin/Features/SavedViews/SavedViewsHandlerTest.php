<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Features\SavedViews;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\SavedView;
use Pulsar\Extension\Admin\Features\SavedViews\SavedViewsHandler;
use Pulsar\Extension\Admin\Features\SavedViews\SavedViewsRequest;
use Pulsar\Extension\Admin\Internal\Storage\SavedViewStoreInterface;

#[CoversClass(SavedViewsHandler::class)]
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
    public function listsViewsForResource(): void
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

        $this->store->method('listForResource')
            ->willReturn([$view]);

        $request = new SavedViewsRequest(
            operation: 'list',
            resourceName: 'users',
        );

        $result = $this->handler->execute($request);

        self::assertTrue($result->success);
        self::assertCount(1, $result->views);
        self::assertSame('Active Users', $result->views[0]->label);
    }

    #[Test]
    public function getsViewById(): void
    {
        $view = new SavedView(
            id: 'v1',
            resourceName: 'users',
            label: 'Active Users',
            filters: [],
            sort: [],
            perPage: 25,
            createdBy: 'admin',
        );

        $this->store->method('find')->willReturn($view);

        $request = new SavedViewsRequest(
            operation: 'get',
            viewId: 'v1',
        );

        $result = $this->handler->execute($request);

        self::assertTrue($result->success);
        self::assertNotNull($result->view);
        self::assertSame('v1', $result->view->id);
    }

    #[Test]
    public function getReturnsNotFoundWhenViewMissing(): void
    {
        $this->store->method('find')->willReturn(null);

        $request = new SavedViewsRequest(
            operation: 'get',
            viewId: 'nonexistent',
        );

        $result = $this->handler->execute($request);

        self::assertFalse($result->success);
        self::assertNull($result->view);
    }

    #[Test]
    public function savesView(): void
    {
        $view = new SavedView(
            id: 'v1',
            resourceName: 'users',
            label: 'My View',
            filters: ['role' => 'admin'],
            sort: ['name' => 'asc'],
            perPage: 50,
            createdBy: 'admin',
        );

        /** @var SavedViewStoreInterface&MockObject $store */
        $store = $this->createMock(SavedViewStoreInterface::class);
        $store->expects($this->once())
            ->method('save')
            ->with($view);

        $handler = new SavedViewsHandler($store);

        $request = new SavedViewsRequest(
            operation: 'save',
            view: $view,
        );

        $result = $handler->execute($request);

        self::assertTrue($result->success);
        self::assertNotNull($result->view);
        self::assertSame('My View', $result->view->label);
    }

    #[Test]
    public function saveFailsWhenViewIsNull(): void
    {
        /** @var SavedViewStoreInterface&MockObject $store */
        $store = $this->createMock(SavedViewStoreInterface::class);
        $store->expects($this->never())->method('save');

        $handler = new SavedViewsHandler($store);

        $request = new SavedViewsRequest(
            operation: 'save',
            view: null,
        );

        $result = $handler->execute($request);

        self::assertFalse($result->success);
    }

    #[Test]
    public function deletesView(): void
    {
        /** @var SavedViewStoreInterface&MockObject $store */
        $store = $this->createMock(SavedViewStoreInterface::class);
        $store->expects($this->once())
            ->method('delete')
            ->with('v1');

        $handler = new SavedViewsHandler($store);

        $request = new SavedViewsRequest(
            operation: 'delete',
            viewId: 'v1',
        );

        $result = $handler->execute($request);

        self::assertTrue($result->success);
    }

    #[Test]
    public function listReturnsEmptyArrayForUnknownResource(): void
    {
        $this->store->method('listForResource')
            ->willReturn([]);

        $request = new SavedViewsRequest(
            operation: 'list',
            resourceName: 'nonexistent',
        );

        $result = $this->handler->execute($request);

        self::assertTrue($result->success);
        self::assertSame([], $result->views);
    }
}
