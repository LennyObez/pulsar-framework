<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus;

use Override;
use Pulsar\Api\Api;
use Pulsar\Cache\Application\CacheManagerInterface;
use Pulsar\Config\ConfigManagerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\PostBootExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\HealthStatus\Config\HealthStatusConfig;
use Pulsar\Extension\HealthStatus\Contracts\HealthCheckRunnerInterface;
use Pulsar\Extension\HealthStatus\Contracts\HealthHistoryStoreInterface;
use Pulsar\Extension\HealthStatus\Contracts\IncidentDetectorInterface;
use Pulsar\Extension\HealthStatus\Contracts\IntegrityVerificationRunnerInterface;
use Pulsar\Extension\HealthStatus\Internal\Adapter\CoreHealthCheckRunnerAdapter;
use Pulsar\Extension\HealthStatus\Internal\Adapter\CoreIntegrityVerificationAdapter;
use Pulsar\Extension\HealthStatus\Internal\Detection\ThresholdIncidentDetector;
use Pulsar\Extension\HealthStatus\Internal\Storage\DatabaseHealthHistoryStore;
use Pulsar\Extension\HealthStatus\Scheduler\HealthCheckSnapshotJob;
use Pulsar\Extension\HealthStatus\Scheduler\HistoryCleanupJob;
use Pulsar\Extension\HealthStatus\Server\Controller\IntegrityDashboardController;
use Pulsar\Extension\HealthStatus\Server\Controller\StatusApiController;
use Pulsar\Extension\HealthStatus\Server\Controller\StatusDashboardController;
use Pulsar\Extension\HealthStatus\Server\Middleware\StatusAccessMiddleware;
use Pulsar\Http\Method;
use Pulsar\Integrity\ManifestVerifierInterface;
use Pulsar\Resilience\HealthCheck\HealthCheckRunnerInterface as CoreHealthCheckRunnerInterface;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouterInterface;
use Pulsar\Scheduler\JobRegistryInterface;
use Pulsar\Scheduler\Schedule;

use function is_array;
use function is_file;
use function sprintf;

use const DIRECTORY_SEPARATOR;

// Note: Pulsar\Http\Method, Route, HeaderBag, ResponseStatus removed: real controllers use Pulsar\Http\Message\Response

