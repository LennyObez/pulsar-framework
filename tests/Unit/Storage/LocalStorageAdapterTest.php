<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Storage\LocalStorageAdapter;
use Pulsar\Storage\StorageException;
use Symfony\Component\Filesystem\Filesystem;

use function file_put_contents;
use function mkdir;
use function strlen;
use function symlink;
use function sys_get_temp_dir;
use function uniqid;

use const DIRECTORY_SEPARATOR;

#[CoversClass(LocalStorageAdapter::class)]
final class LocalStorageAdapterTest extends TestCase
{
    private string $basePath;
    private LocalStorageAdapter $adapter;

    /** @var list<string> directories outside basePath created by a test, removed in tearDown */
    private array $externalDirs = [];

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_storage_test_' . uniqid('', true);
        mkdir($this->basePath, 0o750, true);
        $this->adapter = new LocalStorageAdapter($this->basePath);
    }

    protected function tearDown(): void
    {
        $filesystem = new Filesystem();
        $filesystem->remove($this->basePath);

        foreach ($this->externalDirs as $dir) {
            $filesystem->remove($dir);
        }
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
        $this->expectExceptionMessageIsOrContains('Storage object not found: "missing.txt"');

        $_ = $this->adapter->get('missing.txt');
    }

    #[Test]
    public function pathTraversalWithDoubleDotIsRejected(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessageIsOrContains('path traversal not allowed');

        $this->adapter->put('../escape.txt', 'malicious');
    }

    #[Test]
    public function pathTraversalInMiddleOfKeyIsRejected(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessageIsOrContains('path traversal not allowed');

        $_ = $this->adapter->get('subdir/../../etc/passwd');
    }

    #[Test]
    public function keyStartingWithSlashIsRejected(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessageIsOrContains('key must not start with a directory separator');

        $this->adapter->put('/absolute/path.txt', 'data');
    }

    #[Test]
    public function keyStartingWithBackslashIsRejected(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessageIsOrContains('key must not start with a directory separator');

        $this->adapter->put('\\absolute\\path.txt', 'data');
    }

    #[Test]
    public function emptyKeyIsRejected(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessageIsOrContains('key must not be empty');

        $this->adapter->put('', 'data');
    }

    #[Test]
    public function temporaryUrlReturnsNull(): void
    {
        $this->adapter->put('file.txt', 'data');

        $url = $this->adapter->temporaryUrl('file.txt');

        self::assertNull($url);
    }

    /**
     * Regression for the symlink boundary bug: a bare str_starts_with prefix
     * check (without a trailing separator) would accept a symlink whose
     * realpath shares a non-separator prefix with basePath — e.g. basePath
     * ".../store" and a sibling ".../store-escape". The escape must be
     * rejected because the resolved path is NOT inside the basePath
     * directory.
     */
    #[Test]
    public function symlinkResolvingToSiblingWithSharedPrefixIsRejected(): void
    {
        // Sibling directory whose absolute path EXTENDS basePath's name
        // (no separator between them) — the heart of the prefix-boundary bug.
        $escapeDir = $this->basePath . '-escape';

        if (!@mkdir($escapeDir, 0o750, true) && !is_dir($escapeDir)) {
            self::markTestSkipped('Unable to create sibling directory for symlink test.');
        }
        $this->externalDirs[] = $escapeDir;

        file_put_contents($escapeDir . DIRECTORY_SEPARATOR . 'secret.txt', 'top secret');

        // Symlink INSIDE basePath that points at the sibling escape directory.
        $linkPath = $this->basePath . DIRECTORY_SEPARATOR . 'link';

        if (!@symlink($escapeDir, $linkPath)) {
            self::markTestSkipped('Symlinks are not supported in this environment.');
        }

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageIsOrContains('symlink escapes storage base path');

        $_ = $this->adapter->get('link/secret.txt');
    }

    /**
     * Guard the boundary fix does not over-reject: a real subdirectory whose
     * realpath legitimately starts with basePath + separator must still work.
     */
    #[Test]
    public function legitimateNestedKeyIsNotRejectedByBoundaryCheck(): void
    {
        $this->adapter->put('nested/dir/file.txt', 'ok');

        self::assertSame('ok', $this->adapter->get('nested/dir/file.txt'));
    }
}
