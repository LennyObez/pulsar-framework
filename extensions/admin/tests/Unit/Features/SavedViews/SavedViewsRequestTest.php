<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\SavedViews;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\SavedView;
use Pulsar\Extension\Admin\Features\SavedViews\SavedViewsRequest;

#[CoversClass(SavedViewsRequest::class)]
final class SavedViewsRequestTest extends TestCase
{
    #[Test]
    public function listOperation(): void
    {
        $request = new SavedViewsRequest(
            operation: 'list',
            resourceName: 'users',
        );

        self::assertSame('list', $request->operation);
        self::assertSame('users', $request->resourceName);
        self::assertNull($request->viewId);
        self::assertNull($request->view);
    }

    #[Test]
    public function saveOperation(): void
    {
        $view = new SavedView(
            id: 'view-1',
            resourceName: 'users',
            label: 'Active Users',
            filters: ['status' => 'active'],
            sort: ['name' => 'asc'],
            perPage: 25,
            createdBy: 'admin',
        );

        $request = new SavedViewsRequest(
            operation: 'save',
            view: $view,
        );

        self::assertSame('save', $request->operation);
        self::assertSame($view, $request->view);
    }

    #[Test]
    public function deleteOperation(): void
    {
        $request = new SavedViewsRequest(
            operation: 'delete',
            viewId: 'view-42',
        );

        self::assertSame('delete', $request->operation);
        self::assertSame('view-42', $request->viewId);
    }

    #[Test]
    public function getOperation(): void
    {
        $request = new SavedViewsRequest(
            operation: 'get',
            viewId: 'view-1',
        );

        self::assertSame('get', $request->operation);
        self::assertSame('view-1', $request->viewId);
    }
}
