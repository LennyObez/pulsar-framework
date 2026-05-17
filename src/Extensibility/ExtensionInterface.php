<?php

declare(strict_types=1);

namespace Pulsar\Extensibility;

use Pulsar\Api\Api;
use Pulsar\Container\ContainerInterface;
use Pulsar\Routing\RouterInterface;

/**
 * Contract for Pulsar extensions.
 *
 * Extensions provide a way to modularize application functionality.
 * Each extension can:
 * - Register services in the DI container
 * - Register routes in the router
 * - Provide service providers for deferred loading
 * @api
 */
#[Api(since: '1.0.0')]
interface ExtensionInterface
{
    /**
     * Get the unique name of this extension.
     *
     * Should match the name in pulsar.json manifest.
     */
    public function name(): string;

    /**
     * Register services with the container.
     *
     * Called during the registration phase before any boot() methods.
     * Use this to bind services, interfaces, and factories to the container.
     */
    public function register(ContainerInterface $container): void;

    /**
     * Boot the extension.
     *
     * Called after all extensions have been registered.
     * Use this to register routes, configure services, and perform
     * any initialization that depends on other services being available.
     */
    public function boot(ContainerInterface $container, RouterInterface $router): void;

    /**
     * Get the service providers for this extension.
     *
     * Service providers allow for organized, reusable service registration.
     *
     * @return list<class-string<ServiceProviderInterface>>
     */
    public function providers(): array;
}
