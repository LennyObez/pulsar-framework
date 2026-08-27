<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct;

use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\DataAct\Access\DataAccessController;
use Pulsar\Extension\DataAct\Mapping\DataActMapping;
use Pulsar\Routing\RouterInterface;

/**
 * EU Data Act (Regulation 2023/2854) compliance extension.
 *
 * Provides data portability, interoperability declarations, cloud
 * switching assistance, and third-party data access controls as
 * required by the Data Act for data holders, data recipients,
 * and cloud service providers.
 * @api
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
        // Class-string handlers (DataAccessController is bound by
        // DataActServiceProvider and resolved on dispatch) so the routes compile
        // into the strict route cache instead of being skipped as non-serializable.
        $router->get('/data-act/export', [DataAccessController::class, 'requestExport']);
        $router->get('/data-act/export/{requestId}', [DataAccessController::class, 'exportStatus']);

        // {@see DataActMapping} declared six Data Act controls that nothing
        // registered anywhere — no provider, no wiring, no boot hook — so every one
        // of them was unreachable and unfalsifiable. See DsaExtension::boot() for
        // why this is the right moment and why it is conditional.
        if ($container->has(ControlCatalog::class)) {
            /** @var ControlCatalog $catalog */
            $catalog = $container->get(ControlCatalog::class);

            // A deferred source, for the reason DsaExtension::boot() gives: the
            // mapping is autoloaded at the catalog's first read, not at boot.
            $catalog->contribute(static fn(): array => DataActMapping::declarations());
        }
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
