<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Authorization\PolicyInterface;
use Pulsar\Container\BindingType;
use Pulsar\Container\Container;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Introspection\DatabaseIntrospector;
use Pulsar\Database\Schema\DdlCompiler;
use Pulsar\Database\Schema\SchemaCapabilities;
use Pulsar\Database\Schema\SchemaManager;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Admin\AdminServiceProvider;
use Pulsar\Extension\Admin\Command\AdminServeCommand;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Contracts\ResourceMutatorInterface;
use Pulsar\Extension\Admin\Contracts\ResourceQueryInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Features\BulkAction\BulkActionHandler;
use Pulsar\Extension\Admin\Features\CreateResource\CreateResourceHandler;
use Pulsar\Extension\Admin\Features\Dashboard\DashboardHandler;
use Pulsar\Extension\Admin\Features\DeleteResource\DeleteResourceHandler;
use Pulsar\Extension\Admin\Features\ExportResource\ExportResourceHandler;
use Pulsar\Extension\Admin\Features\GlobalSearch\GlobalSearchHandler;
use Pulsar\Extension\Admin\Features\ListResource\ListResourceHandler;
use Pulsar\Extension\Admin\Features\SavedViews\SavedViewsHandler;
use Pulsar\Extension\Admin\Features\Schema\AlterTableHandler;
use Pulsar\Extension\Admin\Features\Schema\CreateTableHandler;
use Pulsar\Extension\Admin\Features\Schema\DropTableHandler;
use Pulsar\Extension\Admin\Features\Schema\PreviewDdlHandler;
use Pulsar\Extension\Admin\Features\Schema\RenameTableHandler;
use Pulsar\Extension\Admin\Features\UpdateResource\UpdateResourceHandler;
use Pulsar\Extension\Admin\Features\ViewResource\ViewResourceHandler;
use Pulsar\Extension\Admin\Gateway\AdminGateway;
use Pulsar\Extension\Admin\Internal\AdminResourceRegistry;
use Pulsar\Extension\Admin\Internal\Middleware\AdminAccessMiddleware;
use Pulsar\Extension\Admin\Internal\Middleware\AdminAuditMiddleware;
use Pulsar\Extension\Admin\Internal\Middleware\AdminAuthMiddleware;
use Pulsar\Extension\Admin\Internal\Middleware\AdminCspMiddleware;
use Pulsar\Extension\Admin\Internal\Middleware\AdminCsrfMiddleware;
use Pulsar\Extension\Admin\Internal\Middleware\AdminRateLimitMiddleware;
use Pulsar\Extension\Admin\Internal\Middleware\AdminSchemaMiddleware;
use Pulsar\Extension\Admin\Internal\Policy\AdminResourcePolicy;
use Pulsar\Extension\Admin\Internal\Policy\FieldVisibilityFilter;
use Pulsar\Extension\Admin\Internal\Security\AdminAccessGate;
use Pulsar\Extension\Admin\Internal\Security\AdminSafetyMode;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryStoreInterface;
use Pulsar\Extension\Admin\Internal\Storage\SavedViewStoreInterface;
use Pulsar\Extension\Admin\Internal\Storage\SchemaChangeLogStoreInterface;
use Pulsar\Extension\Admin\Internal\Storage\SqliteActionHistoryStore;
use Pulsar\Extension\Admin\Internal\Storage\SqliteSavedViewStore;
use Pulsar\Extension\Admin\Internal\Storage\SqliteSchemaChangeLogStore;
use Pulsar\Extension\Admin\Internal\Widget\RecentActivityWidget;
use Pulsar\Extension\Admin\Internal\Widget\ResourceCountWidget;
use Pulsar\Extension\Admin\Server\Controller\ActionHistoryController;
use Pulsar\Extension\Admin\Server\Controller\BulkActionController;
use Pulsar\Extension\Admin\Server\Controller\DashboardController;
use Pulsar\Extension\Admin\Server\Controller\ExportController;
use Pulsar\Extension\Admin\Server\Controller\ResourceCreateController;
use Pulsar\Extension\Admin\Server\Controller\ResourceDeleteController;
use Pulsar\Extension\Admin\Server\Controller\ResourceIndexController;
use Pulsar\Extension\Admin\Server\Controller\ResourceListController;
use Pulsar\Extension\Admin\Server\Controller\ResourceUpdateController;
use Pulsar\Extension\Admin\Server\Controller\ResourceViewController;
use Pulsar\Extension\Admin\Server\Controller\SavedViewsController;
use Pulsar\Extension\Admin\Server\Controller\SchemaApiController;
use Pulsar\Extension\Admin\Server\Controller\SchemaController;
use Pulsar\Extension\Admin\Server\Controller\SearchController;
use Pulsar\Http\RateLimit\RateLimiterInterface;

