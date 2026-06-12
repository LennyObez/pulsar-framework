<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\MutationContext;
use Pulsar\Extension\Admin\Domain\ActionResult;
use Pulsar\Extension\Admin\Domain\BulkAction;
use Pulsar\Extension\Admin\Domain\ExportFormat;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Domain\ListResourceResult;
use Pulsar\Extension\Admin\Features\BulkAction\BulkActionRequest;
use Pulsar\Extension\Admin\Features\BulkAction\BulkActionResult;
use Pulsar\Extension\Admin\Features\CreateResource\CreateResourceRequest;
use Pulsar\Extension\Admin\Features\CreateResource\CreateResourceResult;
use Pulsar\Extension\Admin\Features\Dashboard\DashboardResult;
use Pulsar\Extension\Admin\Features\DeleteResource\DeleteResourceRequest;
use Pulsar\Extension\Admin\Features\DeleteResource\DeleteResourceResult;
use Pulsar\Extension\Admin\Features\ExportResource\ExportResourceRequest;
use Pulsar\Extension\Admin\Features\ExportResource\ExportResourceResult;
use Pulsar\Extension\Admin\Features\ListResource\ListResourceRequest;
use Pulsar\Extension\Admin\Features\SavedViews\SavedViewsRequest;
use Pulsar\Extension\Admin\Features\SavedViews\SavedViewsResult;
use Pulsar\Extension\Admin\Features\UpdateResource\UpdateResourceRequest;
use Pulsar\Extension\Admin\Features\UpdateResource\UpdateResourceResult;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryEntry;
use Pulsar\Extension\Admin\Internal\Storage\SchemaChangeLogEntry;
use ReflectionClass;

#[CoversClass(BulkAction::class)]
#[CoversClass(BulkActionRequest::class)]
#[CoversClass(BulkActionResult::class)]
#[CoversClass(CreateResourceRequest::class)]
#[CoversClass(CreateResourceResult::class)]
#[CoversClass(DashboardResult::class)]
#[CoversClass(DeleteResourceRequest::class)]
#[CoversClass(DeleteResourceResult::class)]
#[CoversClass(ExportResourceRequest::class)]
#[CoversClass(ExportResourceResult::class)]
#[CoversClass(FieldType::class)]
#[CoversClass(ListResourceRequest::class)]
#[CoversClass(ListResourceResult::class)]
#[CoversClass(SavedViewsRequest::class)]
#[CoversClass(SavedViewsResult::class)]
#[CoversClass(UpdateResourceRequest::class)]
#[CoversClass(UpdateResourceResult::class)]
#[CoversClass(ActionHistoryEntry::class)]
#[CoversClass(SchemaChangeLogEntry::class)]
final class AdminDtosTest extends TestCase
{
    // --- BulkAction ---

    #[Test]
    public function bulkActionWithDefaults(): void
    {
        $action = new BulkAction(name: 'delete', label: 'Delete Selected');

        self::assertSame('delete', $action->name);
        self::assertSame('Delete Selected', $action->label);
        self::assertFalse($action->destructive);
        self::assertTrue($action->requireConfirmation);
        self::assertNull($action->icon);
    }

    #[Test]
    public function bulkActionWithAllFields(): void
    {
        $action = new BulkAction(
            name: 'archive',
            label: 'Archive',
            destructive: true,
            requireConfirmation: false,
            icon: 'archive-box',
        );

        self::assertTrue($action->destructive);
        self::assertFalse($action->requireConfirmation);
        self::assertSame('archive-box', $action->icon);
    }

    // --- BulkActionRequest ---

    #[Test]
    public function bulkActionRequestConstruction(): void
    {
        $context = MutationContext::system('test');
        $request = new BulkActionRequest(
            resourceName: 'users',
            action: 'deactivate',
            ids: ['1', '2', '3'],
            parameters: ['reason' => 'policy violation'],
            context: $context,
        );

        self::assertSame('users', $request->resourceName);
        self::assertSame('deactivate', $request->action);
        self::assertCount(3, $request->ids);
        self::assertSame('policy violation', $request->parameters['reason']);
    }

    // --- BulkActionResult ---

