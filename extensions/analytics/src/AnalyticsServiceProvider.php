<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Cache\Application\CacheDriverInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Analytics\Config\AnalyticsConfig;
use Pulsar\Extension\Analytics\Contracts\DailyStatsRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\EventRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\GoalServiceInterface;
use Pulsar\Extension\Analytics\Contracts\PageViewRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\SessionRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\SiteRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\SiteServiceInterface;
use Pulsar\Extension\Analytics\Contracts\StatsServiceInterface;
use Pulsar\Extension\Analytics\Contracts\TrackingServiceInterface;
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
use Pulsar\Extension\Analytics\Internal\Service\AggregationService;
use Pulsar\Extension\Analytics\Internal\Service\GoalService;
use Pulsar\Extension\Analytics\Internal\Service\ReferrerParser;
use Pulsar\Extension\Analytics\Internal\Service\SessionResolver;
use Pulsar\Extension\Analytics\Internal\Service\SiteService;
use Pulsar\Extension\Analytics\Internal\Service\StatsService;
use Pulsar\Extension\Analytics\Internal\Service\TrackingService;
use Pulsar\Extension\Analytics\Internal\Service\UserAgentParser;
use Pulsar\Extension\Analytics\Server\Controller\BreakdownController;
use Pulsar\Extension\Analytics\Server\Controller\CollectionController;
use Pulsar\Extension\Analytics\Server\Controller\DashboardController;
use Pulsar\Extension\Analytics\Server\Controller\ExportController;
use Pulsar\Extension\Analytics\Server\Controller\GoalController;
use Pulsar\Extension\Analytics\Server\Controller\RealtimeController;
use Pulsar\Extension\Analytics\Server\Controller\SiteController;
use Pulsar\Extension\Analytics\Server\Controller\StatsController;
use Pulsar\Extension\Analytics\Server\Controller\TimeseriesController;
use Pulsar\Extension\Analytics\Server\Controller\TrackerController;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\ZeroTrust\Signal\GeoLocationResolverInterface;

/**
 * Wires all analytics services, repositories, controllers, and middleware.
 */