#[CoversClass(AdminServiceProvider::class)]
final class AdminServiceProviderTest extends TestCase
{
    private Container $container;
    private AdminServiceProvider $provider;

    #[Override]
    protected function setUp(): void
    {
        $this->container = new Container();
        $this->provider = new AdminServiceProvider();

        // Pre-register external infrastructure mocks that the factories depend on
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $this->container->instance(ConnectionInterface::class, $connection);

        $this->container->instance(AuditLoggerInterface::class, $this->createStub(AuditLoggerInterface::class));
        $this->container->instance(RateLimiterInterface::class, $this->createStub(RateLimiterInterface::class));
        $this->container->instance(PolicyInterface::class, $this->createStub(PolicyInterface::class));
    }

    // ---------------------------------------------------------------
    // provides()
    // ---------------------------------------------------------------

    #[Test]
    public function providesReturnsExpectedClasses(): void
    {
        $provides = $this->provider->provides();

        self::assertContains(AdminConfig::class, $provides);
        self::assertContains(ResourceRegistryInterface::class, $provides);
        self::assertContains(ResourceQueryInterface::class, $provides);
        self::assertContains(ResourceMutatorInterface::class, $provides);
        self::assertContains(AdminGateway::class, $provides);
        self::assertContains(AdminAccessGate::class, $provides);
        self::assertContains(AdminSafetyMode::class, $provides);
    }

    #[Test]
    public function providesReturnsSevenEntries(): void
    {
        self::assertCount(7, $this->provider->provides());
    }

    #[Test]
    public function providesReturnsStringArray(): void
    {
        foreach ($this->provider->provides() as $className) {
            self::assertIsString($className);
        }
    }

    // ---------------------------------------------------------------
    // register() — default config
    // ---------------------------------------------------------------

    #[Test]
    public function registerSetsDefaultConfigWhenNotAlreadyBound(): void
    {
        $this->provider->register($this->container);

        $config = $this->container->get(AdminConfig::class);
        self::assertInstanceOf(AdminConfig::class, $config);
        // Default config has enabled=false and routePrefix='/admin'
        self::assertFalse($config->enabled);
        self::assertSame('/admin', $config->routePrefix);
    }

    #[Test]
    public function registerPreservesExistingConfigWhenAlreadyBound(): void
    {
        $customConfig = AdminConfig::fromArray([
            'enabled' => true,
            'route_prefix' => '/custom-admin',
        ]);
        $this->container->instance(AdminConfig::class, $customConfig);

        $this->provider->register($this->container);

        $resolved = $this->container->get(AdminConfig::class);
        self::assertSame($customConfig, $resolved);
        self::assertTrue($resolved->enabled);
        self::assertSame('/custom-admin', $resolved->routePrefix);
    }

    // ---------------------------------------------------------------
    // register() — registry bindings
    // ---------------------------------------------------------------

    #[Test]
    public function registerBindsResourceRegistryInterface(): void
    {
        $this->provider->register($this->container);

        $registry = $this->container->get(ResourceRegistryInterface::class);
        self::assertInstanceOf(AdminResourceRegistry::class, $registry);
    }

    #[Test]
    public function registerBindsAdminResourceRegistryToSameInstance(): void
    {
        $this->provider->register($this->container);

        $interface = $this->container->get(ResourceRegistryInterface::class);
        $concrete = $this->container->get(AdminResourceRegistry::class);
        self::assertSame($interface, $concrete);
    }

    // ---------------------------------------------------------------
    // register() — policy and access gate
    // ---------------------------------------------------------------

    #[Test]
    public function registerBindsFieldVisibilityFilter(): void
    {
        $this->provider->register($this->container);

        self::assertTrue($this->container->has(FieldVisibilityFilter::class));
    }

    #[Test]
    public function registerBindsAdminResourcePolicy(): void
    {
        $this->provider->register($this->container);

        $policy = $this->container->get(AdminResourcePolicy::class);
        self::assertInstanceOf(AdminResourcePolicy::class, $policy);
    }

