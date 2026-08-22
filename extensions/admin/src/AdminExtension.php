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
use Pulsar\Extension\Admin\Internal\Middleware\AdminAccessMiddleware;
use Pulsar\Extension\Admin\Internal\Middleware\AdminAuditMiddleware;
use Pulsar\Extension\Admin\Internal\Middleware\AdminAuthMiddleware;
use Pulsar\Extension\Admin\Internal\Middleware\AdminCspMiddleware;
use Pulsar\Extension\Admin\Internal\Middleware\AdminCsrfMiddleware;
use Pulsar\Extension\Admin\Internal\Middleware\AdminRateLimitMiddleware;
use Pulsar\Extension\Admin\Internal\Middleware\AdminSchemaMiddleware;
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
use Pulsar\Http\Method;
use Pulsar\Routing\Route;
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

        // Every admin route runs the full security stack (outermost first):
        // audit log -> CSP headers -> panel-enabled gate -> rate limit ->
        // authentication/2FA/access-gate -> CSRF. The bound middlewares only
        // execute when attached to the route; without this the admin panel
        // would serve unauthenticated CRUD and schema DDL.
        $secure = [
            AdminAuditMiddleware::class,
            AdminCspMiddleware::class,
            AdminAccessMiddleware::class,
            AdminRateLimitMiddleware::class,
            AdminAuthMiddleware::class,
            AdminCsrfMiddleware::class,
        ];

        // Schema DDL routes additionally enforce per-operation permissions
        // and step-up auth for destructive changes.
        $schemaSecure = [...$secure, AdminSchemaMiddleware::class];

        // Dashboard
        $this->route($router, Method::GET, $prefix, [DashboardController::class, 'index'], 'admin.dashboard', $secure);

        // Search
        $this->route($router, Method::GET, "$prefix/search", [SearchController::class, 'search'], 'admin.search', $secure);

        // Resource index
        $this->route($router, Method::GET, "$prefix/resources", [ResourceIndexController::class, 'index'], 'admin.resources', $secure);

        // Action history
        $this->route($router, Method::GET, "$prefix/activity", [ActionHistoryController::class, 'recent'], 'admin.activity', $secure);
        $this->route($router, Method::GET, "$prefix/activity/{resource}", [ActionHistoryController::class, 'forResource'], 'admin.activity.resource', $secure);

        // Resource CRUD
        $this->route($router, Method::GET, "$prefix/resources/{resource}", [ResourceListController::class, 'list'], 'admin.resource.list', $secure);
        $this->route($router, Method::GET, "$prefix/resources/{resource}/create", [ResourceCreateController::class, 'form'], 'admin.resource.create.form', $secure);
        $this->route($router, Method::POST, "$prefix/resources/{resource}", [ResourceCreateController::class, 'store'], 'admin.resource.create', $secure);
        $this->route($router, Method::GET, "$prefix/resources/{resource}/export", [ExportController::class, 'export'], 'admin.resource.export', $secure);
        $this->route($router, Method::GET, "$prefix/resources/{resource}/{id}", [ResourceViewController::class, 'view'], 'admin.resource.view', $secure);
        $this->route($router, Method::GET, "$prefix/resources/{resource}/{id}/edit", [ResourceUpdateController::class, 'form'], 'admin.resource.edit.form', $secure);
        $this->route($router, Method::PUT, "$prefix/resources/{resource}/{id}", [ResourceUpdateController::class, 'update'], 'admin.resource.update', $secure);
        $this->route($router, Method::DELETE, "$prefix/resources/{resource}/{id}", [ResourceDeleteController::class, 'delete'], 'admin.resource.delete', $secure);

        // Bulk actions
        $this->route($router, Method::POST, "$prefix/resources/{resource}/bulk", [BulkActionController::class, 'execute'], 'admin.resource.bulk', $secure);

        // Saved views
        $this->route($router, Method::GET, "$prefix/resources/{resource}/views", [SavedViewsController::class, 'list'], 'admin.resource.views', $secure);
        $this->route($router, Method::POST, "$prefix/resources/{resource}/views", [SavedViewsController::class, 'store'], 'admin.resource.views.store', $secure);
        $this->route($router, Method::DELETE, "$prefix/resources/{resource}/views/{viewId}", [SavedViewsController::class, 'delete'], 'admin.resource.views.delete', $secure);

        // Schema Builder routes (gated by config)
        if ($config->schema->enabled) {
            // HTML pages
            $this->route($router, Method::GET, "$prefix/schema", [SchemaController::class, 'list'], 'admin.schema', $schemaSecure);
            $this->route($router, Method::GET, "$prefix/schema/create", [SchemaController::class, 'createForm'], 'admin.schema.create.form', $schemaSecure);
            $this->route($router, Method::GET, "$prefix/schema/changelog", [SchemaController::class, 'changelog'], 'admin.schema.changelog', $schemaSecure);
            $this->route($router, Method::GET, "$prefix/schema/{table}", [SchemaController::class, 'view'], 'admin.schema.view', $schemaSecure);

            // JSON API: mutations
            $this->route($router, Method::POST, "$prefix/api/schema", [SchemaApiController::class, 'create'], 'admin.api.schema.create', $schemaSecure);
            $this->route($router, Method::DELETE, "$prefix/api/schema/{table}", [SchemaApiController::class, 'dropTable'], 'admin.api.schema.drop', $schemaSecure);
            $this->route($router, Method::POST, "$prefix/api/schema/{table}/rename", [SchemaApiController::class, 'renameTable'], 'admin.api.schema.rename', $schemaSecure);
            $this->route($router, Method::POST, "$prefix/api/schema/{table}/columns", [SchemaApiController::class, 'addColumn'], 'admin.api.schema.add_column', $schemaSecure);
            $this->route($router, Method::DELETE, "$prefix/api/schema/{table}/columns/{col}", [SchemaApiController::class, 'dropColumn'], 'admin.api.schema.drop_column', $schemaSecure);
            $this->route($router, Method::POST, "$prefix/api/schema/{table}/indexes", [SchemaApiController::class, 'addIndex'], 'admin.api.schema.add_index', $schemaSecure);
            $this->route($router, Method::DELETE, "$prefix/api/schema/{table}/indexes/{name}", [SchemaApiController::class, 'dropIndex'], 'admin.api.schema.drop_index', $schemaSecure);

            // Preview routes (read-only)
            $this->route($router, Method::POST, "$prefix/api/schema/preview/create", [SchemaApiController::class, 'previewCreate'], 'admin.api.schema.preview.create', $schemaSecure);
            $this->route($router, Method::POST, "$prefix/api/schema/preview/{table}/add-column", [SchemaApiController::class, 'previewAddColumn'], 'admin.api.schema.preview.add_column', $schemaSecure);
            $this->route($router, Method::POST, "$prefix/api/schema/preview/{table}/drop-column", [SchemaApiController::class, 'previewDropColumn'], 'admin.api.schema.preview.drop_column', $schemaSecure);
            $this->route($router, Method::POST, "$prefix/api/schema/preview/{table}/add-index", [SchemaApiController::class, 'previewAddIndex'], 'admin.api.schema.preview.add_index', $schemaSecure);
            $this->route($router, Method::POST, "$prefix/api/schema/preview/{table}/drop-index", [SchemaApiController::class, 'previewDropIndex'], 'admin.api.schema.preview.drop_index', $schemaSecure);
            $this->route($router, Method::POST, "$prefix/api/schema/preview/{table}/drop", [SchemaApiController::class, 'previewDropTable'], 'admin.api.schema.preview.drop', $schemaSecure);
            $this->route($router, Method::POST, "$prefix/api/schema/preview/{table}/rename", [SchemaApiController::class, 'previewRenameTable'], 'admin.api.schema.preview.rename', $schemaSecure);

            // Changelog API + export
            $this->route($router, Method::GET, "$prefix/api/schema/changelog", [SchemaApiController::class, 'changelog'], 'admin.api.schema.changelog', $schemaSecure);
            $this->route($router, Method::GET, "$prefix/api/schema/changelog/export", [SchemaApiController::class, 'exportBundle'], 'admin.api.schema.changelog.export', $schemaSecure);
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
     * Register a single admin route with its security middleware stack.
     *
     * @param array{0: class-string, 1: string} $handler
     * @param list<class-string>                 $middleware
     */
    private function route(RouterInterface $router, Method $method, string $path, array $handler, string $name, array $middleware): void
    {
        $router->add(new Route(
            methods: [$method],
            path: $path,
            handler: $handler,
            name: $name,
            middleware: $middleware,
        ));
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
