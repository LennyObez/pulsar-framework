<?php

declare(strict_types=1);

namespace Pulsar\Extension\Devices;

use Override;
use Pulsar\Api\Api;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Devices\Http\Controller\DeviceController;
use Pulsar\Routing\RouterInterface;

/**
 * Device management extension for API token authentication.
 *
 * Provides user device registration, token-based authentication,
 * token rotation, and device lifecycle management via a REST API.
 *
 * @psalm-api Loaded by the framework's ExtensionLoader at boot time
 *            via the pulsar.json manifest, never instantiated by name.
 */
#[Api(since: '1.0.0')]
final readonly class DevicesExtension implements ExtensionInterface
{
    #[Override]
    public function name(): string
    {
        return 'pulsar/devices';
    }

    #[Override]
    public function register(ContainerInterface $container): void
    {
        // All bindings are handled by DevicesServiceProvider
    }

    #[Override]
    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        $this->registerApiRoutes($router);
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    #[Override]
    public function providers(): array
    {
        return [
            DevicesServiceProvider::class,
        ];
    }

    private function registerApiRoutes(RouterInterface $router): void
    {
        $prefix = '/api/v1/devices';

        $router->get($prefix, [DeviceController::class, 'index'], 'devices.api.index');
        $router->post($prefix, [DeviceController::class, 'register'], 'devices.api.register');
        $router->post("$prefix/{id}/rotate", [DeviceController::class, 'rotate'], 'devices.api.rotate');
        $router->delete("$prefix/{id}", [DeviceController::class, 'delete'], 'devices.api.delete');
    }
}