    #[Test]
    public function bulkActionResultWrapsActionResult(): void
    {
        $inner = ActionResult::success('3 items deleted');
        $result = new BulkActionResult(result: $inner);

        self::assertTrue($result->result->success);
        self::assertSame('3 items deleted', $result->result->message);
    }

    // --- CreateResourceRequest ---

    #[Test]
    public function createResourceRequestConstruction(): void
    {
        $context = MutationContext::system('api create');
        $request = new CreateResourceRequest(
            resourceName: 'posts',
            data: ['title' => 'Hello', 'body' => 'World'],
            context: $context,
        );

        self::assertSame('posts', $request->resourceName);
        self::assertSame('Hello', $request->data['title']);
        self::assertSame('system', $request->context->actor);
    }

    // --- CreateResourceResult ---

    #[Test]
    public function createResourceResultWrapsActionResult(): void
    {
        $result = new CreateResourceResult(
            result: ActionResult::success('Created', ['id' => '42']),
        );

        self::assertTrue($result->result->success);
        self::assertSame('42', $result->result->metadata['id']);
    }

    // --- DashboardResult ---

    #[Test]
    public function dashboardResultConstruction(): void
    {
        $result = new DashboardResult(
            widgets: [['id' => 'w1', 'label' => 'Stats', 'size' => 'full', 'data' => []]],
            resources: [['name' => 'users', 'label' => 'Users', 'icon' => 'users']],
        );

        self::assertCount(1, $result->widgets);
        self::assertSame('w1', $result->widgets[0]['id']);
        self::assertCount(1, $result->resources);
    }

    // --- DeleteResourceRequest ---

    #[Test]
    public function deleteResourceRequestConstruction(): void
    {
        $context = MutationContext::system('cleanup');
        $request = new DeleteResourceRequest(
            resourceName: 'posts',
            id: '42',
            context: $context,
        );

        self::assertSame('posts', $request->resourceName);
        self::assertSame('42', $request->id);
    }

    // --- DeleteResourceResult ---

    #[Test]
    public function deleteResourceResultWrapsActionResult(): void
    {
        $result = new DeleteResourceResult(
            result: ActionResult::failure('Not found'),
        );

        self::assertFalse($result->result->success);
    }

    // --- ExportResourceRequest ---

    #[Test]
    public function exportResourceRequestWithDefaults(): void
    {
        $request = new ExportResourceRequest(
            resourceName: 'orders',
            format: ExportFormat::Csv,
        );

        self::assertSame('orders', $request->resourceName);
        self::assertSame(ExportFormat::Csv, $request->format);
        self::assertSame([], $request->filters);
        self::assertSame(10000, $request->maxRows);
    }

    #[Test]
    public function exportResourceRequestWithCustomValues(): void
    {
        $request = new ExportResourceRequest(
            resourceName: 'users',
            format: ExportFormat::Json,
            filters: ['active' => true],
            maxRows: 500,
        );

        self::assertSame(ExportFormat::Json, $request->format);
        self::assertSame(500, $request->maxRows);
        self::assertTrue($request->filters['active']);
    }

    // --- ExportResourceResult ---

    #[Test]
    public function exportResourceResultConstruction(): void
    {
        $result = new ExportResourceResult(
            content: 'id,name\n1,Alice',
            mimeType: 'text/csv',
            filename: 'users.csv',
            evidenceHash: 'abc123',
            rowCount: 1,
        );

        self::assertSame('text/csv', $result->mimeType);
        self::assertSame('users.csv', $result->filename);
        self::assertSame('abc123', $result->evidenceHash);
        self::assertSame(1, $result->rowCount);
    }

    // --- FieldType ---

    #[Test]
    public function fieldTypeCaseCount(): void
    {
        self::assertCount(31, FieldType::cases());
    }

    #[Test]
    public function fieldTypeStringValues(): void
    {
        self::assertSame('string', FieldType::String->value);
        self::assertSame('integer', FieldType::Integer->value);
        self::assertSame('boolean', FieldType::Boolean->value);
        self::assertSame('json', FieldType::Json->value);
        self::assertSame('relation', FieldType::Relation->value);
    }

    // --- ListResourceRequest ---