    #[Test]
    public function registerBindsAdminAccessGate(): void
    {
        $this->provider->register($this->container);

        $gate = $this->container->get(AdminAccessGate::class);
        self::assertInstanceOf(AdminAccessGate::class, $gate);
    }

    // ---------------------------------------------------------------
    // register() — safety mode
    // ---------------------------------------------------------------

    #[Test]
    public function registerBindsAdminSafetyModeWithDebugFalseByDefault(): void
    {
        $this->provider->register($this->container);

        $safety = $this->container->get(AdminSafetyMode::class);
        self::assertInstanceOf(AdminSafetyMode::class, $safety);
        self::assertFalse($safety->isDebug());
    }

    #[Test]
    public function registerBindsAdminSafetyModeWithDebugTrueWhenAppDebugIsTrue(): void
    {
        // Use a ScalarCapableContainer that can store scalar values for app.debug
        $container = new ScalarCapableContainer();
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $container->instance(ConnectionInterface::class, $connection);
        $container->instance(AuditLoggerInterface::class, $this->createStub(AuditLoggerInterface::class));
        $container->instance(RateLimiterInterface::class, $this->createStub(RateLimiterInterface::class));
        $container->instance(PolicyInterface::class, $this->createStub(PolicyInterface::class));

        // Store a scalar true via the test-only scalar storage
        $container->setScalar('app.debug', true);

        $this->provider->register($container);

        $safety = $container->get(AdminSafetyMode::class);
        self::assertInstanceOf(AdminSafetyMode::class, $safety);
        self::assertTrue($safety->isDebug());
    }

    // ---------------------------------------------------------------
    // register() — database introspector
    // ---------------------------------------------------------------

    #[Test]
    public function registerBindsDatabaseIntrospector(): void
    {
        $this->provider->register($this->container);

        $introspector = $this->container->get(DatabaseIntrospector::class);
        self::assertInstanceOf(DatabaseIntrospector::class, $introspector);
    }

    // ---------------------------------------------------------------
    // register() — query and mutator
    // ---------------------------------------------------------------

    #[Test]
    public function registerBindsResourceQueryInterface(): void
    {
        $this->provider->register($this->container);

        $query = $this->container->get(ResourceQueryInterface::class);
        self::assertInstanceOf(ResourceQueryInterface::class, $query);
    }

    #[Test]
    public function registerBindsResourceMutatorInterface(): void
    {
        $this->provider->register($this->container);

        $mutator = $this->container->get(ResourceMutatorInterface::class);
        self::assertInstanceOf(ResourceMutatorInterface::class, $mutator);
    }

    // ---------------------------------------------------------------
    // register() — storage: sqlite driver (default)
    // ---------------------------------------------------------------

    #[Test]
    public function registerBindsSavedViewStoreInterfaceWithSqliteDriver(): void
    {
        $this->provider->register($this->container);

        $store = $this->container->get(SavedViewStoreInterface::class);
        self::assertInstanceOf(SqliteSavedViewStore::class, $store);
    }

    #[Test]
    public function registerBindsActionHistoryStoreInterfaceWithSqliteDriver(): void
    {
        $this->provider->register($this->container);

        $store = $this->container->get(ActionHistoryStoreInterface::class);
        self::assertInstanceOf(SqliteActionHistoryStore::class, $store);
    }

    // ---------------------------------------------------------------
    // register() — storage: database driver
    // ---------------------------------------------------------------

    #[Test]
    public function registerBindsSavedViewStoreWithDatabaseDriverWhenConfigured(): void
    {
        $config = AdminConfig::fromArray([
            'storage' => ['driver' => 'database'],
        ]);
        $this->container->instance(AdminConfig::class, $config);

        $this->provider->register($this->container);

        $store = $this->container->get(SavedViewStoreInterface::class);
        // DbSavedViewStore is what the factory creates for 'database' driver
        self::assertInstanceOf(SavedViewStoreInterface::class, $store);
    }

    #[Test]
    public function registerBindsActionHistoryStoreWithDatabaseDriverWhenConfigured(): void
    {
        $config = AdminConfig::fromArray([
            'storage' => ['driver' => 'database'],
        ]);
        $this->container->instance(AdminConfig::class, $config);

        $this->provider->register($this->container);

        $store = $this->container->get(ActionHistoryStoreInterface::class);
        self::assertInstanceOf(ActionHistoryStoreInterface::class, $store);
    }

