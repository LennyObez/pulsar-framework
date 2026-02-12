<?php

declare(strict_types=1);

namespace Pulsar\Extension\Devices;

use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Devices\Http\Controller\DeviceController;
use Pulsar\Extension\Devices\Http\Middleware\DeviceTokenGuard;
use Pulsar\Extension\Devices\Internal\DeviceService;
use Pulsar\Extension\Devices\Internal\Persistence\DbUserDeviceRepository;

/**
 * Wires device management services: repository, service, controller, and middleware.
 */
#[Internal(reason: 'Devices service wiring — use interfaces for public API')]
final class DevicesServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        if (!$container->has(ConnectionInterface::class)) {
            return;
        }

        /** @var ConnectionInterface $connection */
        $connection = $container->get(ConnectionInterface::class);

        // Repository
        $repository = new DbUserDeviceRepository($connection);
        $container->instance(UserDeviceRepositoryInterface::class, $repository);

        // Service
        $deviceService = new DeviceService($repository);
        $container->instance(DeviceService::class, $deviceService);

        // Controller
        $container->instance(
            DeviceController::class,
            new DeviceController($deviceService),
        );

        // Middleware
        $container->instance(
            DeviceTokenGuard::class,
            new DeviceTokenGuard($deviceService),
        );
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [
            UserDeviceRepositoryInterface::class,
            DeviceService::class,
            DeviceController::class,
            DeviceTokenGuard::class,
        ];
    }
}
