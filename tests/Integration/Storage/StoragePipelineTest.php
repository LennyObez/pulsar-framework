<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\DiskConfig;
use Pulsar\Config\StorageConfig;
use Pulsar\Config\StorageDriver;
use Pulsar\Storage\InMemoryStorageAdapter;
use Pulsar\Storage\StorageException;
use Pulsar\Storage\StorageManager;
use Pulsar\Storage\StorageMetadata;

use function strlen;

#[CoversClass(InMemoryStorageAdapter::class)]
#[CoversClass(StorageManager::class)]
final class StoragePipelineTest extends TestCase
{
    private InMemoryStorageAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new InMemoryStorageAdapter();
    }

    #[Test]
    public function uploadAndRetrieveContent(): void
    {
        $this->adapter->put('docs/readme.txt', 'Hello storage!');

        self::assertTrue($this->adapter->exists('docs/readme.txt'));
        self::assertSame('Hello storage!', $this->adapter->get('docs/readme.txt'));
    }

    #[Test]
    public function putAndGetBinaryContent(): void
    {
        $binary = random_bytes(64);
        $this->adapter->put('files/data.bin', $binary);

        self::assertSame($binary, $this->adapter->get('files/data.bin'));
    }

    #[Test]
    public function overwritingFileReplacesContent(): void
    {
        $this->adapter->put('file.txt', 'original');
        $this->adapter->put('file.txt', 'updated');

        self::assertSame('updated', $this->adapter->get('file.txt'));
    }

    #[Test]
    public function deleteRemovesFile(): void
    {
        $this->adapter->put('temp.txt', 'data');
        self::assertTrue($this->adapter->exists('temp.txt'));

        $this->adapter->delete('temp.txt');

        self::assertFalse($this->adapter->exists('temp.txt'));
    }

    #[Test]
    public function getThrowsOnMissingFile(): void
    {
        $this->expectException(StorageException::class);
        (void) $this->adapter->get('nonexistent.txt');
    }

    #[Test]
    public function listReturnsAllFiles(): void
    {
        $this->adapter->put('a.txt', 'A');
        $this->adapter->put('b.txt', 'B');
        $this->adapter->put('c.txt', 'C');

        $objects = $this->adapter->list();

        self::assertCount(3, $objects);
        $keys = array_map(fn($o) => $o->key, $objects);
        self::assertContains('a.txt', $keys);
        self::assertContains('b.txt', $keys);
        self::assertContains('c.txt', $keys);
    }

    #[Test]
    public function listWithPrefixFiltersResults(): void
    {
        $this->adapter->put('images/photo.jpg', 'img1');
        $this->adapter->put('images/banner.png', 'img2');
        $this->adapter->put('docs/manual.pdf', 'doc1');

        $images = $this->adapter->list('images/');

        self::assertCount(2, $images);
        foreach ($images as $obj) {
            self::assertStringStartsWith('images/', $obj->key);
        }
    }

    #[Test]
    public function storageObjectHasCorrectSize(): void
    {
        $content = 'Hello, World!';
        $this->adapter->put('test.txt', $content);

        $objects = $this->adapter->list();

        self::assertCount(1, $objects);
        self::assertSame(strlen($content), $objects[0]->size);
    }

    #[Test]
    public function storageObjectPreservesMetadata(): void
    {
        $metadata = new StorageMetadata(contentType: 'image/png');
        $this->adapter->put('image.png', 'fake-png-data', $metadata);

        $objects = $this->adapter->list();

        self::assertCount(1, $objects);
        self::assertSame('image.png', $objects[0]->key);
        self::assertSame('image/png', $objects[0]->contentType);
    }

    #[Test]
    public function storageManagerResolvesDefaultDisk(): void
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
        $disk = $manager->disk();

        self::assertInstanceOf(InMemoryStorageAdapter::class, $disk);
    }

    #[Test]
    public function storageManagerResolvesDiskByName(): void
    {
        $config = new StorageConfig(
            default: 'memory',
            disks: [
                'memory' => new DiskConfig(
                    name: 'memory',
                    driver: StorageDriver::Memory,
                ),
                'cache' => new DiskConfig(
                    name: 'cache',
                    driver: StorageDriver::Memory,
                ),
            ],
        );

        $manager = new StorageManager($config);

        self::assertInstanceOf(InMemoryStorageAdapter::class, $manager->disk('cache'));
    }

    #[Test]
    public function storageManagerThrowsForUnknownDisk(): void
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
        $manager->disk('nonexistent');
    }

    #[Test]
    public function storageManagerCachesDiskAdapter(): void
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

        $disk1 = $manager->disk();
        $disk2 = $manager->disk();

        self::assertSame($disk1, $disk2);
    }

    #[Test]
    public function inMemoryAdapterTemporaryUrlReturnsNull(): void
    {
        $this->adapter->put('file.txt', 'data');

        $url = $this->adapter->temporaryUrl('file.txt', 3600);

        self::assertNull($url);
    }

    #[Test]
    public function multipleFilesSamePrefix(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->adapter->put("uploads/file-{$i}.txt", "content {$i}");
        }
        $this->adapter->put('other/file.txt', 'other');

        $uploads = $this->adapter->list('uploads/');

        self::assertCount(5, $uploads);
    }
}
