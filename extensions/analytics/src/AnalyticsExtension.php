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
use Pulsar\Extension\Analytics\Internal\Scheduler\AggregationJob;
use Pulsar\Extension\Analytics\Internal\Scheduler\PartitionMaintenanceJob;
use Pulsar\Extension\Analytics\Internal\Scheduler\RetentionCleanupJob;
use Pulsar\Extension\Analytics\Internal\Scheduler\VisitorSaltPurgeJob;
use Pulsar\ImportExport\ImportExportRegistry;
use Pulsar\Routing\RouterInterface;
use Pulsar\Scheduler\JobRegistryInterface;
use Pulsar\Scheduler\Schedule;

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
 * @api
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

        new AnalyticsRouteRegistrar()->register($router, $config);
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

        // Destroy expired visitor salts after the retention window. Runs after
        // the midnight session-grace window (and the retention cleanup) so the
        // day/day-1 salts it must keep are never in flight when it fires.
        $registry->register(
            VisitorSaltPurgeJob::class,
            Schedule::dailyAt('03:00'),
        );

        $registry->register(
            PartitionMaintenanceJob::class,
            Schedule::weekly(),
        );
    }
}
