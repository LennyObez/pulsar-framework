<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\DiskConfig;
use Pulsar\Config\StorageConfig;
use Pulsar\Config\StorageDriver;
use Pulsar\Container\ContainerInterface;
use Pulsar\Filesystem\WritablePathGuard;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Storage\Storage;
use Pulsar\Storage\StorageAdapterInterface;
use Pulsar\Storage\StorageManager;

use function resolve_path;

#[Internal]
final readonly class StorageWiring implements ServiceWiringInterface
{
    /** The one `visibility` value that waives the document-root check. */
    private const string PUBLIC_VISIBILITY = 'public';

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
        $storageConfig = $this->withGuardedDiskRoots($storageConfig);
        $container->instance(StorageConfig::class, $storageConfig);

        $manager = new StorageManager($storageConfig);
        $container->instance(StorageManager::class, $manager);

        // Bind static facade for convenience access
        Storage::bind($manager);

        // Register default disk adapter as the interface binding
        if ($storageConfig->disks !== []) {
            $container->bind(StorageAdapterInterface::class, static fn(): StorageAdapterInterface => $manager->disk());
        }
    }

    /**
     * Resolve every local disk root to an absolute path, refusing one that lands
     * inside the document root.
     *
     * A disk root is where uploaded and generated files are written. Left
     * unresolved, `storage/app` is judged by nobody and lands wherever the
     * process CWD happens to point — under PHP-FPM, inside the webroot, where
     * anything written becomes a URL. Boot is the only moment this is cheap to
     * refuse, so it is refused here rather than at the first write.
     *
     * Only local disks carry a filesystem root; S3 and memory disks are returned
     * untouched.
     */
    private function withGuardedDiskRoots(StorageConfig $config): StorageConfig
    {
        $disks = [];

        foreach ($config->disks as $name => $disk) {
            $disks[$name] = $disk->driver === StorageDriver::Local
                ? $this->withResolvedRoot($disk)
                : $disk;
        }

        return new StorageConfig($config->default, $disks, $config->unknownKeys);
    }

    /**
     * The resolved root replaces the configured one, because LocalStorageAdapter
     * takes it verbatim: handing the adapter the raw relative string would let it
     * write somewhere other than the location just judged safe.
     *
     * `visibility: public` is the operator declaring a disk is meant to be served
     * — CMS media, generated assets — and is the only way to waive the check.
     * Only the exact string opts out, so a misspelling leaves the disk guarded.
     */
    private function withResolvedRoot(DiskConfig $disk): DiskConfig
    {
        $root = $disk->visibility === self::PUBLIC_VISIBILITY
            ? resolve_path($disk->root)
            : WritablePathGuard::resolveState($disk->root, 'storage.disks.' . $disk->name . '.root');

        return new DiskConfig(
            name: $disk->name,
            driver: $disk->driver,
            root: $root,
            visibility: $disk->visibility,
            region: $disk->region,
            bucket: $disk->bucket,
            prefix: $disk->prefix,
            endpoint: $disk->endpoint,
            usePathStyle: $disk->usePathStyle,
            unknownKeys: $disk->unknownKeys,
        );
    }
}
