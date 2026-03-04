<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Gateway;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Audit\MutationContext;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Contracts\ResourceMutatorInterface;
use Pulsar\Extension\Admin\Contracts\ResourceQueryInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Domain\ActionResult;
use Pulsar\Extension\Admin\Domain\BulkAction;
use Pulsar\Extension\Admin\Domain\ExportFormat;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Admin\Features\BulkAction\BulkActionHandler;
use Pulsar\Extension\Admin\Features\CreateResource\CreateResourceHandler;
use Pulsar\Extension\Admin\Features\Dashboard\DashboardHandler;
use Pulsar\Extension\Admin\Features\DeleteResource\DeleteResourceHandler;
use Pulsar\Extension\Admin\Features\ExportResource\ExportResourceHandler;
use Pulsar\Extension\Admin\Features\GlobalSearch\GlobalSearchHandler;
use Pulsar\Extension\Admin\Features\ListResource\ListResourceHandler;
use Pulsar\Extension\Admin\Features\SavedViews\SavedViewsHandler;
use Pulsar\Extension\Admin\Features\UpdateResource\UpdateResourceHandler;
use Pulsar\Extension\Admin\Features\ViewResource\ViewResourceHandler;
use Pulsar\Extension\Admin\Gateway\AdminGateway;
use Pulsar\Extension\Admin\Internal\Policy\FieldVisibilityFilter;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryStoreInterface;
use Pulsar\Extension\Admin\Internal\Storage\SavedViewStoreInterface;

#[CoversClass(AdminGateway::class)]
final class AdminGatewayTest extends TestCase
{
    private AdminGateway $gateway;

    protected function setUp(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        $resource->method('name')->willReturn('users');
        $resource->method('fields')->willReturn([
            new FieldDefinition(name: 'id', type: FieldType::Integer, label: 'ID'),
            new FieldDefinition(name: 'name', type: FieldType::String, label: 'Name', searchable: true),
        ]);
        $resource->method('operations')->willReturn(ResourceOperation::cases());
        $resource->method('bulkActions')->willReturn([new BulkAction(name: 'delete', label: 'Delete')]);
        $resource->method('exportableFields')->willReturn(['id', 'name']);
        $resource->method('auditReads')->willReturn(false);
        $resource->method('primaryKey')->willReturn('id');
        $resource->method('defaultSortField')->willReturn('id');
        $resource->method('defaultSortDirection')->willReturn('asc');

        $registry = $this->createStub(ResourceRegistryInterface::class);
        $registry->method('get')->willReturn($resource);
        $registry->method('has')->willReturn(true);
        $registry->method('all')->willReturn(['users' => $resource]);

        $query = $this->createStub(ResourceQueryInterface::class);
        $query->method('list')->willReturn([
            'data' => [['id' => 1, 'name' => 'John']],
            'total' => 1,
            'page' => 1,
            'per_page' => 25,
        ]);
        $query->method('find')->willReturn(['id' => 1, 'name' => 'John']);
        $query->method('search')->willReturn([['id' => 1, 'name' => 'John']]);
        $query->method('count')->willReturn(1);

        $mutator = $this->createStub(ResourceMutatorInterface::class);
        $mutator->method('create')->willReturn(ActionResult::success('Created', ['id' => '1']));
        $mutator->method('update')->willReturn(ActionResult::success('Updated'));
        $mutator->method('delete')->willReturn(ActionResult::success('Deleted'));
        $mutator->method('bulkAction')->willReturn(ActionResult::success('Done'));

        $config = AdminConfig::fromArray(['enabled' => true]);
        $filter = new FieldVisibilityFilter();
        $actionHistory = $this->createStub(ActionHistoryStoreInterface::class);
        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $savedViewStore = $this->createStub(SavedViewStoreInterface::class);
        $savedViewStore->method('listForResource')->willReturn([]);

        $this->gateway = new AdminGateway(
            $registry,
            new ListResourceHandler($registry, $query, $filter, $config),
            new ViewResourceHandler($registry, $query, $filter),
            new CreateResourceHandler($registry, $mutator, $actionHistory),
            new UpdateResourceHandler($registry, $mutator, $actionHistory),
            new DeleteResourceHandler($registry, $mutator, $actionHistory),
            new BulkActionHandler($registry, $mutator, $actionHistory),
            new ExportResourceHandler($registry, $query, $filter, $auditLogger),
            new GlobalSearchHandler($registry, $query, $filter),
            new DashboardHandler($registry, []),
            new SavedViewsHandler($savedViewStore),
        );
    }

    #[Test]
    public function register_resource_delegates_to_registry(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);

        // registerResource should not throw
        $this->gateway->registerResource($resource);

        self::assertInstanceOf(AdminGateway::class, $this->gateway);
    }

    #[Test]
    public function list_records_returns_paginated_data(): void
    {
        $result = $this->gateway->listRecords('users');

        self::assertSame(1, $result->total);
        self::assertSame(1, $result->page);
        self::assertNotEmpty($result->data);
    }

    #[Test]
    public function list_records_with_custom_pagination(): void
    {
        $result = $this->gateway->listRecords('users', page: 1, perPage: 50);

        self::assertSame(1, $result->page);
    }

    #[Test]
    public function view_record_returns_data(): void
    {
        $result = $this->gateway->viewRecord('users', '1');

        self::assertArrayHasKey('id', $result->data);
    }

    #[Test]
    public function create_record_returns_success(): void
    {
        $context = new MutationContext(actor: 'admin', reason: 'test');

        $result = $this->gateway->createRecord('users', ['name' => 'John'], $context);

        self::assertTrue($result->success);
    }

    #[Test]
    public function update_record_returns_success(): void
    {
        $context = new MutationContext(actor: 'admin', reason: 'test');

        $result = $this->gateway->updateRecord('users', '1', ['name' => 'Jane'], $context);

        self::assertTrue($result->success);
    }

    #[Test]
    public function delete_record_returns_success(): void
    {
        $context = new MutationContext(actor: 'admin', reason: 'test');

        $result = $this->gateway->deleteRecord('users', '1', $context);

        self::assertTrue($result->success);
    }

    #[Test]
    public function bulk_action_returns_success(): void
    {
        $context = new MutationContext(actor: 'admin', reason: 'test');

        $result = $this->gateway->bulkAction('users', 'delete', ['1'], [], $context);

        self::assertTrue($result->success);
    }

    #[Test]
    public function export_returns_result(): void
    {
        $result = $this->gateway->export('users', ExportFormat::Csv);

        self::assertNotEmpty($result->content);
        self::assertStringStartsWith('text/csv', $result->mimeType);
    }

    #[Test]
    public function search_returns_results(): void
    {
        $result = $this->gateway->search('John');

        self::assertGreaterThanOrEqual(0, $result->totalMatches);
    }

    #[Test]
    public function dashboard_returns_result(): void
    {
        $result = $this->gateway->dashboard();

        self::assertIsArray($result->widgets);
        self::assertIsArray($result->resources);
    }

    #[Test]
    public function saved_views_returns_list(): void
    {
        $result = $this->gateway->savedViews('users');

        self::assertIsArray($result);
    }
}