/**
 * Health status dashboard extension.
 *
 * Provides health check history, incident detection, and an operational
 * dashboard for monitoring system health over time.
 *
 * @psalm-api Loaded by the framework's ExtensionLoader at boot time
 *            via the pulsar.json manifest, never instantiated by name.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HealthStatusExtension implements ExtensionInterface, PostBootExtensionInterface
{
    #[Override]
    public function name(): string
    {
        return 'pulsar/health-status';
    }

    #[Override]
    public function register(ContainerInterface $container): void
    {
        $this->loadConfig($container);
        $config = $container->get(HealthStatusConfig::class);

        $container->bind(
            HealthHistoryStoreInterface::class,
            static fn(ContainerInterface $c): DatabaseHealthHistoryStore => new DatabaseHealthHistoryStore(
                $c->get(\Pulsar\Database\ConnectionInterface::class),
            ),
        );

        $container->bind(
            IncidentDetectorInterface::class,
            static fn(): ThresholdIncidentDetector => new ThresholdIncidentDetector(
                threshold: $config->incidentThresholdConsecutiveFailures,
            ),
        );

        // Site-wide footer/header status pill: worst recorded status over a
        // window, cached and fail-open to a neutral "unknown". Uses the PSR-16
        // cache when one is bound so a footer on every page does not re-query.
        $container->bind(
            StatusPillProvider::class,
            static function (ContainerInterface $c): StatusPillProvider {
                $cache = null;

                if ($c->has(CacheManagerInterface::class)) {
                    /** @var CacheManagerInterface $cacheManager */
                    $cacheManager = $c->get(CacheManagerInterface::class);
                    $cache = $cacheManager->simple();
                }

                /** @var HealthHistoryStoreInterface $store */
                $store = $c->get(HealthHistoryStoreInterface::class);

                return new StatusPillProvider($store, $cache);
            },
        );

        // Bind HealthCheckRunnerInterface to adapter wrapping core runner
        if ($container->has(CoreHealthCheckRunnerInterface::class)) {
            $container->bind(
                HealthCheckRunnerInterface::class,
                static fn(ContainerInterface $c): CoreHealthCheckRunnerAdapter => new CoreHealthCheckRunnerAdapter(
                    $c->get(CoreHealthCheckRunnerInterface::class),
                ),
            );
        }

        // Bind IntegrityVerificationRunnerInterface to adapter wrapping core verifier
        if ($container->has(ManifestVerifierInterface::class)) {
            $container->bind(
                IntegrityVerificationRunnerInterface::class,
                static fn(ContainerInterface $c): CoreIntegrityVerificationAdapter => new CoreIntegrityVerificationAdapter(
                    $c->get(ManifestVerifierInterface::class),
                ),
            );
        }
    }

    #[Override]
    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        $config = $container->get(HealthStatusConfig::class);

        if (!$config->enabled) {
            return;
        }

        $this->registerRoutes($router, $config);
    }

    #[Override]
    public function postBoot(ContainerInterface $container): void
    {
        $config = $container->get(HealthStatusConfig::class);

        if (!$config->enabled) {
            return;
        }

        if ($container->has(JobRegistryInterface::class)) {
            $this->registerSchedulerJobs($container, $config);
        }
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    #[Override]
    public function providers(): array
    {
        return [];
    }

    private function loadConfig(ContainerInterface $container): void
    {
        if ($container->has(HealthStatusConfig::class)) {
            return;
        }

        if ($container->has(ConfigManagerInterface::class)) {
            $configManager = $container->get(ConfigManagerInterface::class);
            $configPath = $configManager->configPath();

            if ($configPath !== null && is_file($configPath . DIRECTORY_SEPARATOR . 'health-status.php')) {
                /**
                 * @psalm-suppress UnresolvableInclude
                 * @var mixed $data
                 */
                $data = require $configPath . DIRECTORY_SEPARATOR . 'health-status.php';

                if (is_array($data)) {
                    /** @var array<string, mixed> $data */
                    $container->instance(HealthStatusConfig::class, HealthStatusConfig::fromArray($data));

                    return;
                }
            }
        }

        $container->instance(HealthStatusConfig::class, new HealthStatusConfig());
    }

    /**
     * Register status dashboard routes with real controllers.
     *
     * All routes are protected by the StatusAccessMiddleware for
     * authentication, rate limiting, and security headers.
     */
    private function registerRoutes(RouterInterface $router, HealthStatusConfig $config): void
    {
        $prefix = $config->routePrefix;
        $middleware = [StatusAccessMiddleware::class];

        $router->add(new Route(
            methods: [Method::GET],
            path: $prefix,
            handler: [StatusDashboardController::class, '__invoke'],
            name: 'health-status.dashboard',
            middleware: $middleware,
        ));
        $router->add(new Route(
            methods: [Method::GET],
            path: "{$prefix}/api/current",
            handler: [StatusApiController::class, 'current'],
            name: 'health-status.api.current',
            middleware: $middleware,
        ));
        $router->add(new Route(
            methods: [Method::GET],
            path: "{$prefix}/api/history",
            handler: [StatusApiController::class, 'history'],
            name: 'health-status.api.history',
            middleware: $middleware,
        ));
        $router->add(new Route(
            methods: [Method::GET],
            path: "{$prefix}/api/incidents",
            handler: [StatusApiController::class, 'incidents'],
            name: 'health-status.api.incidents',
            middleware: $middleware,
        ));
        $router->add(new Route(
            methods: [Method::GET],
            path: "{$prefix}/integrity",
            handler: [IntegrityDashboardController::class, '__invoke'],
            name: 'health-status.integrity',
            middleware: $middleware,
        ));
        $router->add(new Route(
            methods: [Method::GET],
            path: "{$prefix}/api/integrity",
            handler: [IntegrityDashboardController::class, 'api'],
            name: 'health-status.api.integrity',
            middleware: $middleware,
        ));
    }

    private function registerSchedulerJobs(ContainerInterface $container, HealthStatusConfig $config): void
    {
        $registry = $container->get(JobRegistryInterface::class);

        $registry->register(
            HealthCheckSnapshotJob::class,
            Schedule::everyMinute(),
        );

        $registry->register(
            HistoryCleanupJob::class,
            Schedule::cron(sprintf('0 */%d * * *', $config->retention->cleanupIntervalHours)),
        );
    }
}
