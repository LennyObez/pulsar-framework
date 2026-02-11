<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Gateway;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Audit\MutationContext;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Contracts\ResourceMutatorInterface;
use Pulsar\Extension\Admin\Contracts\ResourceQueryInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Domain\ActionResult;
use Pulsar\Extension\Admin\Domain\ExportFormat;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Domain\ResourceOperation;
use Pulsar\Extension\Admin\Domain\SavedView;
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
use Pulsar\Security\Audit\AuditEntry;

#[CoversClass(AdminGateway::class)]
final class AdminGatewayTest extends TestCase
{
    private ResourceRegistryInterface&Stub $registry;
    private ResourceQueryInterface&Stub $query;
    private ResourceMutatorInterface&Stub $mutator;
    private ActionHistoryStoreInterface&Stub $actionHistory;
    private AuditLoggerInterface&Stub $auditLogger;
    private SavedViewStoreInterface&Stub $savedViewStore;
    private DataResourceInterface&Stub $resource;
    private AdminGateway $gateway;

    protected function setUp(): void
    {
        $this->registry = $this->createStub(ResourceRegistryInterface::class);
        $this->query = $this->createStub(ResourceQueryInterface::class);
        $this->mutator = $this->createStub(ResourceMutatorInterface::class);
        $this->actionHistory = $this->createStub(ActionHistoryStoreInterface::class);
        $this->auditLogger = $this->createStub(AuditLoggerInterface::class);
        $this->savedViewStore = $this->createStub(SavedViewStoreInterface::class);

        $this->resource = $this->createStub(DataResourceInterface::class);
        $this->resource->method('name')->willReturn('users');
        $this->resource->method('primaryKey')->willReturn('id');
        $this->resource->method('fields')->willReturn([
            new FieldDefinition(name: 'id', type: FieldType::Integer, label: 'ID'),
            new FieldDefinition(name: 'name', type: FieldType::String, label: 'Name'),
        ]);
        $this->resource->method('operations')->willReturn([
            ResourceOperation::List,
            ResourceOperation::View,
            ResourceOperation::Create,
            ResourceOperation::Update,
            ResourceOperation::Delete,
            ResourceOperation::BulkAction,
            ResourceOperation::Export,
        ]);
        $this->resource->method('exportableFields')->willReturn(['id', 'name']);
        $this->resource->method('pluralLabel')->willReturn('Users');
        $this->resource->method('icon')->willReturn('user');

        $this->registry->method('get')->willReturn($this->resource);

        $this->auditLogger->method('log')->willReturn(
            $this->createStub(AuditEntry::class),
        );

        $config = AdminConfig::fromArray(['enabled' => true]);
        $visibilityFilter = new FieldVisibilityFilter();

        $listHandler = new ListResourceHandler($this->registry, $this->query, $visibilityFilter, $config);
        $viewHandler = new ViewResourceHandler($this->registry, $this->query, $visibilityFilter);
        $createHandler = new CreateResourceHandler($this->registry, $this->mutator, $this->actionHistory);
        $updateHandler = new UpdateResourceHandler($this->registry, $this->mutator, $this->actionHistory);
        $deleteHandler = new DeleteResourceHandler($this->registry, $this->mutator, $this->actionHistory);
        $bulkActionHandler = new BulkActionHandler($this->registry, $this->mutator, $this->actionHistory);
        $exportHandler = new ExportResourceHandler($this->registry, $this->query, $visibilityFilter, $this->auditLogger);
        $searchHandler = new GlobalSearchHandler($this->registry, $this->query, $visibilityFilter);
        $dashboardHandler = new DashboardHandler($this->registry, []);
        $savedViewsHandler = new SavedViewsHandler($this->savedViewStore);

        $this->gateway = new AdminGateway(
            $this->registry,
            $listHandler,
            $viewHandler,
            $createHandler,
            $updateHandler,
            $deleteHandler,
            $bulkActionHandler,
            $exportHandler,
            $searchHandler,
            $dashboardHandler,
            $savedViewsHandler,
        );
    }

