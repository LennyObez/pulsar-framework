<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\DataProtection\ConsentManagerInterface;
use Pulsar\DataProtection\Dsar\DsarCollectorInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Analytics\Config\AnalyticsConfig;
use Pulsar\Extension\Analytics\Consent\AnalyticsConsentBanner;
use Pulsar\Extension\Analytics\Consent\AnalyticsConsentMiddleware;
use Pulsar\Extension\Analytics\Contracts\AttributionServiceInterface;
use Pulsar\Extension\Analytics\Contracts\CustomEventServiceInterface;
use Pulsar\Extension\Analytics\Contracts\DailyStatsRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\EcommerceServiceInterface;
use Pulsar\Extension\Analytics\Contracts\EventRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\FlowServiceInterface;
use Pulsar\Extension\Analytics\Contracts\FunnelServiceInterface;
use Pulsar\Extension\Analytics\Contracts\GoalServiceInterface;
use Pulsar\Extension\Analytics\Contracts\PageViewRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\SearchAnalyticsServiceInterface;
use Pulsar\Extension\Analytics\Contracts\SegmentServiceInterface;
use Pulsar\Extension\Analytics\Contracts\SessionRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\SiteRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\SiteServiceInterface;
use Pulsar\Extension\Analytics\Contracts\StatsServiceInterface;
use Pulsar\Extension\Analytics\Contracts\TrackingServiceInterface;
use Pulsar\Extension\Analytics\Contracts\VisitorSaltStoreInterface;
use Pulsar\Extension\Analytics\Dsar\AnalyticsDsarCollector;
use Pulsar\Extension\Analytics\Dsar\AnalyticsDsarEraser;
use Pulsar\Extension\Analytics\Internal\Bot\BotDetector;
use Pulsar\Extension\Analytics\Internal\Geo\DbIpLiteResolver;
use Pulsar\Extension\Analytics\Internal\Middleware\AnalyticsAuthMiddleware;
use Pulsar\Extension\Analytics\Internal\Middleware\BotFilterMiddleware;
use Pulsar\Extension\Analytics\Internal\Middleware\CollectionCorsMiddleware;
use Pulsar\Extension\Analytics\Internal\Middleware\CollectionRateLimitMiddleware;
use Pulsar\Extension\Analytics\Internal\Repository\DbDailyStatsRepository;
use Pulsar\Extension\Analytics\Internal\Repository\DbEventRepository;
use Pulsar\Extension\Analytics\Internal\Repository\DbGoalConversionRepository;
use Pulsar\Extension\Analytics\Internal\Repository\DbGoalRepository;
use Pulsar\Extension\Analytics\Internal\Repository\DbHourlyStatsRepository;
use Pulsar\Extension\Analytics\Internal\Repository\DbPageViewRepository;
use Pulsar\Extension\Analytics\Internal\Repository\DbSessionRepository;
use Pulsar\Extension\Analytics\Internal\Repository\DbSiteRepository;
use Pulsar\Extension\Analytics\Internal\Security\AnalyticsKeyManager;
use Pulsar\Extension\Analytics\Internal\Security\DbVisitorSaltStore;
use Pulsar\Extension\Analytics\Internal\Service\AggregationService;
use Pulsar\Extension\Analytics\Internal\Service\AttributionService;
use Pulsar\Extension\Analytics\Internal\Service\CustomEventService;
use Pulsar\Extension\Analytics\Internal\Service\EcommerceService;
use Pulsar\Extension\Analytics\Internal\Service\FlowService;
use Pulsar\Extension\Analytics\Internal\Service\FunnelService;
use Pulsar\Extension\Analytics\Internal\Service\GoalService;
use Pulsar\Extension\Analytics\Internal\Service\ReferrerParser;
use Pulsar\Extension\Analytics\Internal\Service\SearchAnalyticsService;
use Pulsar\Extension\Analytics\Internal\Service\SegmentService;
use Pulsar\Extension\Analytics\Internal\Service\SessionResolver;
use Pulsar\Extension\Analytics\Internal\Service\SiteService;
use Pulsar\Extension\Analytics\Internal\Service\StatsService;
use Pulsar\Extension\Analytics\Internal\Service\TrackingService;
use Pulsar\Extension\Analytics\Internal\Service\UserAgentParser;
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
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\ZeroTrust\Signal\GeoLocationResolverInterface;