#[Internal(reason: 'Analytics service wiring — use interfaces for public API')]
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
            $container->singleton(AnalyticsKeyManager::class, static fn() => new AnalyticsKeyManager(
                $container->get(MasterKey::class),
            ));
        }

        // Pure services (no DB dependency)
        $container->singleton(BotDetector::class, static fn() => new BotDetector($config));
        $container->singleton(ReferrerParser::class, static fn() => new ReferrerParser());
        $container->singleton(UserAgentParser::class, static fn() => new UserAgentParser());

        // Geo resolver
        if (!$container->has(GeoLocationResolverInterface::class)) {
            $container->singleton(GeoLocationResolverInterface::class, static fn() => new DbIpLiteResolver($config));
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
        ];
    }

    private function registerRepositories(ContainerInterface $container): void
    {
        $container->singleton(SiteRepositoryInterface::class, static fn() => new DbSiteRepository(
            $container->get(ConnectionInterface::class),
        ));

        $container->singleton(PageViewRepositoryInterface::class, static fn() => new DbPageViewRepository(
            $container->get(ConnectionInterface::class),
        ));

        $container->singleton(SessionRepositoryInterface::class, static fn() => new DbSessionRepository(
            $container->get(ConnectionInterface::class),
        ));

        $container->singleton(EventRepositoryInterface::class, static fn() => new DbEventRepository(
            $container->get(ConnectionInterface::class),
        ));

        $container->singleton(DbGoalRepository::class, static fn() => new DbGoalRepository(
            $container->get(ConnectionInterface::class),
        ));

        $container->singleton(DbGoalConversionRepository::class, static fn() => new DbGoalConversionRepository(
            $container->get(ConnectionInterface::class),
        ));

        $container->singleton(DailyStatsRepositoryInterface::class, static fn() => new DbDailyStatsRepository(
            $container->get(ConnectionInterface::class),
        ));

        $container->singleton(DbHourlyStatsRepository::class, static fn() => new DbHourlyStatsRepository(
            $container->get(ConnectionInterface::class),
        ));
    }

    private function registerServices(ContainerInterface $container, AnalyticsConfig $config): void
    {
        $container->singleton(SessionResolver::class, static fn() => new SessionResolver(
            $config,
            $container->get(SessionRepositoryInterface::class),
        ));

        $container->singleton(SiteServiceInterface::class, static fn() => new SiteService(
            $container->get(SiteRepositoryInterface::class),
        ));

        $container->singleton(TrackingServiceInterface::class, static fn() => new TrackingService(
            keyManager: $container->get(AnalyticsKeyManager::class),
            botDetector: $container->get(BotDetector::class),
            referrerParser: $container->get(ReferrerParser::class),
            userAgentParser: $container->get(UserAgentParser::class),
            sessionResolver: $container->get(SessionResolver::class),
            geoResolver: $container->get(GeoLocationResolverInterface::class),
            pageViewRepository: $container->get(PageViewRepositoryInterface::class),
            eventRepository: $container->get(EventRepositoryInterface::class),
            siteRepository: $container->get(SiteRepositoryInterface::class),
            config: $config,
        ));

        $container->singleton(AggregationService::class, static fn() => new AggregationService(
            pageViewRepository: $container->get(PageViewRepositoryInterface::class),
            sessionRepository: $container->get(SessionRepositoryInterface::class),
            eventRepository: $container->get(EventRepositoryInterface::class),
            dailyStatsRepository: $container->get(DailyStatsRepositoryInterface::class),
            hourlyStatsRepository: $container->get(DbHourlyStatsRepository::class),
            connection: $container->get(ConnectionInterface::class),
        ));

        $container->singleton(StatsServiceInterface::class, static fn() => new StatsService(
            dailyStatsRepository: $container->get(DailyStatsRepositoryInterface::class),
            hourlyStatsRepository: $container->get(DbHourlyStatsRepository::class),
            pageViewRepository: $container->get(PageViewRepositoryInterface::class),
            connection: $container->get(ConnectionInterface::class),
        ));

        $container->singleton(GoalServiceInterface::class, static fn() => new GoalService(
            goalRepository: $container->get(DbGoalRepository::class),
            conversionRepository: $container->get(DbGoalConversionRepository::class),
        ));
    }

    private function registerMiddleware(ContainerInterface $container, AnalyticsConfig $config): void
    {
        $container->singleton(CollectionRateLimitMiddleware::class, static fn() => new CollectionRateLimitMiddleware(
            $config,
            $container->has(CacheDriverInterface::class) ? $container->get(CacheDriverInterface::class) : null,
        ));

        $container->singleton(CollectionCorsMiddleware::class, static fn() => new CollectionCorsMiddleware(
            $config,
            $container->get(SiteRepositoryInterface::class),
        ));

        $container->singleton(BotFilterMiddleware::class, static fn() => new BotFilterMiddleware(
            $container->get(BotDetector::class),
        ));

        $container->singleton(AnalyticsAuthMiddleware::class, static fn() => new AnalyticsAuthMiddleware(
            $config,
            $container->has(GateInterface::class) ? $container->get(GateInterface::class) : null,
        ));
    }

    private function registerControllers(ContainerInterface $container, AnalyticsConfig $config): void
    {
        $container->singleton(CollectionController::class, static fn() => new CollectionController(
            $container->get(TrackingServiceInterface::class),
            $container->get(SiteRepositoryInterface::class),
            $config,
        ));

        $container->singleton(TrackerController::class, static fn() => new TrackerController($config));

        $container->singleton(StatsController::class, static fn() => new StatsController(
            $container->get(StatsServiceInterface::class),
        ));

        $container->singleton(TimeseriesController::class, static fn() => new TimeseriesController(
            $container->get(StatsServiceInterface::class),
        ));

        $container->singleton(BreakdownController::class, static fn() => new BreakdownController(
            $container->get(StatsServiceInterface::class),
        ));

        $container->singleton(RealtimeController::class, static fn() => new RealtimeController(
            $container->get(StatsServiceInterface::class),
        ));

        $container->singleton(GoalController::class, static fn() => new GoalController(
            $container->get(GoalServiceInterface::class),
        ));

        $container->singleton(SiteController::class, static fn() => new SiteController(
            $container->get(SiteServiceInterface::class),
        ));

        $container->singleton(ExportController::class, static fn() => new ExportController(
            $container->get(StatsServiceInterface::class),
            $container->get(PageViewRepositoryInterface::class),
        ));

        $container->singleton(DashboardController::class, static fn() => new DashboardController($config));
    }
}
