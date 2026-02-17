<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices;

use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\MedicalDevices\Internal\InMemoryUdiRegistry;
use Pulsar\Extension\MedicalDevices\Udi\UdiRegistryInterface;
use Pulsar\Extension\MedicalDevices\Udi\UdiValidator;

/**
 * Wires medical device services: UDI registry, validator.
 *
 * Production deployments should override UdiRegistryInterface binding
 * with a persistent implementation backed by EUDAMED or a database.
 */
#[Internal(reason: 'Medical device service wiring; use interfaces for public API')]
final class MedicalDevicesServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        // UDI validator
        $container->instance(UdiValidator::class, new UdiValidator());

        // UDI registry (default: in-memory; override for production persistence)
        $container->instance(UdiRegistryInterface::class, new InMemoryUdiRegistry());
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [
            UdiValidator::class,
            UdiRegistryInterface::class,
        ];
    }
}
