<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin;

use Override;
use PDO;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Authorization\PolicyInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Introspection\DatabaseIntrospector;
use Pulsar\Database\Schema\DdlCompiler;
use Pulsar\Database\Schema\SchemaCapabilities;
use Pulsar\Database\Schema\SchemaManager;
use Pulsar\Extensibility\ServiceProviderInterface;
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
use Pulsar\Extension\Admin\Internal\Adapter\OrmResourceMutator;
use Pulsar\Extension\Admin\Internal\Adapter\OrmResourceQuery;
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
use Pulsar\Extension\Admin\Internal\Storage\DbActionHistoryStore;
use Pulsar\Extension\Admin\Internal\Storage\DbSavedViewStore;
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

use function is_string;

/**
 * Service provider for the admin extension.
 *
 * Binds all admin services, handlers, controllers, and infrastructure
 * components to the DI container.
 */
final readonly class AdminServiceProvider implements ServiceProviderInterface
{
    #[Override]
    public function register(ContainerInterface $container): void
    {
        // Config (loaded in preBoot via AdminExtension; only set defaults if missing)
        if (!$container->has(AdminConfig::class)) {
            $container->instance(AdminConfig::class, AdminConfig::fromArray([]));
        }

        // Registry (singleton)
        $registry = new AdminResourceRegistry();
        $container->bind(ResourceRegistryInterface::class, static fn(): ResourceRegistryInterface => $registry);
        $container->bind(AdminResourceRegistry::class, static fn(): AdminResourceRegistry => $registry);

        // Field visibility filter
        $container->bind(FieldVisibilityFilter::class, FieldVisibilityFilter::class);

        // Policy + Access Gate
        $container->bind(AdminResourcePolicy::class, static function () use ($container): AdminResourcePolicy {
            /** @var AdminConfig $config */
            $config = $container->get(AdminConfig::class);
            return new AdminResourcePolicy($config);
        });

        $container->bind(AdminAccessGate::class, static function () use ($container): AdminAccessGate {
            /** @var PolicyInterface $policy */
            $policy = $container->get(AdminResourcePolicy::class);
            return new AdminAccessGate($policy);
        });

        // Safety mode
        $container->bind(AdminSafetyMode::class, static function () use ($container): AdminSafetyMode {
            $debug = $container->has('app.debug') && $container->get('app.debug') === true;
            return new AdminSafetyMode($debug);
        });

        // Database introspector (for auto-discovery and manual use)
        $container->bind(DatabaseIntrospector::class, static function () use ($container): DatabaseIntrospector {
            /** @var ConnectionInterface $connection */
            $connection = $container->get(ConnectionInterface::class);
            return new DatabaseIntrospector($connection);
        });

        // Query + Mutator
        $container->bind(ResourceQueryInterface::class, static function () use ($container): ResourceQueryInterface {
            /** @var ConnectionInterface $connection */
            $connection = $container->get(ConnectionInterface::class);
            return new OrmResourceQuery($connection);
        });

        $container->bind(ResourceMutatorInterface::class, static function () use ($container): ResourceMutatorInterface {
            /** @var ConnectionInterface $connection */
            $connection = $container->get(ConnectionInterface::class);
            /** @var AuditLoggerInterface $auditLogger */
            $auditLogger = $container->get(AuditLoggerInterface::class);
            return new OrmResourceMutator($connection, $auditLogger);
        });

        // Storage
        $container->bind(SavedViewStoreInterface::class, static function () use ($container): SavedViewStoreInterface {
            /** @var AdminConfig $config */
            $config = $container->get(AdminConfig::class);
            if ($config->storage->driver === 'database') {
                /** @var ConnectionInterface $connection */
                $connection = $container->get(ConnectionInterface::class);
                return new DbSavedViewStore($connection);
            }
            $path = $config->storage->sqlitePath ?? sys_get_temp_dir() . '/pulsar_admin.sqlite';
            $pdo = new PDO("sqlite:$path");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return new SqliteSavedViewStore($pdo);
        });

        $container->bind(ActionHistoryStoreInterface::class, static function () use ($container): ActionHistoryStoreInterface {
            /** @var AdminConfig $config */
            $config = $container->get(AdminConfig::class);
            if ($config->storage->driver === 'database') {
                /** @var ConnectionInterface $connection */
                $connection = $container->get(ConnectionInterface::class);
                return new DbActionHistoryStore($connection);
            }
            $path = $config->storage->sqlitePath ?? sys_get_temp_dir() . '/pulsar_admin.sqlite';
            $pdo = new PDO("sqlite:$path");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return new SqliteActionHistoryStore($pdo);
        });

        // Widgets
        $container->bind(ResourceCountWidget::class, static function () use ($container): ResourceCountWidget {
            /** @var ResourceRegistryInterface $registry */
            $registry = $container->get(ResourceRegistryInterface::class);
            /** @var ResourceQueryInterface $query */
            $query = $container->get(ResourceQueryInterface::class);
            return new ResourceCountWidget($registry, $query);
        });

        $container->bind(RecentActivityWidget::class, static function () use ($container): RecentActivityWidget {
            /** @var ActionHistoryStoreInterface $actionHistory */
            $actionHistory = $container->get(ActionHistoryStoreInterface::class);
            return new RecentActivityWidget($actionHistory);
        });

        // Feature handlers
        $container->bind(ListResourceHandler::class, static function () use ($container): ListResourceHandler {
            return new ListResourceHandler(
                $container->get(ResourceRegistryInterface::class),
                $container->get(ResourceQueryInterface::class),
                $container->get(FieldVisibilityFilter::class),
                $container->get(AdminConfig::class),
            );
        });

        $container->bind(ViewResourceHandler::class, static function () use ($container): ViewResourceHandler {
            return new ViewResourceHandler(
                $container->get(ResourceRegistryInterface::class),
                $container->get(ResourceQueryInterface::class),
                $container->get(FieldVisibilityFilter::class),
            );
        });

        $container->bind(CreateResourceHandler::class, static function () use ($container): CreateResourceHandler {
            return new CreateResourceHandler(
                $container->get(ResourceRegistryInterface::class),
                $container->get(ResourceMutatorInterface::class),
                $container->get(ActionHistoryStoreInterface::class),
            );
        });

        $container->bind(UpdateResourceHandler::class, static function () use ($container): UpdateResourceHandler {
            return new UpdateResourceHandler(
                $container->get(ResourceRegistryInterface::class),
                $container->get(ResourceMutatorInterface::class),
                $container->get(ActionHistoryStoreInterface::class),
            );
        });

        $container->bind(DeleteResourceHandler::class, static function () use ($container): DeleteResourceHandler {
            return new DeleteResourceHandler(
                $container->get(ResourceRegistryInterface::class),
                $container->get(ResourceMutatorInterface::class),
                $container->get(ActionHistoryStoreInterface::class),
            );
        });

        $container->bind(BulkActionHandler::class, static function () use ($container): BulkActionHandler {
            return new BulkActionHandler(
                $container->get(ResourceRegistryInterface::class),
                $container->get(ResourceMutatorInterface::class),
                $container->get(ActionHistoryStoreInterface::class),
            );
        });

        $container->bind(ExportResourceHandler::class, static function () use ($container): ExportResourceHandler {
            return new ExportResourceHandler(
                $container->get(ResourceRegistryInterface::class),
                $container->get(ResourceQueryInterface::class),
                $container->get(FieldVisibilityFilter::class),
                $container->get(AuditLoggerInterface::class),
            );
        });

        $container->bind(GlobalSearchHandler::class, static function () use ($container): GlobalSearchHandler {
            return new GlobalSearchHandler(
                $container->get(ResourceRegistryInterface::class),
                $container->get(ResourceQueryInterface::class),
                $container->get(FieldVisibilityFilter::class),
            );
        });

        $container->bind(DashboardHandler::class, static function () use ($container): DashboardHandler {
            $widgets = [];

            // ResourceCountWidget requires a database: only include when available
            if ($container->has(ConnectionInterface::class)) {
                $widgets[] = $container->get(ResourceCountWidget::class);
            }

            $widgets[] = $container->get(RecentActivityWidget::class);

            return new DashboardHandler(
                $container->get(ResourceRegistryInterface::class),
                $widgets,
            );
        });

        $container->bind(SavedViewsHandler::class, static function () use ($container): SavedViewsHandler {
            return new SavedViewsHandler(
                $container->get(SavedViewStoreInterface::class),
            );
        });

        // Gateway
        $container->bind(AdminGateway::class, static function () use ($container): AdminGateway {
            return new AdminGateway(
                $container->get(ResourceRegistryInterface::class),
                $container->get(ListResourceHandler::class),
                $container->get(ViewResourceHandler::class),
                $container->get(CreateResourceHandler::class),
                $container->get(UpdateResourceHandler::class),
                $container->get(DeleteResourceHandler::class),
                $container->get(BulkActionHandler::class),
                $container->get(ExportResourceHandler::class),
                $container->get(GlobalSearchHandler::class),
                $container->get(DashboardHandler::class),
                $container->get(SavedViewsHandler::class),
            );
        });

        // Middleware
        $container->bind(AdminAccessMiddleware::class, static function () use ($container): AdminAccessMiddleware {
            return new AdminAccessMiddleware($container->get(AdminConfig::class));
        });
        $container->bind(AdminAuthMiddleware::class, static function () use ($container): AdminAuthMiddleware {
            return new AdminAuthMiddleware(
                $container->get(AdminAccessGate::class),
                $container->get(AdminConfig::class),
            );
        });
        $container->bind(AdminCsrfMiddleware::class, static function () use ($container): AdminCsrfMiddleware {
            return new AdminCsrfMiddleware($container->get(AdminConfig::class));
        });
        $container->bind(AdminCspMiddleware::class, static function () use ($container): AdminCspMiddleware {
            return new AdminCspMiddleware($container->get(AdminConfig::class));
        });
        $container->bind(AdminRateLimitMiddleware::class, static function () use ($container): AdminRateLimitMiddleware {
            return new AdminRateLimitMiddleware(
                $container->get(RateLimiterInterface::class),
            );
        });
        $container->bind(AdminAuditMiddleware::class, static function () use ($container): AdminAuditMiddleware {
            return new AdminAuditMiddleware($container->get(AuditLoggerInterface::class));
        });

        // Commands
        $container->bind(AdminServeCommand::class, static function () use ($container): AdminServeCommand {
            /** @var AdminConfig $config */
            $config = $container->get(AdminConfig::class);
            $basePath = getcwd() ?: '.';

            if ($container->has('app.base_path')) {
                $basePathValue = $container->get('app.base_path');

                if (is_string($basePathValue)) {
                    $basePath = $basePathValue;
                }
            }

            return new AdminServeCommand($config, $basePath);
        });

        // Schema DDL layer
        $container->bind(SchemaCapabilities::class, static function () use ($container): SchemaCapabilities {
            /** @var ConnectionInterface $connection */
            $connection = $container->get(ConnectionInterface::class);
            return new SchemaCapabilities($connection->driver(), $connection);
        });

        $container->bind(DdlCompiler::class, static function () use ($container): DdlCompiler {
            /** @var ConnectionInterface $connection */
            $connection = $container->get(ConnectionInterface::class);
            /** @var SchemaCapabilities $capabilities */
            $capabilities = $container->get(SchemaCapabilities::class);
            return new DdlCompiler($connection->driver(), $capabilities);
        });

        $container->bind(SchemaManager::class, static function () use ($container): SchemaManager {
            /** @var ConnectionInterface $connection */
            $connection = $container->get(ConnectionInterface::class);
            /** @var DdlCompiler $compiler */
            $compiler = $container->get(DdlCompiler::class);
            /** @var SchemaCapabilities $capabilities */
            $capabilities = $container->get(SchemaCapabilities::class);
            return new SchemaManager($connection, $compiler, $capabilities);
        });

        // Schema change log store
        $container->bind(SchemaChangeLogStoreInterface::class, static function () use ($container): SchemaChangeLogStoreInterface {
            /** @var AdminConfig $config */
            $config = $container->get(AdminConfig::class);
            $path = $config->storage->sqlitePath ?? sys_get_temp_dir() . '/pulsar_admin.sqlite';
            $pdo = new PDO("sqlite:$path");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return new SqliteSchemaChangeLogStore($pdo);
        });

        // Schema handlers
        $container->bind(CreateTableHandler::class, static function () use ($container): CreateTableHandler {
            /** @var AdminConfig $config */
            $config = $container->get(AdminConfig::class);
            return new CreateTableHandler(
                $container->get(SchemaManager::class),
                $container->get(DatabaseIntrospector::class),
                $container->get(AuditLoggerInterface::class),
                $container->get(SchemaChangeLogStoreInterface::class),
                $config->schema,
            );
        });

        $container->bind(AlterTableHandler::class, static function () use ($container): AlterTableHandler {
            /** @var AdminConfig $config */
            $config = $container->get(AdminConfig::class);
            return new AlterTableHandler(
                $container->get(SchemaManager::class),
                $container->get(SchemaCapabilities::class),
                $container->get(AuditLoggerInterface::class),
                $container->get(SchemaChangeLogStoreInterface::class),
                $config->schema,
            );
        });

        $container->bind(DropTableHandler::class, static function () use ($container): DropTableHandler {
            /** @var AdminConfig $config */
            $config = $container->get(AdminConfig::class);
            return new DropTableHandler(
                $container->get(SchemaManager::class),
                $container->get(DatabaseIntrospector::class),
                $container->get(AuditLoggerInterface::class),
                $container->get(SchemaChangeLogStoreInterface::class),
                $config->schema,
            );
        });

        $container->bind(RenameTableHandler::class, static function () use ($container): RenameTableHandler {
            /** @var AdminConfig $config */
            $config = $container->get(AdminConfig::class);
            return new RenameTableHandler(
                $container->get(SchemaManager::class),
                $container->get(DatabaseIntrospector::class),
                $container->get(AuditLoggerInterface::class),
                $container->get(SchemaChangeLogStoreInterface::class),
                $config->schema,
            );
        });

        $container->bind(PreviewDdlHandler::class, static function () use ($container): PreviewDdlHandler {
            return new PreviewDdlHandler(
                $container->get(SchemaManager::class),
                $container->get(SchemaCapabilities::class),
            );
        });

        // Schema middleware
        $container->bind(AdminSchemaMiddleware::class, static function () use ($container): AdminSchemaMiddleware {
            /** @var AdminConfig $config */
            $config = $container->get(AdminConfig::class);
            /** @var PolicyInterface $policy */
            $policy = $container->get(PolicyInterface::class);
            return new AdminSchemaMiddleware($config->schema, $policy);
        });

        // Schema controllers
        $container->bind(SchemaController::class, static function () use ($container): SchemaController {
            /** @var AdminConfig $config */
            $config = $container->get(AdminConfig::class);
            return new SchemaController(
                $container->get(DatabaseIntrospector::class),
                $container->get(SchemaCapabilities::class),
                $config->schema,
                $container->get(SchemaChangeLogStoreInterface::class),
            );
        });

        $container->bind(SchemaApiController::class, static function () use ($container): SchemaApiController {
            return new SchemaApiController(
                $container->get(CreateTableHandler::class),
                $container->get(AlterTableHandler::class),
                $container->get(DropTableHandler::class),
                $container->get(RenameTableHandler::class),
                $container->get(PreviewDdlHandler::class),
                $container->get(SchemaChangeLogStoreInterface::class),
            );
        });

        // Controllers
        $container->bind(DashboardController::class, static function () use ($container): DashboardController {
            return new DashboardController(
                $container->get(DashboardHandler::class),
                $container->get(AdminConfig::class),
            );
        });
        $container->bind(ResourceIndexController::class, static function () use ($container): ResourceIndexController {
            return new ResourceIndexController(
                $container->get(ResourceRegistryInterface::class),
                $container->get(AdminConfig::class),
            );
        });
        $container->bind(ResourceListController::class, static function () use ($container): ResourceListController {
            return new ResourceListController(
                $container->get(ListResourceHandler::class),
                $container->get(ResourceRegistryInterface::class),
                $container->get(AdminConfig::class),
            );
        });
        $container->bind(ResourceViewController::class, static function () use ($container): ResourceViewController {
            return new ResourceViewController(
                $container->get(ViewResourceHandler::class),
                $container->get(ResourceRegistryInterface::class),
                $container->get(AdminConfig::class),
            );
        });
        $container->bind(ResourceCreateController::class, static function () use ($container): ResourceCreateController {
            return new ResourceCreateController(
                $container->get(CreateResourceHandler::class),
                $container->get(ResourceRegistryInterface::class),
                $container->get(AdminConfig::class),
            );
        });
        $container->bind(ResourceUpdateController::class, static function () use ($container): ResourceUpdateController {
            return new ResourceUpdateController(
                $container->get(UpdateResourceHandler::class),
                $container->get(ResourceRegistryInterface::class),
                $container->get(AdminConfig::class),
            );
        });
        $container->bind(ResourceDeleteController::class, static function () use ($container): ResourceDeleteController {
            return new ResourceDeleteController($container->get(DeleteResourceHandler::class));
        });
        $container->bind(BulkActionController::class, static function () use ($container): BulkActionController {
            return new BulkActionController($container->get(BulkActionHandler::class));
        });
        $container->bind(ExportController::class, static function () use ($container): ExportController {
            return new ExportController($container->get(ExportResourceHandler::class));
        });
        $container->bind(SearchController::class, static function () use ($container): SearchController {
            return new SearchController(
                $container->get(GlobalSearchHandler::class),
                $container->get(AdminConfig::class),
            );
        });
        $container->bind(SavedViewsController::class, static function () use ($container): SavedViewsController {
            return new SavedViewsController($container->get(SavedViewsHandler::class));
        });
        $container->bind(ActionHistoryController::class, static function () use ($container): ActionHistoryController {
            return new ActionHistoryController(
                $container->get(ActionHistoryStoreInterface::class),
                $container->get(AdminConfig::class),
            );
        });
    }

    #[Override]
    public function provides(): array
    {
        return [
            AdminConfig::class,
            ResourceRegistryInterface::class,
            ResourceQueryInterface::class,
            ResourceMutatorInterface::class,
            AdminGateway::class,
            AdminAccessGate::class,
            AdminSafetyMode::class,
        ];
    }
}
