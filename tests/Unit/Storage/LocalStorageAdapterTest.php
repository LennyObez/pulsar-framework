<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Storage\LocalStorageAdapter;
use Pulsar\Storage\StorageException;

use function strlen;
use function sys_get_temp_dir;
use function uniqid;

use const DIRECTORY_SEPARATOR;

#[CoversClass(LocalStorageAdapter::class)]
final class LocalStorageAdapterTest extends TestCase
{
    private string $basePath;
    private LocalStorageAdapter $adapter;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_storage_test_' . uniqid('', true);
        mkdir($this->basePath, 0o750, true);
        $this->adapter = new LocalStorageAdapter($this->basePath);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);
    }

    #[Test]
    public function putAndGetReturnsContent(): void
    {
        $this->adapter->put('greeting.txt', 'Hello, local!');

        $content = $this->adapter->get('greeting.txt');

        self::assertSame('Hello, local!', $content);
    }

    #[Test]
    public function putCreatesSubdirectories(): void
    {
        $this->adapter->put('deep/nested/dir/file.txt', 'nested content');

        self::assertSame('nested content', $this->adapter->get('deep/nested/dir/file.txt'));
    }

    #[Test]
    public function existsReturnsTrueForStoredFile(): void
    {
        $this->adapter->put('exists.txt', 'data');

        self::assertTrue($this->adapter->exists('exists.txt'));
    }

    #[Test]
    public function existsReturnsFalseForMissingFile(): void
    {
        self::assertFalse($this->adapter->exists('missing.txt'));
    }

    #[Test]
    public function deleteRemovesFile(): void
    {
        $this->adapter->put('to-delete.txt', 'data');
        $this->adapter->delete('to-delete.txt');

        self::assertFalse($this->adapter->exists('to-delete.txt'));
    }

    #[Test]
    public function deleteNonExistentFileDoesNotThrow(): void
    {
        $this->adapter->delete('never-existed.txt');

        self::assertFalse($this->adapter->exists('never-existed.txt'));
    }

    #[Test]
    public function listReturnsStoredFiles(): void
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
    public function listReturnsEmptyArrayWhenDirectoryDoesNotExist(): void
    {
        $objects = $this->adapter->list('nonexistent/');

        self::assertSame([], $objects);
    }

    #[Test]
    public function listReportsCorrectSize(): void
    {
        $content = 'file content here';
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
    public function pathTraversalWithDoubleDotIsRejected(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('path traversal not allowed');

        $this->adapter->put('../escape.txt', 'malicious');
    }

    #[Test]
    public function pathTraversalInMiddleOfKeyIsRejected(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('path traversal not allowed');

        $_ = $this->adapter->get('subdir/../../etc/passwd');
    }

    #[Test]
    public function keyStartingWithSlashIsRejected(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('key must not start with a directory separator');

        $this->adapter->put('/absolute/path.txt', 'data');
    }

    #[Test]
    public function keyStartingWithBackslashIsRejected(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('key must not start with a directory separator');

        $this->adapter->put('\\absolute\\path.txt', 'data');
    }

    #[Test]
    public function emptyKeyIsRejected(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('key must not be empty');

        $this->adapter->put('', 'data');
    }

    #[Test]
    public function temporaryUrlReturnsNull(): void
    {
        $this->adapter->put('file.txt', 'data');

        $url = $this->adapter->temporaryUrl('file.txt');

        self::assertNull($url);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
