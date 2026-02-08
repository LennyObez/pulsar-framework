<?php

declare(strict_types=1);

namespace Pulsar\Extension\Example;

use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Example\Controller\ExampleController;
use Pulsar\Routing\RouterInterface;
use Pulsar\Routing\RoutingException;

/**
 * Example extension demonstrating the extension system.
 *
 * This extension:
 * - Registers a service provider
 * - Binds the ExampleService
 * - Registers routes
 */
final class ExampleExtension implements ExtensionInterface
{
    public function name(): string
    {
        return 'pulsar/example';
    }

    public function register(ContainerInterface $container): void
    {
        // Additional services can be registered here
        // The service provider handles ExampleService
    }

    /**
     * @throws RoutingException If the router is locked in strict cache mode
     */
    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        // Register extension routes
        $router->get('/example', [ExampleController::class, 'index'], 'example.index');
        $router->get('/example/info', [ExampleController::class, 'info'], 'example.info');
        $router->get('/example/{name}', [ExampleController::class, 'greet'], 'example.greet');
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    public function providers(): array
    {
        return [
            ExampleServiceProvider::class,
        ];
    }
}
