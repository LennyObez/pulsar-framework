<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics;

use Override;
use Pulsar\Api\Api;
use Pulsar\Config\ConfigManagerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\PostBootExtensionInterface;
use Pulsar\Extensibility\PreBootExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Analytics\Config\AnalyticsConfig;
use Pulsar\Extension\Analytics\Contracts\FunnelServiceInterface;
use Pulsar\Extension\Analytics\Contracts\GoalServiceInterface;
use Pulsar\Extension\Analytics\Contracts\SiteRepositoryInterface;
use Pulsar\Extension\Analytics\ImportExport\AnalyticsImportExportProvider;
use Pulsar\Extension\Analytics\Internal\Middleware\AnalyticsAuthMiddleware;
use Pulsar\Extension\Analytics\Internal\Middleware\BotFilterMiddleware;
use Pulsar\Extension\Analytics\Internal\Middleware\CollectionCorsMiddleware;
use Pulsar\Extension\Analytics\Internal\Middleware\CollectionRateLimitMiddleware;
use Pulsar\Extension\Analytics\Internal\Scheduler\AggregationJob;
use Pulsar\Extension\Analytics\Internal\Scheduler\PartitionMaintenanceJob;
use Pulsar\Extension\Analytics\Internal\Scheduler\RetentionCleanupJob;
use Pulsar\Extension\Analytics\Server\Controller\AttributionController;
use Pulsar\Extension\Analytics\Server\Controller\BreakdownController;
use Pulsar\Extension\Analytics\Server\Controller\CollectionController;
use Pulsar\Extension\Analytics\Server\Controller\ConsentController;
use Pulsar\Extension\Analytics\Server\Controller\CustomEventController;
use Pulsar\Extension\Analytics\Server\Controller\DashboardController;
use Pulsar\Extension\Analytics\Server\Controller\DsarController;
use Pulsar\Extension\Analytics\Server\Controller\EcommerceController;
use Pulsar\Extension\Analytics\Server\Controller\ExportController;
use Pulsar\Extension\Analytics\Server\Controller\FlowController;
use Pulsar\Extension\Analytics\Server\Controller\FunnelController;
use Pulsar\Extension\Analytics\Server\Controller\GoalController;
use Pulsar\Extension\Analytics\Server\Controller\RealtimeController;
use Pulsar\Extension\Analytics\Server\Controller\SearchAnalyticsController;
use Pulsar\Extension\Analytics\Server\Controller\SegmentController;
use Pulsar\Extension\Analytics\Server\Controller\SiteController;
use Pulsar\Extension\Analytics\Server\Controller\StatsController;
use Pulsar\Extension\Analytics\Server\Controller\TimeseriesController;
use Pulsar\Extension\Analytics\Server\Controller\TrackerController;
use Pulsar\Http\Method;
use Pulsar\ImportExport\ImportExportRegistry;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouterInterface;
use Pulsar\Scheduler\JobRegistryInterface;
use Pulsar\Scheduler\Schedule;
use Pulsar\Security\Csrf\CsrfMiddleware;

use function is_array;
use function is_file;

use const DIRECTORY_SEPARATOR;

/**
 * Privacy-focused, self-hosted web analytics extension.
 *
 * Provides page view tracking, session management, custom events, goals,
 * and multi-site analytics without cookies: fully GDPR/ePrivacy compliant.
 *
 * @psalm-api Loaded by the framework's ExtensionLoader at boot time
 *            via the pulsar.json manifest, never instantiated by name.
 */
