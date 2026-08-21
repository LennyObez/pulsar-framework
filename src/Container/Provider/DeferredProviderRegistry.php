<?php

declare(strict_types=1);

namespace Pulsar\Container\Provider;

use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Container\Exception\ContainerException;

use function array_key_exists;
use function spl_object_id;
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

    /**
     * Providers already registered, keyed by object identity rather than class
     * name.
     *
     * Class-name keying assumed one instance per provider class, which stopped
     * being true once extension providers started arriving wrapped in a
     * per-extension scope ({@see \Pulsar\Extensibility\Internal\ScopedDeferredProvider}):
     * every wrapped provider shares one class, so the first one to resolve
     * marked the class registered and every other extension's deferred provider
     * was silently skipped — its services never bound, with no error anywhere.
     * Identity is what "each provider registers once" actually meant.
     *
     * `spl_object_id` is safe as a key here despite being reused after an object
     * is collected: every id recorded belongs to a provider held in
     * {@see self::$providers}, which is never unset, so no provider in this map
     * can be collected while its id is still in it.
     *
     * @var array<int, true>
     */
    private array $registered = [];

    /**
     * Register a deferred provider, mapping all its provided service IDs.
     *
     * @throws ContainerException If a service ID is already claimed by another provider
     */
    public function register(DeferredServiceProviderInterface $provider): void
    {
        foreach ($provider->provides() as $serviceId) {
            $claimant = $this->providers[$serviceId] ?? null;

            // Object identity, not class name: two DISTINCT providers claiming
            // one service id is the collision this guard exists to catch, and
            // they are increasingly likely to share a class — every extension's
            // scoped deferred provider does. Re-registering the same instance
            // stays idempotent, which is the only case the old class comparison
            // was really allowing.
            if ($claimant !== null && $claimant !== $provider) {
                throw new ContainerException(sprintf(
                    'Deferred service ID "%s" is already claimed by provider "%s". '
                    . 'Cannot register duplicate claim from provider "%s".',
                    $serviceId,
                    $claimant::class,
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
        $providerId = spl_object_id($provider);

        // Only register each provider once
        if (array_key_exists($providerId, $this->registered)) {
            return;
        }

        $this->registered[$providerId] = true;
        $provider->register($container);
    }
}