/**
 * Wires all analytics services, repositories, controllers, and middleware.
 */
#[Internal(reason: 'Analytics service wiring; use interfaces for public API')]
final class AnalyticsServiceProvider implements ServiceProviderInterface
{
    #[Override]
    public function register(ContainerInterface $container): void
    {
        $config = $container->has(AnalyticsConfig::class)
            ? $container->get(AnalyticsConfig::class)
            : new AnalyticsConfig();

        if (!$config->enabled) {
            return;
        }

        // Security
        if ($container->has(MasterKey::class)) {
            $container->bind(AnalyticsKeyManager::class, static fn() => new AnalyticsKeyManager(
                $container->get(MasterKey::class),
            ));
        }

        // Pure services (no DB dependency)
        $container->bind(BotDetector::class, static fn() => new BotDetector());
        $container->bind(ReferrerParser::class, static fn() => new ReferrerParser());
        $container->bind(UserAgentParser::class, static fn() => new UserAgentParser());

        // Geo resolver
        if (!$container->has(GeoLocationResolverInterface::class)) {
            $container->bind(GeoLocationResolverInterface::class, static fn() => new DbIpLiteResolver());
        }

        // Database-dependent bindings
        if ($container->has(ConnectionInterface::class)) {
            $this->registerRepositories($container);
            $this->registerServices($container, $config);
            $this->registerMiddleware($container, $config);
            $this->registerControllers($container, $config);
        }
    }

    #[Override]
    public function provides(): array
    {
        return [
            TrackingServiceInterface::class,
            StatsServiceInterface::class,
            GoalServiceInterface::class,
            SiteServiceInterface::class,
            PageViewRepositoryInterface::class,
            EventRepositoryInterface::class,
            SessionRepositoryInterface::class,
            SiteRepositoryInterface::class,
            DailyStatsRepositoryInterface::class,
            FlowServiceInterface::class,
            FunnelServiceInterface::class,
            EcommerceServiceInterface::class,
            CustomEventServiceInterface::class,
            SegmentServiceInterface::class,
            AttributionServiceInterface::class,
            SearchAnalyticsServiceInterface::class,
            DsarCollectorInterface::class,
            AnalyticsDsarCollector::class,
            AnalyticsDsarEraser::class,
        ];
    }

    private function registerRepositories(ContainerInterface $container): void
    {
        $container->bind(VisitorSaltStoreInterface::class, static fn() => new DbVisitorSaltStore(
            $container->get(ConnectionInterface::class),
        ));

        $container->bind(SiteRepositoryInterface::class, static fn() => new DbSiteRepository(
            $container->get(ConnectionInterface::class),
        ));

        $container->bind(PageViewRepositoryInterface::class, static fn() => new DbPageViewRepository(
            $container->get(ConnectionInterface::class),
        ));

        $container->bind(SessionRepositoryInterface::class, static fn() => new DbSessionRepository(
            $container->get(ConnectionInterface::class),
        ));

        $container->bind(EventRepositoryInterface::class, static fn() => new DbEventRepository(
            $container->get(ConnectionInterface::class),
        ));

        $container->bind(DbGoalRepository::class, static fn() => new DbGoalRepository(
            $container->get(ConnectionInterface::class),
        ));

        $container->bind(DbGoalConversionRepository::class, static fn() => new DbGoalConversionRepository(
            $container->get(ConnectionInterface::class),
        ));

        $container->bind(DailyStatsRepositoryInterface::class, static fn() => new DbDailyStatsRepository(
            $container->get(ConnectionInterface::class),
        ));

        $container->bind(DbHourlyStatsRepository::class, static fn() => new DbHourlyStatsRepository(
            $container->get(ConnectionInterface::class),
        ));
    }

