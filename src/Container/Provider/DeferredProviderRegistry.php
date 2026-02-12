<?php

declare(strict_types=1);

namespace Pulsar\Container\Provider;

use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Container\Exception\ContainerException;

use function array_key_exists;
use function sprintf;

/**
 * Registry of deferred service providers.
 *
 * Maps service IDs to their deferred providers. When a service ID is first
 * requested, the corresponding provider's `register()` is triggered.
 */
#[Internal]
final class DeferredProviderRegistry
{
    /** @var array<string, DeferredServiceProviderInterface> Service ID → provider */
    private array $providers = [];

    /** @var array<string, bool> Tracks which providers have been registered */
    private array $registered = [];

    /**
     * Register a deferred provider, mapping all its provided service IDs.
     *
     * @throws ContainerException If a service ID is already claimed by another provider
     */
    public function register(DeferredServiceProviderInterface $provider): void
    {
        foreach ($provider->provides() as $serviceId) {
            if (isset($this->providers[$serviceId]) && $this->providers[$serviceId]::class !== $provider::class) {
                throw new ContainerException(sprintf(
                    'Deferred service ID "%s" is already claimed by provider "%s". '
                    . 'Cannot register duplicate claim from provider "%s".',
                    $serviceId,
                    $this->providers[$serviceId]::class,
                    $provider::class,
                ));
            }

            $this->providers[$serviceId] = $provider;
        }
    }

    /**
     * Check if a deferred provider exists for the given service ID.
     */
    public function has(string $serviceId): bool
    {
        return isset($this->providers[$serviceId]);
    }

    /**
     * Trigger the deferred provider's registration for the given service ID.
     *
     * This calls `register()` on the provider, which binds its services
     * into the container. The provider is only registered once even if
     * it provides multiple service IDs.
     */
    public function resolve(string $serviceId, ContainerInterface $container): void
    {
        if (!isset($this->providers[$serviceId])) {
            return;
        }

        $provider = $this->providers[$serviceId];
        $providerClass = $provider::class;

        // Only register each provider once
        if (array_key_exists($providerClass, $this->registered)) {
            return;
        }

        $this->registered[$providerClass] = true;
        $provider->register($container);
    }
}