    #[Test]
    public function listResourceRequestWithDefaults(): void
    {
        $request = new ListResourceRequest(resourceName: 'products');

        self::assertSame('products', $request->resourceName);
        self::assertSame([], $request->filters);
        self::assertSame([], $request->sort);
        self::assertSame(1, $request->page);
        self::assertSame(25, $request->perPage);
    }

    #[Test]
    public function listResourceRequestWithCustomValues(): void
    {
        $request = new ListResourceRequest(
            resourceName: 'orders',
            filters: ['status' => 'pending'],
            sort: ['created_at' => 'desc'],
            page: 3,
            perPage: 50,
        );

        self::assertSame(3, $request->page);
        self::assertSame(50, $request->perPage);
        self::assertSame('desc', $request->sort['created_at']);
    }

    // --- ListResourceResult ---

    #[Test]
    public function listResourceResultConstruction(): void
    {
        $result = new ListResourceResult(
            data: [['id' => 1, 'name' => 'Alice']],
            total: 100,
            page: 2,
            perPage: 25,
            totalPages: 4,
        );

        self::assertCount(1, $result->data);
        self::assertSame(100, $result->total);
        self::assertSame(4, $result->totalPages);
    }

    // --- SavedViewsRequest ---

    #[Test]
    public function savedViewsRequestForList(): void
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

    // --- SavedViewsResult ---

    #[Test]
    public function savedViewsResultSuccess(): void
    {
        $result = new SavedViewsResult(success: true, views: []);

        self::assertTrue($result->success);
        self::assertSame([], $result->views);
        self::assertNull($result->view);
    }

    // --- UpdateResourceRequest ---

    #[Test]
    public function updateResourceRequestConstruction(): void
    {
        $context = MutationContext::system('api update');
        $request = new UpdateResourceRequest(
            resourceName: 'posts',
            id: '42',
            data: ['title' => 'Updated'],
            context: $context,
        );

        self::assertSame('42', $request->id);
        self::assertSame('Updated', $request->data['title']);
    }

    // --- UpdateResourceResult ---

    #[Test]
    public function updateResourceResultWrapsActionResult(): void
    {
        $result = new UpdateResourceResult(
            result: ActionResult::success('Updated'),
        );

        self::assertTrue($result->result->success);
    }

    // --- ActionHistoryEntry ---

    #[Test]
    public function actionHistoryEntryConstruction(): void
    {
        $entry = new ActionHistoryEntry(
            id: 'ah-1',
            action: 'create',
            resourceName: 'users',
            recordId: '42',
            actor: 'admin@example.com',
            timestamp: 1709856000,
            success: true,
            detail: 'Created user Alice',
        );

        self::assertSame('ah-1', $entry->id);
        self::assertSame('create', $entry->action);
        self::assertSame('users', $entry->resourceName);
        self::assertSame('42', $entry->recordId);
        self::assertSame('admin@example.com', $entry->actor);
        self::assertSame(1709856000, $entry->timestamp);
        self::assertTrue($entry->success);
        self::assertSame('Created user Alice', $entry->detail);
    }

    #[Test]
    public function actionHistoryEntryDefaultDetail(): void
    {
        $entry = new ActionHistoryEntry(
            id: 'ah-2',
            action: 'delete',
            resourceName: 'posts',
            recordId: null,
            actor: 'system',
            timestamp: 0,
            success: false,
        );

        self::assertSame('', $entry->detail);
        self::assertNull($entry->recordId);
    }

    // --- SchemaChangeLogEntry ---

    #[Test]
    public function schemaChangeLogEntryConstruction(): void
    {
        $entry = new SchemaChangeLogEntry(
            id: 'scl-1',
            operation: 'create_table',
            table: 'products',
            actor: 'admin',
            reason: 'New feature',
            timestamp: 1709856000,
            statements: ['CREATE TABLE products (id INT PRIMARY KEY)'],
            evidenceHash: 'sha256:abc',
            correlationId: 'req-123',
            success: true,
        );

        self::assertSame('scl-1', $entry->id);
        self::assertSame('create_table', $entry->operation);
        self::assertSame('products', $entry->table);
        self::assertCount(1, $entry->statements);
        self::assertSame('sha256:abc', $entry->evidenceHash);
        self::assertSame('req-123', $entry->correlationId);
        self::assertTrue($entry->success);
    }
}