    #[Test]
    public function registerResourceDelegatesToRegistry(): void
    {
        $resource = $this->createStub(DataResourceInterface::class);
        /** @var ResourceRegistryInterface&\PHPUnit\Framework\MockObject\MockObject $registry */
        $registry = $this->createMock(ResourceRegistryInterface::class);
        $registry->expects($this->once())->method('register')->with($resource);

        $config = AdminConfig::fromArray(['enabled' => true]);
        $visibilityFilter = new FieldVisibilityFilter();

        $gateway = new AdminGateway(
            $registry,
            new ListResourceHandler($registry, $this->query, $visibilityFilter, $config),
            new ViewResourceHandler($registry, $this->query, $visibilityFilter),
            new CreateResourceHandler($registry, $this->mutator, $this->actionHistory),
            new UpdateResourceHandler($registry, $this->mutator, $this->actionHistory),
            new DeleteResourceHandler($registry, $this->mutator, $this->actionHistory),
            new BulkActionHandler($registry, $this->mutator, $this->actionHistory),
            new ExportResourceHandler($registry, $this->query, $visibilityFilter, $this->auditLogger),
            new GlobalSearchHandler($registry, $this->query, $visibilityFilter),
            new DashboardHandler($registry, []),
            new SavedViewsHandler($this->savedViewStore),
        );

        $gateway->registerResource($resource);
    }

    #[Test]
    public function listRecordsReturnsListResourceResult(): void
    {
        $this->query->method('list')->willReturn([
            'data' => [['id' => 1, 'name' => 'Alice']],
            'total' => 1,
            'page' => 1,
            'per_page' => 25,
        ]);

        $result = $this->gateway->listRecords('users');

        self::assertSame(1, $result->total);
        self::assertSame(1, $result->page);
        self::assertSame(25, $result->perPage);
        self::assertCount(1, $result->data);
    }

    #[Test]
    public function listRecordsPassesFiltersAndSort(): void
    {
        $this->query->method('list')->willReturn([
            'data' => [],
            'total' => 0,
            'page' => 2,
            'per_page' => 10,
        ]);

        $result = $this->gateway->listRecords(
            resourceName: 'users',
            filters: ['status' => 'active'],
            sort: ['name' => 'asc'],
            page: 2,
            perPage: 10,
        );

        self::assertSame(0, $result->total);
        self::assertSame(2, $result->page);
        self::assertSame(10, $result->perPage);
    }

    #[Test]
    public function viewRecordReturnsSingleRecord(): void
    {
        $this->query->method('find')->willReturn(['id' => '42', 'name' => 'Bob']);

        $result = $this->gateway->viewRecord('users', '42');

        self::assertSame('42', $result->data['id']);
        self::assertSame('Bob', $result->data['name']);
    }

    #[Test]
    public function createRecordReturnsActionResult(): void
    {
        $this->mutator->method('create')->willReturn(
            ActionResult::success('Created', ['id' => '1']),
        );

        $context = new MutationContext(actor: 'admin', reason: 'test');
        $result = $this->gateway->createRecord('users', ['name' => 'Charlie'], $context);

        self::assertTrue($result->success);
        self::assertSame('Created', $result->message);
    }

    #[Test]
    public function updateRecordReturnsActionResult(): void
    {
        $this->mutator->method('update')->willReturn(
            ActionResult::success('Updated'),
        );

        $context = new MutationContext(actor: 'admin', reason: 'test');
        $result = $this->gateway->updateRecord('users', '1', ['name' => 'Updated'], $context);

        self::assertTrue($result->success);
        self::assertSame('Updated', $result->message);
    }

    #[Test]
    public function deleteRecordReturnsActionResult(): void
    {
        $this->mutator->method('delete')->willReturn(
            ActionResult::success('Deleted'),
        );

        $context = new MutationContext(actor: 'admin', reason: 'test');
        $result = $this->gateway->deleteRecord('users', '1', $context);

        self::assertTrue($result->success);
        self::assertSame('Deleted', $result->message);
    }

    #[Test]
    public function bulkActionReturnsActionResult(): void
    {
        $this->mutator->method('bulkAction')->willReturn(
            ActionResult::success('Bulk completed'),
        );

        $context = new MutationContext(actor: 'admin', reason: 'test');
        $result = $this->gateway->bulkAction(
            'users',
            'activate',
            ['1', '2', '3'],
            ['notify' => true],
            $context,
        );

        self::assertTrue($result->success);
        self::assertSame('Bulk completed', $result->message);
    }

    #[Test]
    public function exportReturnsCsvResult(): void
    {
        $this->query->method('list')->willReturn([
            'data' => [['id' => 1, 'name' => 'Alice']],
            'total' => 1,
            'page' => 1,
            'per_page' => 10000,
        ]);

        $result = $this->gateway->export('users', ExportFormat::Csv);

        self::assertStringContainsString('text/csv', $result->mimeType);
        self::assertSame(1, $result->rowCount);
        self::assertNotEmpty($result->evidenceHash);
        self::assertStringEndsWith('.csv', $result->filename);
    }

