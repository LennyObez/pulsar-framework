<?php

declare(strict_types=1);

namespace Pulsar\Extensibility;

use Pulsar\Api\Api;
use Pulsar\Container\ContainerInterface;

/**
 * Contract for service providers.
 *
 * Service providers encapsulate service registration logic and can be
 * shared across multiple extensions. They allow for organized, modular
 * service configuration.
 */
#[Api(since: '1.0.0')]
interface ServiceProviderInterface
{
    /**
     * Register services with the container.
     *
     * This method is called during the registration phase.
     * Use it to bind services, interfaces, and factories.
     */
    public function register(ContainerInterface $container): void;

    /**
     * Get the list of service IDs this provider registers.
     *
     * This is used for deferred loading and debugging purposes.
     * Return an empty array if you don't want to advertise services.
     *
     * @return list<string>
     */
    public function provides(): array;
}