    private function registerServices(ContainerInterface $container, AnalyticsConfig $config): void
    {
        $container->bind(SessionResolver::class, static fn() => new SessionResolver(
            $container->get(SessionRepositoryInterface::class),
        ));

        $container->bind(SiteServiceInterface::class, static fn() => new SiteService(
            $container->get(SiteRepositoryInterface::class),
        ));

        if (!$container->has(AnalyticsKeyManager::class)) {
            return;
        }

        $container->bind(TrackingServiceInterface::class, static fn() => new TrackingService(
            keyManager: $container->get(AnalyticsKeyManager::class),
            saltStore: $container->get(VisitorSaltStoreInterface::class),
            botDetector: $container->get(BotDetector::class),
            referrerParser: $container->get(ReferrerParser::class),
            userAgentParser: $container->get(UserAgentParser::class),
            sessionResolver: $container->get(SessionResolver::class),
            geoResolver: $container->get(GeoLocationResolverInterface::class),
            pageViewRepository: $container->get(PageViewRepositoryInterface::class),
            eventRepository: $container->get(EventRepositoryInterface::class),
            siteRepository: $container->get(SiteRepositoryInterface::class),
            config: $config,
            goalService: $container->has(GoalServiceInterface::class)
                ? $container->get(GoalServiceInterface::class)
                : null,
            consentManager: $container->has(ConsentManagerInterface::class)
                ? $container->get(ConsentManagerInterface::class)
                : null,
        ));

        $container->bind(AggregationService::class, static fn() => new AggregationService(
            dailyStatsRepository: $container->get(DailyStatsRepositoryInterface::class),
            hourlyStatsRepository: $container->get(DbHourlyStatsRepository::class),
            connection: $container->get(ConnectionInterface::class),
        ));

        $container->bind(StatsServiceInterface::class, static fn() => new StatsService(
            dailyStatsRepository: $container->get(DailyStatsRepositoryInterface::class),
            connection: $container->get(ConnectionInterface::class),
        ));

        $container->bind(GoalServiceInterface::class, static fn() => new GoalService(
            goalRepository: $container->get(DbGoalRepository::class),
            conversionRepository: $container->get(DbGoalConversionRepository::class),
        ));

        // GA4-level services
        $container->bind(FlowServiceInterface::class, static fn() => new FlowService(
            $container->get(ConnectionInterface::class),
        ));

        $container->bind(FunnelServiceInterface::class, static fn() => new FunnelService(
            $container->get(ConnectionInterface::class),
        ));

        $container->bind(EcommerceServiceInterface::class, static fn() => new EcommerceService(
            $container->get(ConnectionInterface::class),
        ));

        $container->bind(CustomEventServiceInterface::class, static fn() => new CustomEventService(
            $container->get(ConnectionInterface::class),
        ));

        $container->bind(SegmentServiceInterface::class, static fn() => new SegmentService(
            $container->get(ConnectionInterface::class),
        ));

        $container->bind(AttributionServiceInterface::class, static fn() => new AttributionService(
            $container->get(ConnectionInterface::class),
        ));

        $container->bind(SearchAnalyticsServiceInterface::class, static fn() => new SearchAnalyticsService(
            $container->get(ConnectionInterface::class),
        ));

        // DSAR (GDPR data subject access and erasure)
        $container->bind(AnalyticsDsarCollector::class, static fn() => new AnalyticsDsarCollector(
            $container->get(PageViewRepositoryInterface::class),
            $container->get(SessionRepositoryInterface::class),
            $container->get(EventRepositoryInterface::class),
        ));

        // Also register as the interface for the DsarRequestHandler collector list
        $container->bind(DsarCollectorInterface::class, static fn() => $container->get(AnalyticsDsarCollector::class));

        if ($container->has(AuditLoggerInterface::class)) {
            $container->bind(AnalyticsDsarEraser::class, static fn() => new AnalyticsDsarEraser(
                $container->get(PageViewRepositoryInterface::class),
                $container->get(SessionRepositoryInterface::class),
                $container->get(EventRepositoryInterface::class),
                $container->get(AuditLoggerInterface::class),
            ));
        }

        // Consent banner
        $container->bind(AnalyticsConsentBanner::class, static fn() => new AnalyticsConsentBanner());
    }

