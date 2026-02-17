<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora;

use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Dora\Internal\InMemoryIctAssetRegistry;
use Pulsar\Extension\Dora\Risk\IctAssetRegistryInterface;

/**
 * Wires DORA services: ICT asset registry.
 *
 * Production deployments should override IctAssetRegistryInterface
 * with a persistent implementation.
 */
#[Internal(reason: 'DORA service wiring; use interfaces for public API')]
final class DoraServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->instance(IctAssetRegistryInterface::class, new InMemoryIctAssetRegistry());
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [
            IctAssetRegistryInterface::class,
        ];
    }
}