    // ---------------------------------------------------------------
    // register() — storage: sqlite path configuration
    // ---------------------------------------------------------------

    #[Test]
    public function registerUsesSqlitePathFromConfigWhenProvided(): void
    {
        $tmpPath = sys_get_temp_dir() . '/pulsar_admin_test_' . bin2hex(random_bytes(4)) . '.sqlite';

        try {
            $config = AdminConfig::fromArray([
                'storage' => ['driver' => 'sqlite', 'sqlite_path' => $tmpPath],
            ]);
            $this->container->instance(AdminConfig::class, $config);

            $this->provider->register($this->container);

            $store = $this->container->get(SavedViewStoreInterface::class);
            self::assertInstanceOf(SqliteSavedViewStore::class, $store);
        } finally {
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    // ---------------------------------------------------------------
    // register() — widgets
    // ---------------------------------------------------------------

    #[Test]
    public function registerBindsResourceCountWidget(): void
    {
        $this->provider->register($this->container);

        $widget = $this->container->get(ResourceCountWidget::class);
        self::assertInstanceOf(ResourceCountWidget::class, $widget);
    }

    #[Test]
    public function registerBindsRecentActivityWidget(): void
    {
        $this->provider->register($this->container);

        $widget = $this->container->get(RecentActivityWidget::class);
        self::assertInstanceOf(RecentActivityWidget::class, $widget);
    }

    // ---------------------------------------------------------------
    // register() — feature handlers
    // ---------------------------------------------------------------

    #[Test]
    public function registerBindsListResourceHandler(): void
    {
        $this->provider->register($this->container);

        $handler = $this->container->get(ListResourceHandler::class);
        self::assertInstanceOf(ListResourceHandler::class, $handler);
    }

    #[Test]
    public function registerBindsViewResourceHandler(): void
    {
        $this->provider->register($this->container);

        $handler = $this->container->get(ViewResourceHandler::class);
        self::assertInstanceOf(ViewResourceHandler::class, $handler);
    }

    #[Test]
    public function registerBindsCreateResourceHandler(): void
    {
        $this->provider->register($this->container);

        $handler = $this->container->get(CreateResourceHandler::class);
        self::assertInstanceOf(CreateResourceHandler::class, $handler);
    }

    #[Test]
    public function registerBindsUpdateResourceHandler(): void
    {
        $this->provider->register($this->container);

        $handler = $this->container->get(UpdateResourceHandler::class);
        self::assertInstanceOf(UpdateResourceHandler::class, $handler);
    }

    #[Test]
    public function registerBindsDeleteResourceHandler(): void
    {
        $this->provider->register($this->container);

        $handler = $this->container->get(DeleteResourceHandler::class);
        self::assertInstanceOf(DeleteResourceHandler::class, $handler);
    }

    #[Test]
    public function registerBindsBulkActionHandler(): void
    {
        $this->provider->register($this->container);

        $handler = $this->container->get(BulkActionHandler::class);
        self::assertInstanceOf(BulkActionHandler::class, $handler);
    }

    #[Test]
    public function registerBindsExportResourceHandler(): void
    {
        $this->provider->register($this->container);

        $handler = $this->container->get(ExportResourceHandler::class);
        self::assertInstanceOf(ExportResourceHandler::class, $handler);
    }

    #[Test]
    public function registerBindsGlobalSearchHandler(): void
    {
        $this->provider->register($this->container);

        $handler = $this->container->get(GlobalSearchHandler::class);
        self::assertInstanceOf(GlobalSearchHandler::class, $handler);
    }

    #[Test]
    public function registerBindsDashboardHandler(): void
    {
        $this->provider->register($this->container);

        $handler = $this->container->get(DashboardHandler::class);
        self::assertInstanceOf(DashboardHandler::class, $handler);
    }

    #[Test]
    public function registerBindsDashboardHandlerWithoutResourceCountWidgetWhenNoConnection(): void
    {
        // Create a container without ConnectionInterface
        $container = new Container();
        $container->instance(AuditLoggerInterface::class, $this->createStub(AuditLoggerInterface::class));
        $container->instance(RateLimiterInterface::class, $this->createStub(RateLimiterInterface::class));
        $container->instance(PolicyInterface::class, $this->createStub(PolicyInterface::class));

        $this->provider->register($container);

        // DashboardHandler should still resolve — just without the ResourceCountWidget
        $handler = $container->get(DashboardHandler::class);
        self::assertInstanceOf(DashboardHandler::class, $handler);
    }

    #[Test]
    public function registerBindsSavedViewsHandler(): void
    {
        $this->provider->register($this->container);

        $handler = $this->container->get(SavedViewsHandler::class);
        self::assertInstanceOf(SavedViewsHandler::class, $handler);
    }

    // ---------------------------------------------------------------
    // register() — gateway
    // ---------------------------------------------------------------

    #[Test]
    public function registerBindsAdminGateway(): void
    {
        $this->provider->register($this->container);

        $gateway = $this->container->get(AdminGateway::class);
        self::assertInstanceOf(AdminGateway::class, $gateway);
    }

    // ---------------------------------------------------------------
    // register() — middleware
    // ---------------------------------------------------------------

    #[Test]
    public function registerBindsAdminAccessMiddleware(): void
    {
        $this->provider->register($this->container);

        $mw = $this->container->get(AdminAccessMiddleware::class);
        self::assertInstanceOf(AdminAccessMiddleware::class, $mw);
    }

    #[Test]
    public function registerBindsAdminAuthMiddleware(): void
    {
        $this->provider->register($this->container);

        $mw = $this->container->get(AdminAuthMiddleware::class);
        self::assertInstanceOf(AdminAuthMiddleware::class, $mw);
    }

    #[Test]
    public function registerBindsAdminCsrfMiddleware(): void
    {
        $this->provider->register($this->container);

        $mw = $this->container->get(AdminCsrfMiddleware::class);
        self::assertInstanceOf(AdminCsrfMiddleware::class, $mw);
    }

    #[Test]
    public function registerBindsAdminCspMiddleware(): void
    {
        $this->provider->register($this->container);

        $mw = $this->container->get(AdminCspMiddleware::class);
        self::assertInstanceOf(AdminCspMiddleware::class, $mw);
    }

    #[Test]
    public function registerBindsAdminRateLimitMiddleware(): void
    {
        $this->provider->register($this->container);

        $mw = $this->container->get(AdminRateLimitMiddleware::class);
        self::assertInstanceOf(AdminRateLimitMiddleware::class, $mw);
    }

    #[Test]
    public function registerBindsAdminAuditMiddleware(): void
    {
        $this->provider->register($this->container);

        $mw = $this->container->get(AdminAuditMiddleware::class);
        self::assertInstanceOf(AdminAuditMiddleware::class, $mw);
    }

    // ---------------------------------------------------------------
    // register() — commands
    // ---------------------------------------------------------------

    #[Test]
    public function registerBindsAdminServeCommandWithDefaultBasePath(): void
    {
        $this->provider->register($this->container);

        $command = $this->container->get(AdminServeCommand::class);
        self::assertInstanceOf(AdminServeCommand::class, $command);
    }

    #[Test]
    public function registerBindsAdminServeCommandWithCustomBasePath(): void
    {
        $container = new ScalarCapableContainer();
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $container->instance(ConnectionInterface::class, $connection);
        $container->instance(AuditLoggerInterface::class, $this->createStub(AuditLoggerInterface::class));
        $container->instance(RateLimiterInterface::class, $this->createStub(RateLimiterInterface::class));
        $container->instance(PolicyInterface::class, $this->createStub(PolicyInterface::class));

        $container->setScalar('app.base_path', '/custom/path');

        $this->provider->register($container);

        $command = $container->get(AdminServeCommand::class);
        self::assertInstanceOf(AdminServeCommand::class, $command);
    }

    // ---------------------------------------------------------------
    // register() — schema DDL layer
    // ---------------------------------------------------------------

    #[Test]
    public function registerBindsSchemaCapabilities(): void
    {
        $this->provider->register($this->container);

        $capabilities = $this->container->get(SchemaCapabilities::class);
        self::assertInstanceOf(SchemaCapabilities::class, $capabilities);
    }

    #[Test]
    public function registerBindsDdlCompiler(): void
    {
        $this->provider->register($this->container);

        $compiler = $this->container->get(DdlCompiler::class);
        self::assertInstanceOf(DdlCompiler::class, $compiler);
    }

    #[Test]
    public function registerBindsSchemaManager(): void
    {
        $this->provider->register($this->container);

        $manager = $this->container->get(SchemaManager::class);
        self::assertInstanceOf(SchemaManager::class, $manager);
    }

    #[Test]
    public function registerBindsSchemaChangeLogStoreInterface(): void
    {
        $this->provider->register($this->container);

        $store = $this->container->get(SchemaChangeLogStoreInterface::class);
        self::assertInstanceOf(SqliteSchemaChangeLogStore::class, $store);
    }

    // ---------------------------------------------------------------
    // register() — schema handlers
    // ---------------------------------------------------------------

    #[Test]
    public function registerBindsCreateTableHandler(): void
    {
        $this->provider->register($this->container);

        $handler = $this->container->get(CreateTableHandler::class);
        self::assertInstanceOf(CreateTableHandler::class, $handler);
    }

    #[Test]
    public function registerBindsAlterTableHandler(): void
    {
        $this->provider->register($this->container);

        $handler = $this->container->get(AlterTableHandler::class);
        self::assertInstanceOf(AlterTableHandler::class, $handler);
    }

    #[Test]
    public function registerBindsDropTableHandler(): void
    {
        $this->provider->register($this->container);

        $handler = $this->container->get(DropTableHandler::class);
        self::assertInstanceOf(DropTableHandler::class, $handler);
    }

    #[Test]
    public function registerBindsRenameTableHandler(): void
    {
        $this->provider->register($this->container);

        $handler = $this->container->get(RenameTableHandler::class);
        self::assertInstanceOf(RenameTableHandler::class, $handler);
    }

    #[Test]
    public function registerBindsPreviewDdlHandler(): void
    {
        $this->provider->register($this->container);

        $handler = $this->container->get(PreviewDdlHandler::class);
        self::assertInstanceOf(PreviewDdlHandler::class, $handler);
    }

    // ---------------------------------------------------------------
    // register() — schema middleware
    // ---------------------------------------------------------------

    #[Test]
    public function registerBindsAdminSchemaMiddleware(): void
    {
        $this->provider->register($this->container);

        $mw = $this->container->get(AdminSchemaMiddleware::class);
        self::assertInstanceOf(AdminSchemaMiddleware::class, $mw);
    }

    // ---------------------------------------------------------------
    // register() — schema controllers
    // ---------------------------------------------------------------

    #[Test]
    public function registerBindsSchemaController(): void
    {
        $this->provider->register($this->container);

        $controller = $this->container->get(SchemaController::class);
        self::assertInstanceOf(SchemaController::class, $controller);
    }

    #[Test]
    public function registerBindsSchemaApiController(): void
    {
        $this->provider->register($this->container);

        $controller = $this->container->get(SchemaApiController::class);
        self::assertInstanceOf(SchemaApiController::class, $controller);
    }

    // ---------------------------------------------------------------
    // register() — controllers
    // ---------------------------------------------------------------

    #[Test]
    public function registerBindsDashboardController(): void
    {
        $this->provider->register($this->container);

        $controller = $this->container->get(DashboardController::class);
        self::assertInstanceOf(DashboardController::class, $controller);
    }

    #[Test]
    public function registerBindsResourceIndexController(): void
    {
        $this->provider->register($this->container);

        $controller = $this->container->get(ResourceIndexController::class);
        self::assertInstanceOf(ResourceIndexController::class, $controller);
    }

    #[Test]
    public function registerBindsResourceListController(): void
    {
        $this->provider->register($this->container);

        $controller = $this->container->get(ResourceListController::class);
        self::assertInstanceOf(ResourceListController::class, $controller);
    }

    #[Test]
    public function registerBindsResourceViewController(): void
    {
        $this->provider->register($this->container);

        $controller = $this->container->get(ResourceViewController::class);
        self::assertInstanceOf(ResourceViewController::class, $controller);
    }

    #[Test]
    public function registerBindsResourceCreateController(): void
    {
        $this->provider->register($this->container);

        $controller = $this->container->get(ResourceCreateController::class);
        self::assertInstanceOf(ResourceCreateController::class, $controller);
    }

    #[Test]
    public function registerBindsResourceUpdateController(): void
    {
        $this->provider->register($this->container);

        $controller = $this->container->get(ResourceUpdateController::class);
        self::assertInstanceOf(ResourceUpdateController::class, $controller);
    }

    #[Test]
    public function registerBindsResourceDeleteController(): void
    {
        $this->provider->register($this->container);

        $controller = $this->container->get(ResourceDeleteController::class);
        self::assertInstanceOf(ResourceDeleteController::class, $controller);
    }

    #[Test]
    public function registerBindsBulkActionController(): void
    {
        $this->provider->register($this->container);

        $controller = $this->container->get(BulkActionController::class);
        self::assertInstanceOf(BulkActionController::class, $controller);
    }

    #[Test]
    public function registerBindsExportController(): void
    {
        $this->provider->register($this->container);

        $controller = $this->container->get(ExportController::class);
        self::assertInstanceOf(ExportController::class, $controller);
    }

    #[Test]
    public function registerBindsSearchController(): void
    {
        $this->provider->register($this->container);

        $controller = $this->container->get(SearchController::class);
        self::assertInstanceOf(SearchController::class, $controller);
    }

    #[Test]
    public function registerBindsSavedViewsController(): void
    {
        $this->provider->register($this->container);

        $controller = $this->container->get(SavedViewsController::class);
        self::assertInstanceOf(SavedViewsController::class, $controller);
    }

    #[Test]
    public function registerBindsActionHistoryController(): void
    {
        $this->provider->register($this->container);

        $controller = $this->container->get(ActionHistoryController::class);
        self::assertInstanceOf(ActionHistoryController::class, $controller);
    }

    // ---------------------------------------------------------------
    // register() — all bindings are present
    // ---------------------------------------------------------------

    #[Test]
    public function registerCreatesAllExpectedBindings(): void
    {
        $this->provider->register($this->container);

        $expectedBindings = [
            ResourceRegistryInterface::class,
            AdminResourceRegistry::class,
            FieldVisibilityFilter::class,
            AdminResourcePolicy::class,
            AdminAccessGate::class,
            AdminSafetyMode::class,
            DatabaseIntrospector::class,
            ResourceQueryInterface::class,
            ResourceMutatorInterface::class,
            SavedViewStoreInterface::class,
            ActionHistoryStoreInterface::class,
            ResourceCountWidget::class,
            RecentActivityWidget::class,
            ListResourceHandler::class,
            ViewResourceHandler::class,
            CreateResourceHandler::class,
            UpdateResourceHandler::class,
            DeleteResourceHandler::class,
            BulkActionHandler::class,
            ExportResourceHandler::class,
            GlobalSearchHandler::class,
            DashboardHandler::class,
            SavedViewsHandler::class,
            AdminGateway::class,
            AdminAccessMiddleware::class,
            AdminAuthMiddleware::class,
            AdminCsrfMiddleware::class,
            AdminCspMiddleware::class,
            AdminRateLimitMiddleware::class,
            AdminAuditMiddleware::class,
            AdminServeCommand::class,
            SchemaCapabilities::class,
            DdlCompiler::class,
            SchemaManager::class,
            SchemaChangeLogStoreInterface::class,
            CreateTableHandler::class,
            AlterTableHandler::class,
            DropTableHandler::class,
            RenameTableHandler::class,
            PreviewDdlHandler::class,
            AdminSchemaMiddleware::class,
            SchemaController::class,
            SchemaApiController::class,
            DashboardController::class,
            ResourceIndexController::class,
            ResourceListController::class,
            ResourceViewController::class,
            ResourceCreateController::class,
            ResourceUpdateController::class,
            ResourceDeleteController::class,
            BulkActionController::class,
            ExportController::class,
            SearchController::class,
            SavedViewsController::class,
            ActionHistoryController::class,
        ];

        foreach ($expectedBindings as $binding) {
            self::assertTrue(
                $this->container->has($binding),
                "Expected binding missing: {$binding}",
            );
        }
    }

    // ---------------------------------------------------------------
    // register() — singleton behavior
    // ---------------------------------------------------------------

    #[Test]
    public function registryIsSingleton(): void
    {
        $this->provider->register($this->container);

        $first = $this->container->get(ResourceRegistryInterface::class);
        $second = $this->container->get(ResourceRegistryInterface::class);
        self::assertSame($first, $second);
    }

    #[Test]
    public function adminConfigInstanceIsSingleton(): void
    {
        $this->provider->register($this->container);

        $first = $this->container->get(AdminConfig::class);
        $second = $this->container->get(AdminConfig::class);
        self::assertSame($first, $second);
    }

    // ---------------------------------------------------------------
    // register() — safety mode with app.debug absent
    // ---------------------------------------------------------------

    #[Test]
    public function safetyModeIsNotDebugWhenAppDebugAbsent(): void
    {
        // The default container in setUp does not have app.debug
        $this->provider->register($this->container);

        $safety = $this->container->get(AdminSafetyMode::class);
        self::assertFalse($safety->isDebug());
    }

    // ---------------------------------------------------------------
    // register() — storage with custom sqlite path for action history
    // ---------------------------------------------------------------

    #[Test]
    public function registerUsesCustomSqlitePathForActionHistoryStore(): void
    {
        $tmpPath = sys_get_temp_dir() . '/pulsar_admin_ah_test_' . bin2hex(random_bytes(4)) . '.sqlite';

        try {
            $config = AdminConfig::fromArray([
                'storage' => ['driver' => 'sqlite', 'sqlite_path' => $tmpPath],
            ]);
            $this->container->instance(AdminConfig::class, $config);

            $this->provider->register($this->container);

            $store = $this->container->get(ActionHistoryStoreInterface::class);
            self::assertInstanceOf(SqliteActionHistoryStore::class, $store);
        } finally {
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    // ---------------------------------------------------------------
    // register() — schema change log store uses config path
    // ---------------------------------------------------------------

    #[Test]
    public function registerUsesCustomSqlitePathForSchemaChangeLogStore(): void
    {
        $tmpPath = sys_get_temp_dir() . '/pulsar_admin_scl_test_' . bin2hex(random_bytes(4)) . '.sqlite';

        try {
            $config = AdminConfig::fromArray([
                'storage' => ['driver' => 'sqlite', 'sqlite_path' => $tmpPath],
            ]);
            $this->container->instance(AdminConfig::class, $config);

            $this->provider->register($this->container);

            $store = $this->container->get(SchemaChangeLogStoreInterface::class);
            self::assertInstanceOf(SqliteSchemaChangeLogStore::class, $store);
        } finally {
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    // ---------------------------------------------------------------
    // register() — ServiceProviderInterface contract
    // ---------------------------------------------------------------

    #[Test]
    public function implementsServiceProviderInterface(): void
    {
        self::assertInstanceOf(
            ServiceProviderInterface::class,
            $this->provider,
        );
    }
}

/**
 * Test-only container decorator that supports scalar values for keys like 'app.debug'.
 *
 * The production Container only stores objects via instance(). This decorator adds
 * a parallel scalar store so AdminServiceProvider factories that call
 * $container->get('app.debug') or $container->get('app.base_path') can
 * receive non-object values during tests.
 *
 * @internal Test helper only
 */
final class ScalarCapableContainer implements ContainerInterface
{
    private Container $inner;

    /** @var array<string, mixed> */
    private array $scalars = [];

    public function __construct()
    {
        $this->inner = new Container();
    }

    public function setScalar(string $id, mixed $value): void
    {
        $this->scalars[$id] = $value;
    }

    #[Override]
    public function bind(string $id, callable|string $concrete, BindingType $type = BindingType::Singleton): void
    {
        $this->inner->bind($id, $concrete, $type);
    }

    #[Override]
    public function singleton(string $id, callable|string $concrete): void
    {
        $this->inner->singleton($id, $concrete);
    }

    #[Override]
    public function instance(string $id, object $instance): void
    {
        $this->inner->instance($id, $instance);
    }

    #[Override]
    public function has(string $id): bool
    {
        return isset($this->scalars[$id]) || $this->inner->has($id);
    }

    #[Override]
    public function get(string $id): mixed
    {
        if (isset($this->scalars[$id])) {
            return $this->scalars[$id];
        }

        return $this->inner->get($id);
    }

    #[Override]
    public function forgetInstance(string $id): void
    {
        $this->inner->forgetInstance($id);
    }

    #[Override]
    public function setResolutionHints(?array $hints): void
    {
        $this->inner->setResolutionHints($hints);
    }

    #[Override]
    public function getBindings(): array
    {
        return $this->inner->getBindings();
    }

    #[Override]
    public function getInstances(): array
    {
        return $this->inner->getInstances();
    }

    #[Override]
    public function call(callable $callable, array $params = []): mixed
    {
        return $this->inner->call($callable, $params);
    }
}