#[Api(since: '1.0.0')]
final readonly class AnalyticsExtension implements
    ExtensionInterface,
    PreBootExtensionInterface,
    PostBootExtensionInterface
{
    #[Override]
    public function name(): string
    {
        return 'pulsar/analytics';
    }

    #[Override]
    public function register(ContainerInterface $container): void
    {
        // Service provider handles all bindings
    }

    #[Override]
    public function preBoot(ContainerInterface $container): void
    {
        if (!$container->has(AnalyticsConfig::class) && $container->has(ConfigManagerInterface::class)) {
            $configManager = $container->get(ConfigManagerInterface::class);
            $configPath = $configManager->configPath();

            if ($configPath !== null && is_file($configPath . DIRECTORY_SEPARATOR . 'analytics.php')) {
                /** @var mixed $data */
                $data = require $configPath . DIRECTORY_SEPARATOR . 'analytics.php';

                if (is_array($data)) {
                    /** @var array<string, mixed> $data */
                    $container->instance(AnalyticsConfig::class, AnalyticsConfig::fromArray($data));
                }
            }
        }

        if (!$container->has(AnalyticsConfig::class)) {
            $container->instance(AnalyticsConfig::class, new AnalyticsConfig());
        }
    }

    #[Override]
    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        $config = $container->get(AnalyticsConfig::class);

        if (!$config->enabled) {
            return;
        }

        $this->registerPublicRoutes($router, $container);
        $this->registerApiRoutes($router);
        $this->registerDashboardRoutes($router);
        $this->registerConsentRoutes($router);
        $this->registerDsarRoutes($router);
    }

    #[Override]
    public function postBoot(ContainerInterface $container): void
    {
        $config = $container->get(AnalyticsConfig::class);

        if (!$config->enabled) {
            return;
        }

        if ($container->has(JobRegistryInterface::class)) {
            $this->registerSchedulerJobs($container);
        }

        $this->registerImportExportProvider($container);
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    #[Override]
    public function providers(): array
    {
        return [AnalyticsServiceProvider::class];
    }

    private function registerPublicRoutes(RouterInterface $router, ContainerInterface $container): void
    {
        $config = $container->get(AnalyticsConfig::class);

        $router->add(new Route(
            methods: [Method::POST],
            path: $config->tracking->trackerEndpoint,
            handler: [CollectionController::class, 'collect'],
            name: 'analytics.collect',
            middleware: [
                CollectionRateLimitMiddleware::class,
                CollectionCorsMiddleware::class,
                BotFilterMiddleware::class,
            ],
        ));

        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: $config->tracking->scriptEndpoint,
            handler: [TrackerController::class, 'script'],
            name: 'analytics.tracker',
        ));
    }

    private function registerApiRoutes(RouterInterface $router): void
    {
        $prefix = '/plsr/api/v1';
        $authMiddleware = [AnalyticsAuthMiddleware::class];
        $mutationMiddleware = [AnalyticsAuthMiddleware::class, CsrfMiddleware::class];

        // Stats endpoints
        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: "$prefix/stats/aggregate",
            handler: [StatsController::class, 'aggregate'],
            name: 'analytics.api.stats',
            middleware: $authMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: "$prefix/stats/timeseries",
            handler: [TimeseriesController::class, 'timeseries'],
            name: 'analytics.api.timeseries',
            middleware: $authMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: "$prefix/stats/breakdown",
            handler: [BreakdownController::class, 'breakdown'],
            name: 'analytics.api.breakdown',
            middleware: $authMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: "$prefix/stats/realtime",
            handler: [RealtimeController::class, 'realtime'],
            name: 'analytics.api.realtime',
            middleware: $authMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: "$prefix/export",
            handler: [ExportController::class, 'export'],
            name: 'analytics.api.export',
            middleware: $authMiddleware,
        ));

        // Goals CRUD
        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: "$prefix/goals",
            handler: [GoalController::class, 'index'],
            name: 'analytics.api.goals.index',
            middleware: $authMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::POST],
            path: "$prefix/goals",
            handler: [GoalController::class, 'create'],
            name: 'analytics.api.goals.create',
            middleware: $mutationMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: "$prefix/goals/{id}",
            handler: [GoalController::class, 'show'],
            name: 'analytics.api.goals.show',
            middleware: $authMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::PUT],
            path: "$prefix/goals/{id}",
            handler: [GoalController::class, 'update'],
            name: 'analytics.api.goals.update',
            middleware: $mutationMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::DELETE],
            path: "$prefix/goals/{id}",
            handler: [GoalController::class, 'delete'],
            name: 'analytics.api.goals.delete',
            middleware: $mutationMiddleware,
        ));

        // Flow / Behavior Flow
        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: "$prefix/flow",
            handler: [FlowController::class, 'flow'],
            name: 'analytics.api.flow',
            middleware: $authMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: "$prefix/flow/exits",
            handler: [FlowController::class, 'exits'],
            name: 'analytics.api.flow.exits',
            middleware: $authMiddleware,
        ));

        // Funnels
        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: "$prefix/funnels",
            handler: [FunnelController::class, 'index'],
            name: 'analytics.api.funnels.index',
            middleware: $authMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::POST],
            path: "$prefix/funnels",
            handler: [FunnelController::class, 'create'],
            name: 'analytics.api.funnels.create',
            middleware: $mutationMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: "$prefix/funnels/{id}/evaluate",
            handler: [FunnelController::class, 'evaluate'],
            name: 'analytics.api.funnels.evaluate',
            middleware: $authMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::DELETE],
            path: "$prefix/funnels/{id}",
            handler: [FunnelController::class, 'delete'],
            name: 'analytics.api.funnels.delete',
            middleware: $mutationMiddleware,
        ));

        // E-commerce
        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: "$prefix/ecommerce/summary",
            handler: [EcommerceController::class, 'summary'],
            name: 'analytics.api.ecommerce.summary',
            middleware: $authMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: "$prefix/ecommerce/products",
            handler: [EcommerceController::class, 'products'],
            name: 'analytics.api.ecommerce.products',
            middleware: $authMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: "$prefix/ecommerce/revenue",
            handler: [EcommerceController::class, 'revenue'],
            name: 'analytics.api.ecommerce.revenue',
            middleware: $authMiddleware,
        ));

        // Custom Events
        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: "$prefix/events/names",
            handler: [CustomEventController::class, 'names'],
            name: 'analytics.api.events.names',
            middleware: $authMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: "$prefix/events/properties",
            handler: [CustomEventController::class, 'properties'],
            name: 'analytics.api.events.properties',
            middleware: $authMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: "$prefix/events/timeseries",
            handler: [CustomEventController::class, 'timeseries'],
            name: 'analytics.api.events.timeseries',
            middleware: $authMiddleware,
        ));

        // Segments
        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: "$prefix/segments",
            handler: [SegmentController::class, 'index'],
            name: 'analytics.api.segments.index',
            middleware: $authMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::POST],
            path: "$prefix/segments",
            handler: [SegmentController::class, 'create'],
            name: 'analytics.api.segments.create',
            middleware: $mutationMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: "$prefix/segments/{id}/count",
            handler: [SegmentController::class, 'count'],
            name: 'analytics.api.segments.count',
            middleware: $authMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::DELETE],
            path: "$prefix/segments/{id}",
            handler: [SegmentController::class, 'delete'],
            name: 'analytics.api.segments.delete',
            middleware: $mutationMiddleware,
        ));

        // Attribution
        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: "$prefix/attribution",
            handler: [AttributionController::class, 'calculate'],
            name: 'analytics.api.attribution',
            middleware: $authMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: "$prefix/attribution/compare",
            handler: [AttributionController::class, 'compare'],
            name: 'analytics.api.attribution.compare',
            middleware: $authMiddleware,
        ));

        // Search Analytics
        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: "$prefix/search/overview",
            handler: [SearchAnalyticsController::class, 'overview'],
            name: 'analytics.api.search.overview',
            middleware: $authMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: "$prefix/search/queries",
            handler: [SearchAnalyticsController::class, 'topQueries'],
            name: 'analytics.api.search.queries',
            middleware: $authMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: "$prefix/search/zero-results",
            handler: [SearchAnalyticsController::class, 'zeroResults'],
            name: 'analytics.api.search.zero_results',
            middleware: $authMiddleware,
        ));

        // Sites CRUD
        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: "$prefix/sites",
            handler: [SiteController::class, 'index'],
            name: 'analytics.api.sites.index',
            middleware: $authMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::POST],
            path: "$prefix/sites",
            handler: [SiteController::class, 'create'],
            name: 'analytics.api.sites.create',
            middleware: $mutationMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: "$prefix/sites/{id}",
            handler: [SiteController::class, 'show'],
            name: 'analytics.api.sites.show',
            middleware: $authMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::PUT],
            path: "$prefix/sites/{id}",
            handler: [SiteController::class, 'update'],
            name: 'analytics.api.sites.update',
            middleware: $mutationMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::DELETE],
            path: "$prefix/sites/{id}",
            handler: [SiteController::class, 'delete'],
            name: 'analytics.api.sites.delete',
            middleware: $mutationMiddleware,
        ));
    }

    private function registerDashboardRoutes(RouterInterface $router): void
    {
        $authMiddleware = [AnalyticsAuthMiddleware::class];

        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: '/analytics',
            handler: [DashboardController::class, 'index'],
            name: 'analytics.dashboard',
            middleware: $authMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: '/analytics/sites',
            handler: [DashboardController::class, 'sites'],
            name: 'analytics.dashboard.sites',
            middleware: $authMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: '/analytics/goals',
            handler: [DashboardController::class, 'goals'],
            name: 'analytics.dashboard.goals',
            middleware: $authMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: '/analytics/settings',
            handler: [DashboardController::class, 'settings'],
            name: 'analytics.dashboard.settings',
            middleware: $authMiddleware,
        ));

        // GA4-level dashboard pages
        $ga4Pages = ['funnels', 'ecommerce', 'events', 'segments', 'attribution', 'flow', 'search'];

        foreach ($ga4Pages as $page) {
            $router->add(new Route(
                methods: [Method::GET, Method::HEAD],
                path: "/analytics/{$page}",
                handler: [DashboardController::class, 'index'],
                name: "analytics.dashboard.{$page}",
                middleware: $authMiddleware,
            ));
        }

        // Static assets do not require auth; served publicly
        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: '/analytics/assets/{path}',
            handler: [DashboardController::class, 'asset'],
            name: 'analytics.assets',
        ));
    }

    private function registerConsentRoutes(RouterInterface $router): void
    {
        $consentMiddleware = [CsrfMiddleware::class];

        $router->add(new Route(
            methods: [Method::POST],
            path: '/plsr/consent/grant',
            handler: [ConsentController::class, 'grant'],
            name: 'analytics.consent.grant',
            middleware: $consentMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::POST],
            path: '/plsr/consent/revoke',
            handler: [ConsentController::class, 'revoke'],
            name: 'analytics.consent.revoke',
            middleware: $consentMiddleware,
        ));
    }

    private function registerDsarRoutes(RouterInterface $router): void
    {
        $dsarMiddleware = [AnalyticsAuthMiddleware::class, CsrfMiddleware::class];

        $router->add(new Route(
            methods: [Method::POST],
            path: '/plsr/dsar/request',
            handler: [DsarController::class, 'request'],
            name: 'analytics.dsar.request',
            middleware: $dsarMiddleware,
        ));

        $router->add(new Route(
            methods: [Method::POST],
            path: '/plsr/dsar/erase',
            handler: [DsarController::class, 'erase'],
            name: 'analytics.dsar.erase',
            middleware: $dsarMiddleware,
        ));
    }

    private function registerImportExportProvider(ContainerInterface $container): void
    {
        if (!$container->has(ImportExportRegistry::class)) {
            return;
        }

        if (
            !$container->has(SiteRepositoryInterface::class)
            || !$container->has(GoalServiceInterface::class)
            || !$container->has(FunnelServiceInterface::class)
        ) {
            return;
        }

        /** @var ImportExportRegistry $registry */
        $registry = $container->get(ImportExportRegistry::class);

        $registry->register(new AnalyticsImportExportProvider(
            $container->get(SiteRepositoryInterface::class),
            $container->get(GoalServiceInterface::class),
            $container->get(FunnelServiceInterface::class),
        ));
    }

    private function registerSchedulerJobs(ContainerInterface $container): void
    {
        $registry = $container->get(JobRegistryInterface::class);

        $registry->register(
            AggregationJob::class,
            Schedule::hourly(),
        );

        $registry->register(
            RetentionCleanupJob::class,
            Schedule::dailyAt('02:00'),
        );

        $registry->register(
            PartitionMaintenanceJob::class,
            Schedule::weekly(),
        );
    }
}
