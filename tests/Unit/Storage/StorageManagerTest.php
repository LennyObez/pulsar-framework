<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\DiskConfig;
use Pulsar\Config\StorageConfig;
use Pulsar\Config\StorageDriver;
use Pulsar\Storage\InMemoryStorageAdapter;
use Pulsar\Storage\StorageException;
use Pulsar\Storage\StorageManager;

#[CoversClass(StorageManager::class)]
final class StorageManagerTest extends TestCase
{
    #[Test]
    public function diskReturnsAdapterForConfiguredDisk(): void
    {
        $config = new StorageConfig(
            default: 'memory',
            disks: [
                'memory' => new DiskConfig(
                    name: 'memory',
                    driver: StorageDriver::Memory,
                ),
            ],
        );

        $manager = new StorageManager($config);
        $adapter = $manager->disk('memory');

        self::assertInstanceOf(InMemoryStorageAdapter::class, $adapter);
    }

    #[Test]
    public function diskThrowsForUnknownDiskName(): void
    {
        $config = new StorageConfig(
            default: 'memory',
            disks: [
                'memory' => new DiskConfig(
                    name: 'memory',
                    driver: StorageDriver::Memory,
                ),
            ],
        );

        $manager = new StorageManager($config);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Storage disk not found: "nonexistent"');

        $manager->disk('nonexistent');
    }

    #[Test]
    public function diskWithoutArgumentUsesDefaultDisk(): void
    {
        $config = new StorageConfig(
            default: 'memory',
            disks: [
                'memory' => new DiskConfig(
                    name: 'memory',
                    driver: StorageDriver::Memory,
                ),
            ],
        );

        $manager = new StorageManager($config);
        $adapter = $manager->disk();

        self::assertInstanceOf(InMemoryStorageAdapter::class, $adapter);
    }

    #[Test]
    public function diskCachesSameInstanceOnRepeatedCalls(): void
    {
        $config = new StorageConfig(
            default: 'memory',
            disks: [
                'memory' => new DiskConfig(
                    name: 'memory',
                    driver: StorageDriver::Memory,
                ),
            ],
        );

        $manager = new StorageManager($config);
        $first = $manager->disk('memory');
        $second = $manager->disk('memory');

        self::assertSame($first, $second);
    }

    #[Test]
    public function diskReturnsDifferentInstancesForDifferentDisks(): void
    {
        $config = new StorageConfig(
            default: 'one',
            disks: [
                'one' => new DiskConfig(
                    name: 'one',
                    driver: StorageDriver::Memory,
                ),
                'two' => new DiskConfig(
                    name: 'two',
                    driver: StorageDriver::Memory,
                ),
            ],
        );

        $manager = new StorageManager($config);
        $first = $manager->disk('one');
        $second = $manager->disk('two');

        self::assertNotSame($first, $second);
    }

    #[Test]
    public function defaultDiskThrowsWhenNotConfigured(): void
    {
        $config = new StorageConfig(
            default: 'missing',
            disks: [],
        );

        $manager = new StorageManager($config);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Storage disk not found: "missing"');

        $manager->disk();
    }
}
