<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin;

use Override;
use Pulsar\Config\ConfigManagerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Introspection\DatabaseIntrospector;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\PreBootExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Internal\Adapter\IntrospectedResourceFactory;
use Pulsar\Extension\Admin\Internal\AdminStudioModule;
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
use Pulsar\Extension\Studio\Contracts\StudioModuleRegistryInterface;
use Pulsar\Routing\RouterInterface;

use function is_array;
use function is_file;

use const DIRECTORY_SEPARATOR;

/**
 * Admin panel extension.
 *
 * Provides a full-featured admin CRUD interface with audit logging,
 * export with evidence hashing, dashboard widgets, and saved views.
 * Enabled by default in local/dev. In staging/production, requires
 * explicit environment variables.
 *
 * @psalm-api Loaded by the framework's ExtensionLoader at boot time
 *            via the pulsar.json manifest, never instantiated by name.
 */
final readonly class AdminExtension implements ExtensionInterface, PreBootExtensionInterface
{
    #[Override]
    public function name(): string
    {
        return 'pulsar/admin';
    }

    #[Override]
    public function register(ContainerInterface $container): void
    {
        // Service provider handles all bindings
    }

    #[Override]
    public function preBoot(ContainerInterface $container): void
    {
        // Load admin config from config directory (same pattern as Studio)
        if ($container->has(ConfigManagerInterface::class)) {
            /** @var ConfigManagerInterface $configManager */
            $configManager = $container->get(ConfigManagerInterface::class);
            $configPath = $configManager->configPath();

            if ($configPath !== null && is_file($configPath . DIRECTORY_SEPARATOR . 'admin.php')) {
                /**
                 * @psalm-suppress UnresolvableInclude
                 *
                 * @var mixed $adminData
                 */
                $adminData = require $configPath . DIRECTORY_SEPARATOR . 'admin.php';

                if (is_array($adminData)) {
                    /** @var array<string, mixed> $adminData */
                    $adminConfig = AdminConfig::fromArray($adminData);
                    $container->instance(AdminConfig::class, $adminConfig);
                }
            }
        }

        // Ensure AdminConfig exists in container (with defaults if no config file)
        if (!$container->has(AdminConfig::class)) {
            $container->instance(AdminConfig::class, AdminConfig::fromArray([]));
        }

        // Register as a Studio module
        if ($container->has(StudioModuleRegistryInterface::class)) {
            /** @var StudioModuleRegistryInterface $studioRegistry */
            $studioRegistry = $container->get(StudioModuleRegistryInterface::class);
            $studioRegistry->register(new AdminStudioModule());
        }
    }

    #[Override]
    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        /** @var AdminConfig $config */
        $config = $container->get(AdminConfig::class);

        if (!$config->enabled) {
            return;
        }

        $prefix = rtrim($config->routePrefix, '/');

        // Dashboard
        $router->get($prefix, [DashboardController::class, 'index'], 'admin.dashboard');

        // Search
        $router->get("$prefix/search", [SearchController::class, 'search'], 'admin.search');

        // Resource index
        $router->get("$prefix/resources", [ResourceIndexController::class, 'index'], 'admin.resources');

        // Action history
        $router->get("$prefix/activity", [ActionHistoryController::class, 'recent'], 'admin.activity');
        $router->get("$prefix/activity/{resource}", [ActionHistoryController::class, 'forResource'], 'admin.activity.resource');

        // Resource CRUD
        $router->get("$prefix/resources/{resource}", [ResourceListController::class, 'list'], 'admin.resource.list');
        $router->get("$prefix/resources/{resource}/create", [ResourceCreateController::class, 'form'], 'admin.resource.create.form');
        $router->post("$prefix/resources/{resource}", [ResourceCreateController::class, 'store'], 'admin.resource.create');
        $router->get("$prefix/resources/{resource}/export", [ExportController::class, 'export'], 'admin.resource.export');
        $router->get("$prefix/resources/{resource}/{id}", [ResourceViewController::class, 'view'], 'admin.resource.view');
        $router->get("$prefix/resources/{resource}/{id}/edit", [ResourceUpdateController::class, 'form'], 'admin.resource.edit.form');
        $router->put("$prefix/resources/{resource}/{id}", [ResourceUpdateController::class, 'update'], 'admin.resource.update');
        $router->delete("$prefix/resources/{resource}/{id}", [ResourceDeleteController::class, 'delete'], 'admin.resource.delete');

        // Bulk actions
        $router->post("$prefix/resources/{resource}/bulk", [BulkActionController::class, 'execute'], 'admin.resource.bulk');

        // Saved views
        $router->get("$prefix/resources/{resource}/views", [SavedViewsController::class, 'list'], 'admin.resource.views');
        $router->post("$prefix/resources/{resource}/views", [SavedViewsController::class, 'store'], 'admin.resource.views.store');
        $router->delete("$prefix/resources/{resource}/views/{viewId}", [SavedViewsController::class, 'delete'], 'admin.resource.views.delete');

        // Schema Builder routes (gated by config)
        if ($config->schema->enabled) {
            // HTML pages
            $router->get("$prefix/schema", [SchemaController::class, 'list'], 'admin.schema');
            $router->get("$prefix/schema/create", [SchemaController::class, 'createForm'], 'admin.schema.create.form');
            $router->get("$prefix/schema/changelog", [SchemaController::class, 'changelog'], 'admin.schema.changelog');
            $router->get("$prefix/schema/{table}", [SchemaController::class, 'view'], 'admin.schema.view');

            // JSON API: mutations
            $router->post("$prefix/api/schema", [SchemaApiController::class, 'create'], 'admin.api.schema.create');
            $router->delete("$prefix/api/schema/{table}", [SchemaApiController::class, 'dropTable'], 'admin.api.schema.drop');
            $router->post("$prefix/api/schema/{table}/rename", [SchemaApiController::class, 'renameTable'], 'admin.api.schema.rename');
            $router->post("$prefix/api/schema/{table}/columns", [SchemaApiController::class, 'addColumn'], 'admin.api.schema.add_column');
            $router->delete("$prefix/api/schema/{table}/columns/{col}", [SchemaApiController::class, 'dropColumn'], 'admin.api.schema.drop_column');
            $router->post("$prefix/api/schema/{table}/indexes", [SchemaApiController::class, 'addIndex'], 'admin.api.schema.add_index');
            $router->delete("$prefix/api/schema/{table}/indexes/{name}", [SchemaApiController::class, 'dropIndex'], 'admin.api.schema.drop_index');

            // Preview routes (read-only)
            $router->post("$prefix/api/schema/preview/create", [SchemaApiController::class, 'previewCreate'], 'admin.api.schema.preview.create');
            $router->post("$prefix/api/schema/preview/{table}/add-column", [SchemaApiController::class, 'previewAddColumn'], 'admin.api.schema.preview.add_column');
            $router->post("$prefix/api/schema/preview/{table}/drop-column", [SchemaApiController::class, 'previewDropColumn'], 'admin.api.schema.preview.drop_column');
            $router->post("$prefix/api/schema/preview/{table}/add-index", [SchemaApiController::class, 'previewAddIndex'], 'admin.api.schema.preview.add_index');
            $router->post("$prefix/api/schema/preview/{table}/drop-index", [SchemaApiController::class, 'previewDropIndex'], 'admin.api.schema.preview.drop_index');
            $router->post("$prefix/api/schema/preview/{table}/drop", [SchemaApiController::class, 'previewDropTable'], 'admin.api.schema.preview.drop');
            $router->post("$prefix/api/schema/preview/{table}/rename", [SchemaApiController::class, 'previewRenameTable'], 'admin.api.schema.preview.rename');

            // Changelog API + export
            $router->get("$prefix/api/schema/changelog", [SchemaApiController::class, 'changelog'], 'admin.api.schema.changelog');
            $router->get("$prefix/api/schema/changelog/export", [SchemaApiController::class, 'exportBundle'], 'admin.api.schema.changelog.export');
        }

        // Auto-discover database tables when no resources are manually registered
        if ($container->has(ResourceRegistryInterface::class) && $container->has(ConnectionInterface::class)) {
            /** @var ResourceRegistryInterface $registry */
            $registry = $container->get(ResourceRegistryInterface::class);

            if ($registry->all() === []) {
                /** @var ConnectionInterface $connection */
                $connection = $container->get(ConnectionInterface::class);
                $introspector = new DatabaseIntrospector($connection);
                $factory = new IntrospectedResourceFactory($introspector);

                $excludePrefixes = ['sqlite_', 'admin_', 'studio_', 'pulsar_'];

                foreach ($factory->discoverAll($excludePrefixes) as $resource) {
                    $registry->register($resource);
                }
            }
        }
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    #[Override]
    public function providers(): array
    {
        return [
            AdminServiceProvider::class,
        ];
    }
}