    private function registerMiddleware(ContainerInterface $container, AnalyticsConfig $config): void
    {
        $container->bind(CollectionRateLimitMiddleware::class, static fn() => new CollectionRateLimitMiddleware(
            $config,
            $container->has(CacheDriverInterface::class) ? $container->get(CacheDriverInterface::class) : null,
        ));

        $container->bind(CollectionCorsMiddleware::class, static fn() => new CollectionCorsMiddleware(
            $container->get(SiteRepositoryInterface::class),
        ));

        $container->bind(BotFilterMiddleware::class, static fn() => new BotFilterMiddleware(
            $container->get(BotDetector::class),
        ));

        $container->bind(AnalyticsAuthMiddleware::class, static fn() => new AnalyticsAuthMiddleware(
            $container->has(GateInterface::class) ? $container->get(GateInterface::class) : null,
        ));

        // Consent middleware: only registered when consent is required
        if ($config->privacy->requireConsent
            && $container->has(ConsentManagerInterface::class)
            && $container->has(AnalyticsKeyManager::class)
        ) {
            $container->bind(AnalyticsConsentMiddleware::class, static fn() => new AnalyticsConsentMiddleware(
                $config,
                $container->get(ConsentManagerInterface::class),
                $container->get(AnalyticsKeyManager::class),
                $container->get(AnalyticsConsentBanner::class),
            ));
        }
    }

    private function registerControllers(ContainerInterface $container, AnalyticsConfig $config): void
    {
        $container->bind(CollectionController::class, static fn() => new CollectionController(
            $container->get(TrackingServiceInterface::class),
            $container->get(SiteRepositoryInterface::class),
        ));

        $container->bind(TrackerController::class, static fn() => new TrackerController());

        $container->bind(StatsController::class, static fn() => new StatsController(
            $container->get(StatsServiceInterface::class),
        ));

        $container->bind(TimeseriesController::class, static fn() => new TimeseriesController(
            $container->get(StatsServiceInterface::class),
        ));

        $container->bind(BreakdownController::class, static fn() => new BreakdownController(
            $container->get(StatsServiceInterface::class),
        ));

        $container->bind(RealtimeController::class, static fn() => new RealtimeController(
            $container->get(StatsServiceInterface::class),
        ));

        $container->bind(GoalController::class, static fn() => new GoalController(
            $container->get(GoalServiceInterface::class),
        ));

        $container->bind(SiteController::class, static fn() => new SiteController(
            $container->get(SiteServiceInterface::class),
        ));

        $container->bind(ExportController::class, static fn() => new ExportController(
            $container->get(PageViewRepositoryInterface::class),
        ));

        $container->bind(DashboardController::class, static fn() => new DashboardController($config));

        // GA4-level controllers
        $container->bind(FlowController::class, static fn() => new FlowController(
            $container->get(FlowServiceInterface::class),
        ));

        $container->bind(FunnelController::class, static fn() => new FunnelController(
            $container->get(FunnelServiceInterface::class),
        ));

        $container->bind(EcommerceController::class, static fn() => new EcommerceController(
            $container->get(EcommerceServiceInterface::class),
        ));

        $container->bind(CustomEventController::class, static fn() => new CustomEventController(
            $container->get(CustomEventServiceInterface::class),
        ));

        $container->bind(SegmentController::class, static fn() => new SegmentController(
            $container->get(SegmentServiceInterface::class),
        ));

        $container->bind(AttributionController::class, static fn() => new AttributionController(
            $container->get(AttributionServiceInterface::class),
        ));

        $container->bind(SearchAnalyticsController::class, static fn() => new SearchAnalyticsController(
            $container->get(SearchAnalyticsServiceInterface::class),
        ));

        // Consent controller
        if ($container->has(ConsentManagerInterface::class) && $container->has(AnalyticsKeyManager::class)) {
            $container->bind(ConsentController::class, static fn() => new ConsentController(
                $container->get(ConsentManagerInterface::class),
                $container->get(AnalyticsKeyManager::class),
            ));
        }

        // DSAR controller
        if ($container->has(AnalyticsDsarCollector::class)
            && $container->has(AnalyticsDsarEraser::class)
            && $container->has(AnalyticsKeyManager::class)
            && $container->has(VisitorSaltStoreInterface::class)
        ) {
            $container->bind(DsarController::class, static fn() => new DsarController(
                $container->get(AnalyticsDsarCollector::class),
                $container->get(AnalyticsDsarEraser::class),
                $container->get(AnalyticsKeyManager::class),
                $container->get(VisitorSaltStoreInterface::class),
            ));
        }
    }
}
