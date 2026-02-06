<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Storage\InMemoryStorageAdapter;
use Pulsar\Storage\StorageException;
use Pulsar\Storage\StorageMetadata;

use function strlen;

#[CoversClass(InMemoryStorageAdapter::class)]
final class InMemoryStorageAdapterTest extends TestCase
{
    private InMemoryStorageAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new InMemoryStorageAdapter();
    }

    #[Test]
    public function putAndGetReturnsContent(): void
    {
        $this->adapter->put('docs/readme.txt', 'Hello, world!');

        $content = $this->adapter->get('docs/readme.txt');

        self::assertSame('Hello, world!', $content);
    }

    #[Test]
    public function putOverwritesExistingContent(): void
    {
        $this->adapter->put('file.txt', 'original');
        $this->adapter->put('file.txt', 'updated');

        self::assertSame('updated', $this->adapter->get('file.txt'));
    }

    #[Test]
    public function existsReturnsTrueForStoredObject(): void
    {
        $this->adapter->put('existing.txt', 'data');

        self::assertTrue($this->adapter->exists('existing.txt'));
    }

    #[Test]
    public function existsReturnsFalseForMissingObject(): void
    {
        self::assertFalse($this->adapter->exists('missing.txt'));
    }

    #[Test]
    public function deleteRemovesObject(): void
    {
        $this->adapter->put('to-delete.txt', 'data');
        $this->adapter->delete('to-delete.txt');

        self::assertFalse($this->adapter->exists('to-delete.txt'));
    }

    #[Test]
    public function deleteNonExistentKeyDoesNotThrow(): void
    {
        $this->adapter->delete('never-existed.txt');

        self::assertFalse($this->adapter->exists('never-existed.txt'));
    }

    #[Test]
    public function listReturnsAllObjectsWhenNoPrefixGiven(): void
    {
        $this->adapter->put('a.txt', 'aaa');
        $this->adapter->put('b.txt', 'bbb');

        $objects = $this->adapter->list();

        self::assertCount(2, $objects);

        $keys = array_map(static fn($o) => $o->key, $objects);
        sort($keys);
        self::assertSame(['a.txt', 'b.txt'], $keys);
    }

    #[Test]
    public function listFiltersObjectsByPrefix(): void
    {
        $this->adapter->put('logs/app.log', 'log data');
        $this->adapter->put('logs/error.log', 'error data');
        $this->adapter->put('data/file.dat', 'file data');

        $objects = $this->adapter->list('logs/');

        self::assertCount(2, $objects);

        $keys = array_map(static fn($o) => $o->key, $objects);
        sort($keys);
        self::assertSame(['logs/app.log', 'logs/error.log'], $keys);
    }

    #[Test]
    public function listReturnsEmptyArrayWhenNoObjectsMatchPrefix(): void
    {
        $this->adapter->put('data/file.dat', 'data');

        $objects = $this->adapter->list('nonexistent/');

        self::assertSame([], $objects);
    }

    #[Test]
    public function listIncludesContentTypeFromMetadata(): void
    {
        $metadata = new StorageMetadata(contentType: 'application/json');
        $this->adapter->put('config.json', '{}', $metadata);

        $objects = $this->adapter->list();

        self::assertCount(1, $objects);
        self::assertSame('application/json', $objects[0]->contentType);
    }

    #[Test]
    public function listReportsCorrectSize(): void
    {
        $content = 'Hello, world!';
        $this->adapter->put('sized.txt', $content);

        $objects = $this->adapter->list();

        self::assertCount(1, $objects);
        self::assertSame(strlen($content), $objects[0]->size);
    }

    #[Test]
    public function getThrowsObjectNotFoundForMissingKey(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Storage object not found: "missing.txt"');

        $_ = $this->adapter->get('missing.txt');
    }

    #[Test]
    public function temporaryUrlReturnsNull(): void
    {
        $this->adapter->put('file.txt', 'data');

        $url = $this->adapter->temporaryUrl('file.txt');

        self::assertNull($url);
    }
}