    #[Test]
    public function exportReturnsJsonResult(): void
    {
        $this->query->method('list')->willReturn([
            'data' => [['id' => 1, 'name' => 'Alice']],
            'total' => 1,
            'page' => 1,
            'per_page' => 10000,
        ]);

        $result = $this->gateway->export('users', ExportFormat::Json, ['status' => 'active']);

        self::assertStringContainsString('application/json', $result->mimeType);
        self::assertStringEndsWith('.json', $result->filename);
    }

    #[Test]
    public function searchReturnsGlobalSearchResult(): void
    {
        $this->registry->method('all')->willReturn(['users' => $this->resource]);
        $this->query->method('search')->willReturn([
            ['id' => 1, 'name' => 'Alice'],
        ]);

        $result = $this->gateway->search('Alice');

        self::assertSame(1, $result->totalMatches);
        self::assertArrayHasKey('users', $result->results);
    }

    #[Test]
    public function searchWithEmptyQueryReturnsEmpty(): void
    {
        $result = $this->gateway->search('');

        self::assertSame(0, $result->totalMatches);
        self::assertSame([], $result->results);
    }

    #[Test]
    public function dashboardReturnsDashboardResult(): void
    {
        $this->registry->method('all')->willReturn([]);

        $result = $this->gateway->dashboard();

        self::assertSame([], $result->widgets);
        self::assertSame([], $result->resources);
    }

    #[Test]
    public function savedViewsReturnsViewsList(): void
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

        $this->savedViewStore->method('listForResource')->willReturn([$view]);

        $views = $this->gateway->savedViews('users');

        self::assertCount(1, $views);
        self::assertSame('Active Users', $views[0]->label);
    }

    #[Test]
    public function saveSavedViewDelegatesToHandler(): void
    {
        $view = new SavedView(
            id: 'v1',
            resourceName: 'users',
            label: 'My View',
            filters: [],
            sort: [],
            perPage: 25,
            createdBy: 'admin',
        );

        /** @var SavedViewStoreInterface&\PHPUnit\Framework\MockObject\MockObject $store */
        $store = $this->createMock(SavedViewStoreInterface::class);
        $store->expects($this->once())->method('save')->with($view);

        $config = AdminConfig::fromArray(['enabled' => true]);
        $visibilityFilter = new FieldVisibilityFilter();

        $gateway = new AdminGateway(
            $this->registry,
            new ListResourceHandler($this->registry, $this->query, $visibilityFilter, $config),
            new ViewResourceHandler($this->registry, $this->query, $visibilityFilter),
            new CreateResourceHandler($this->registry, $this->mutator, $this->actionHistory),
            new UpdateResourceHandler($this->registry, $this->mutator, $this->actionHistory),
            new DeleteResourceHandler($this->registry, $this->mutator, $this->actionHistory),
            new BulkActionHandler($this->registry, $this->mutator, $this->actionHistory),
            new ExportResourceHandler($this->registry, $this->query, $visibilityFilter, $this->auditLogger),
            new GlobalSearchHandler($this->registry, $this->query, $visibilityFilter),
            new DashboardHandler($this->registry, []),
            new SavedViewsHandler($store),
        );

        $gateway->saveSavedView($view);
    }

    #[Test]
    public function deleteSavedViewDelegatesToHandler(): void
    {
        /** @var SavedViewStoreInterface&\PHPUnit\Framework\MockObject\MockObject $store */
        $store = $this->createMock(SavedViewStoreInterface::class);
        $store->expects($this->once())->method('delete')->with('v1');

        $config = AdminConfig::fromArray(['enabled' => true]);
        $visibilityFilter = new FieldVisibilityFilter();

        $gateway = new AdminGateway(
            $this->registry,
            new ListResourceHandler($this->registry, $this->query, $visibilityFilter, $config),
            new ViewResourceHandler($this->registry, $this->query, $visibilityFilter),
            new CreateResourceHandler($this->registry, $this->mutator, $this->actionHistory),
            new UpdateResourceHandler($this->registry, $this->mutator, $this->actionHistory),
            new DeleteResourceHandler($this->registry, $this->mutator, $this->actionHistory),
            new BulkActionHandler($this->registry, $this->mutator, $this->actionHistory),
            new ExportResourceHandler($this->registry, $this->query, $visibilityFilter, $this->auditLogger),
            new GlobalSearchHandler($this->registry, $this->query, $visibilityFilter),
            new DashboardHandler($this->registry, []),
            new SavedViewsHandler($store),
        );

        $gateway->deleteSavedView('v1');
    }
}
