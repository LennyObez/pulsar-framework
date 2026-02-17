<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct;

use Override;
use Pulsar\Api\Api;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\DataAct\Access\DataAccessController;
use Pulsar\Routing\RouterInterface;

/**
 * EU Data Act (Regulation 2023/2854) compliance extension.
 *
 * Provides data portability, interoperability declarations, cloud
 * switching assistance, and third-party data access controls as
 * required by the Data Act for data holders, data recipients,
 * and cloud service providers.
 */
#[Api(since: '1.0.0')]
final readonly class DataActExtension implements ExtensionInterface
{
    #[Override]
    public function name(): string
    {
        return 'pulsar/data-act';
    }

    #[Override]
    public function register(ContainerInterface $container): void
    {
        // All bindings handled by DataActServiceProvider
    }

    #[Override]
    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        /** @var DataAccessController $controller */
        $controller = $container->get(DataAccessController::class);

        $router->get('/data-act/export', [$controller, 'requestExport']);
        $router->get('/data-act/export/{requestId}', [$controller, 'exportStatus']);
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    #[Override]
    public function providers(): array
    {
        return [
            DataActServiceProvider::class,
        ];
    }
}
