<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\DiskConfig;
use Pulsar\Config\StorageConfig;
use Pulsar\Config\StorageDriver;
use Pulsar\Storage\InMemoryStorageAdapter;
use Pulsar\Storage\Storage;
use Pulsar\Storage\StorageAdapterInterface;
use Pulsar\Storage\StorageException;
use Pulsar\Storage\StorageManager;

final class StorageFacadeTest extends TestCase
{
    protected function setUp(): void
    {
        Storage::reset();
    }

    protected function tearDown(): void
    {
        Storage::reset();
    }

    #[Test]
    public function throwsWhenNotBound(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessageIsOrContains('not been initialized');

        (void) Storage::get('any-file');
    }

    #[Test]
    public function diskReturnsAdapterAfterBinding(): void
    {
        $config = new StorageConfig(
            default: 'memory',
            disks: ['memory' => new DiskConfig(name: 'memory', driver: StorageDriver::Memory, root: '')],
        );
        $manager = new StorageManager($config);
        Storage::bind($manager);

        $adapter = Storage::disk('memory');
        self::assertInstanceOf(StorageAdapterInterface::class, $adapter);
    }

    #[Test]
    public function putAndGetRoundTrip(): void
    {
        $config = new StorageConfig(
            default: 'mem',
            disks: ['mem' => new DiskConfig(name: 'mem', driver: StorageDriver::Memory, root: '')],
        );
        Storage::bind(new StorageManager($config));

        Storage::put('test.txt', 'hello world');
        $content = Storage::get('test.txt');

        self::assertSame('hello world', $content);
    }

    #[Test]
    public function existsReturnsTrueAfterPut(): void
    {
        $config = new StorageConfig(
            default: 'mem',
            disks: ['mem' => new DiskConfig(name: 'mem', driver: StorageDriver::Memory, root: '')],
        );
        Storage::bind(new StorageManager($config));

        self::assertFalse(Storage::exists('file.txt'));
        Storage::put('file.txt', 'data');
        self::assertTrue(Storage::exists('file.txt'));
    }

    #[Test]
    public function deleteRemovesFile(): void
    {
        $config = new StorageConfig(
            default: 'mem',
            disks: ['mem' => new DiskConfig(name: 'mem', driver: StorageDriver::Memory, root: '')],
        );
        Storage::bind(new StorageManager($config));

        Storage::put('file.txt', 'data');
        Storage::delete('file.txt');

        self::assertFalse(Storage::exists('file.txt'));
    }

    #[Test]
    public function listReturnsStoredObjects(): void
    {
        $config = new StorageConfig(
            default: 'mem',
            disks: ['mem' => new DiskConfig(name: 'mem', driver: StorageDriver::Memory, root: '')],
        );
        Storage::bind(new StorageManager($config));

        Storage::put('a.txt', 'x');
        Storage::put('b.txt', 'y');

        $objects = Storage::list();
        self::assertCount(2, $objects);
    }

    #[Test]
    public function diskDefaultsToConfigDefault(): void
    {
        $config = new StorageConfig(
            default: 'primary',
            disks: ['primary' => new DiskConfig(name: 'primary', driver: StorageDriver::Memory, root: '')],
        );
        Storage::bind(new StorageManager($config));

        // disk() with null should use 'primary'
        $adapter = Storage::disk();
        self::assertInstanceOf(InMemoryStorageAdapter::class, $adapter);
    }

    #[Test]
    public function temporaryUrlDelegatesForInMemoryReturnsNull(): void
    {
        $config = new StorageConfig(
            default: 'mem',
            disks: ['mem' => new DiskConfig(name: 'mem', driver: StorageDriver::Memory, root: '')],
        );
        Storage::bind(new StorageManager($config));

        Storage::put('file.txt', 'data');
        self::assertNull(Storage::temporaryUrl('file.txt'));
    }
}
