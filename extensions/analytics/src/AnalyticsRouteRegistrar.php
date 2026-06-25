<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics;

use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Config\AnalyticsConfig;
use Pulsar\Extension\Analytics\Internal\Middleware\AnalyticsAuthMiddleware;
use Pulsar\Extension\Analytics\Internal\Middleware\BotFilterMiddleware;
use Pulsar\Extension\Analytics\Internal\Middleware\CollectionCorsMiddleware;
use Pulsar\Extension\Analytics\Internal\Middleware\CollectionRateLimitMiddleware;
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
use Pulsar\Routing\Route;
use Pulsar\Routing\RouterInterface;
use Pulsar\Security\Csrf\CsrfMiddleware;

/**
 * Registers all analytics HTTP routes (collection, API, dashboard, consent,
 * DSAR) with the router at boot time.
 *
 * Extracted from {@see AnalyticsExtension} so the extension's lifecycle hooks
 * stay focused on lifecycle and the (boot-time, non-hot-path) route table lives
 * in one place. Behaviour-preserving relocation.
 */
#[Internal(reason: 'Analytics route table; consumed by AnalyticsExtension::boot()')]
final readonly class AnalyticsRouteRegistrar
{
    /**
     * Register every analytics route group.
     */
    public function register(RouterInterface $router, AnalyticsConfig $config): void
    {
        $this->registerPublicRoutes($router, $config);
        $this->registerApiRoutes($router);
        $this->registerDashboardRoutes($router);
        $this->registerConsentRoutes($router);
        $this->registerDsarRoutes($router);
    }

    private function registerPublicRoutes(RouterInterface $router, AnalyticsConfig $config): void
    {
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
}
