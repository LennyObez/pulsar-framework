<?php

declare(strict_types=1);

namespace Pulsar\Extension\Releases;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Releases\Http\Controller\Admin\ReleaseController as AdminReleaseController;
use Pulsar\Extension\Releases\Http\Controller\Api\BetaSignupController;
use Pulsar\Extension\Releases\Http\Controller\Api\ReleaseApiController;
use Pulsar\Extension\Releases\Internal\Persistence\DbBetaSignupRepository;
use Pulsar\Extension\Releases\Internal\Persistence\DbReleaseRepository;
use Pulsar\Extension\Releases\Internal\ReleaseService;

/**
 * Binds release repositories, service, and HTTP controllers to the container.
 */
#[Internal(reason: 'Release service wiring — use ReleaseRepositoryInterface for public API')]
final class ReleasesServiceProvider implements ServiceProviderInterface
{
    #[Override]
    public function register(ContainerInterface $container): void
    {
        if (!$container->has(ConnectionInterface::class)) {
            return;
        }

        /** @var ConnectionInterface $connection */
        $connection = $container->get(ConnectionInterface::class);

        // Repositories
        $releaseRepository = new DbReleaseRepository($connection);
        $container->instance(ReleaseRepositoryInterface::class, $releaseRepository);

        $betaSignupRepository = new DbBetaSignupRepository($connection);
        $container->instance(BetaSignupRepositoryInterface::class, $betaSignupRepository);

        // Service
        $service = new ReleaseService($releaseRepository, $betaSignupRepository);
        $container->instance(ReleaseService::class, $service);

        // API controllers
        $container->instance(
            ReleaseApiController::class,
            new ReleaseApiController($service, $releaseRepository),
        );

        $container->instance(
            BetaSignupController::class,
            new BetaSignupController($service),
        );

        // Admin controller
        $container->instance(
            AdminReleaseController::class,
            new AdminReleaseController($service, $releaseRepository, $betaSignupRepository),
        );
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function provides(): array
    {
        return [
            ReleaseRepositoryInterface::class,
            BetaSignupRepositoryInterface::class,
            ReleaseService::class,
            ReleaseApiController::class,
            BetaSignupController::class,
            AdminReleaseController::class,
        ];
    }
}
