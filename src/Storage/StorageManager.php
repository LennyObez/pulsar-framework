<?php

declare(strict_types=1);

namespace Pulsar\Storage;

use Pulsar\Api\Api;
use Pulsar\Config\DiskConfig;
use Pulsar\Config\StorageConfig;
use Pulsar\Config\StorageDriver;

/**
 * Storage manager that provides disk-based storage adapter resolution.
 */
#[Api(since: '1.0.0')]
final class StorageManager
{
    /** @var array<string, StorageAdapterInterface> */
    private array $adapters = [];

    public function __construct(
        private readonly StorageConfig $config,
    ) {}

    /**
     * Get a storage adapter for the given disk name.
     *
     * Uses the default disk if no name is provided. Adapters are
     * lazily instantiated and cached for the lifetime of the manager.
     *
     * @throws StorageException If the disk is not configured
     */
    public function disk(?string $name = null): StorageAdapterInterface
    {
        $name ??= $this->config->default;

        if (isset($this->adapters[$name])) {
            return $this->adapters[$name];
        }

        if (!isset($this->config->disks[$name])) {
            throw StorageException::diskNotFound($name);
        }

        $diskConfig = $this->config->disks[$name];
        $adapter = $this->createAdapter($diskConfig);
        $this->adapters[$name] = $adapter;

        return $adapter;
    }

    private function createAdapter(DiskConfig $config): StorageAdapterInterface
    {
        return match ($config->driver) {
            StorageDriver::Local => new LocalStorageAdapter($config->root),
            StorageDriver::S3 => new S3StorageAdapter(
                region: $config->region,
                bucket: $config->bucket,
                prefix: $config->prefix,
                endpoint: $config->endpoint,
                usePathStyle: $config->usePathStyle,
            ),
            StorageDriver::Memory => new InMemoryStorageAdapter(),
        };
    }
}
