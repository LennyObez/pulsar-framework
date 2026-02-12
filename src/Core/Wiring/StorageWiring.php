<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\StorageConfig;
use Pulsar\Container\BindingType;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Storage\StorageAdapterInterface;
use Pulsar\Storage\StorageManager;

#[Internal]
final readonly class StorageWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();

        if (!$repository->has(StorageConfig::class)) {
            return;
        }

        /** @var StorageConfig $storageConfig */
        $storageConfig = $repository->get(StorageConfig::class);
        $container->instance(StorageConfig::class, $storageConfig);

        $manager = new StorageManager($storageConfig);
        $container->instance(StorageManager::class, $manager);

        // Register default disk adapter as the interface binding
        if ($storageConfig->disks !== []) {
            $container->bind(StorageAdapterInterface::class, static fn(): StorageAdapterInterface => $manager->disk(), BindingType::Singleton);
        }
    }
}
