<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Storage;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Storage\LocalStorageAdapter;
use Pulsar\Storage\StorageException;
use Pulsar\Storage\StorageMetadata;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function is_dir;
use function mkdir;
use function rmdir;
use function str_replace;
use function strlen;
use function sys_get_temp_dir;
use function uniqid;

use const DIRECTORY_SEPARATOR;

#[CoversClass(LocalStorageAdapter::class)]
final class StorageIntegrationTest extends TestCase
{
    private string $basePath;
    private LocalStorageAdapter $storage;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_storage_test_' . uniqid();
        mkdir($this->basePath, 0o755, true);
        $this->storage = new LocalStorageAdapter($this->basePath);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);
    }

    // ---------------------------------------------------------------
    // Write / Read
    // ---------------------------------------------------------------

    #[Test]
    public function putAndGetStringContent(): void
    {
        $this->storage->put('documents/readme.txt', 'Hello, Pulsar!');

        $content = $this->storage->get('documents/readme.txt');

        self::assertSame('Hello, Pulsar!', $content);
    }

    #[Test]
    public function putAndGetBinaryContent(): void
    {
        $binary = random_bytes(128);

        $this->storage->put('data/blob.bin', $binary);

        self::assertSame($binary, $this->storage->get('data/blob.bin'));
    }

    #[Test]
    public function putOverwritesExistingFile(): void
    {
        $this->storage->put('config.json', '{"version": 1}');
        $this->storage->put('config.json', '{"version": 2}');

        self::assertSame('{"version": 2}', $this->storage->get('config.json'));
    }

    #[Test]
    public function putCreatesIntermediateDirectories(): void
    {
        $this->storage->put('deep/nested/path/file.txt', 'deep content');

        self::assertSame('deep content', $this->storage->get('deep/nested/path/file.txt'));
    }

    #[Test]
    public function putEmptyContent(): void
    {
        $this->storage->put('empty.txt', '');

        self::assertSame('', $this->storage->get('empty.txt'));
    }

    #[Test]
    public function putWithMetadataDoesNotAffectContent(): void
    {
        $metadata = new StorageMetadata(contentType: 'application/json');

        $this->storage->put('data.json', '{"key":"value"}', $metadata);

        self::assertSame('{"key":"value"}', $this->storage->get('data.json'));
    }

    // ---------------------------------------------------------------
    // Existence checks
    // ---------------------------------------------------------------

    #[Test]
    public function existsReturnsTrueForExistingFile(): void
    {
        $this->storage->put('present.txt', 'exists');

        self::assertTrue($this->storage->exists('present.txt'));
    }

    #[Test]
    public function existsReturnsFalseForMissingFile(): void
    {
        self::assertFalse($this->storage->exists('absent.txt'));
    }

    #[Test]
    public function existsReturnsFalseAfterDeletion(): void
    {
        $this->storage->put('temporary.txt', 'temp');
        $this->storage->delete('temporary.txt');

        self::assertFalse($this->storage->exists('temporary.txt'));
    }

    // ---------------------------------------------------------------
    // Deletion
    // ---------------------------------------------------------------

    #[Test]
    public function deleteRemovesExistingFile(): void
    {
        $this->storage->put('trash.txt', 'discard');

        $this->storage->delete('trash.txt');

        self::assertFalse($this->storage->exists('trash.txt'));
    }

    #[Test]
    public function deleteNonExistentFileDoesNotThrow(): void
    {
        // Should complete without exception
        $this->storage->delete('ghost.txt');

        self::assertFalse($this->storage->exists('ghost.txt'));
    }

    #[Test]
    public function deleteAndReCreateFile(): void
    {
        $this->storage->put('cycle.txt', 'first');
        $this->storage->delete('cycle.txt');
        $this->storage->put('cycle.txt', 'second');

        self::assertSame('second', $this->storage->get('cycle.txt'));
    }

    // ---------------------------------------------------------------
    // Read errors
    // ---------------------------------------------------------------

    #[Test]
    public function getThrowsForNonExistentFile(): void
    {
        $this->expectException(StorageException::class);

        (void) $this->storage->get('nonexistent.txt');
    }

    #[Test]
    public function getThrowsAfterDeletion(): void
    {
        $this->storage->put('removed.txt', 'data');
        $this->storage->delete('removed.txt');

        $this->expectException(StorageException::class);
        (void) $this->storage->get('removed.txt');
    }

    // ---------------------------------------------------------------
    // Directory listing
    // ---------------------------------------------------------------

    #[Test]
    public function listReturnsAllFilesRecursively(): void
    {
        $this->storage->put('a.txt', 'A');
        $this->storage->put('dir/b.txt', 'B');
        $this->storage->put('dir/sub/c.txt', 'C');

        $objects = $this->storage->list();

        self::assertCount(3, $objects);

        $keys = array_map(fn($o) => str_replace('\\', '/', $o->key), $objects);
        self::assertContains('a.txt', $keys);
        self::assertContains('dir/b.txt', $keys);
        self::assertContains('dir/sub/c.txt', $keys);
    }

    #[Test]
    public function listWithPrefixFiltersToSubdirectory(): void
    {
        $this->storage->put('images/photo.jpg', 'jpg');
        $this->storage->put('images/banner.png', 'png');
        $this->storage->put('docs/manual.pdf', 'pdf');

        $images = $this->storage->list('images');

        self::assertCount(2, $images);
    }

    #[Test]
    public function listReturnsEmptyForNonExistentPrefix(): void
    {
        $this->storage->put('real.txt', 'data');

        $objects = $this->storage->list('nonexistent');

        self::assertSame([], $objects);
    }

    #[Test]
    public function listReturnsEmptyForEmptyStorage(): void
    {
        self::assertSame([], $this->storage->list());
    }

    #[Test]
    public function listObjectsHaveCorrectSize(): void
    {
        $content = 'Hello, World!';
        $this->storage->put('sized.txt', $content);

        $objects = $this->storage->list();

        self::assertCount(1, $objects);
        self::assertSame(strlen($content), $objects[0]->size);
    }

    #[Test]
    public function listObjectsHaveRecentTimestamp(): void
    {
        $before = time() - 1;
        $this->storage->put('timed.txt', 'content');

        $objects = $this->storage->list();

        self::assertCount(1, $objects);
        self::assertGreaterThanOrEqual($before, $objects[0]->lastModified);
    }

    // ---------------------------------------------------------------
    // Path resolution and security
    // ---------------------------------------------------------------

    #[Test]
    public function pathTraversalIsBlocked(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessageIsOrContains('path traversal');

        $this->storage->put('../escape.txt', 'malicious');
    }

    #[Test]
    public function emptyKeyIsRejected(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessageIsOrContains('must not be empty');

        $this->storage->put('', 'content');
    }

    #[Test]
    public function absolutePathKeyIsRejected(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessageIsOrContains('directory separator');

        $this->storage->put('/etc/passwd', 'hack');
    }

    #[Test]
    public function backslashAbsolutePathIsRejected(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessageIsOrContains('directory separator');

        $this->storage->put('\\windows\\system', 'hack');
    }

    #[Test]
    public function pathTraversalInMiddleIsBlocked(): void
    {
        $this->expectException(StorageException::class);

        (void) $this->storage->get('valid/../../../etc/passwd');
    }

    // ---------------------------------------------------------------
    // Temporary URL
    // ---------------------------------------------------------------

    #[Test]
    public function temporaryUrlReturnsNullForLocalAdapter(): void
    {
        $this->storage->put('file.txt', 'data');

        self::assertNull($this->storage->temporaryUrl('file.txt'));
    }

    // ---------------------------------------------------------------
    // File lifecycle (write -> read -> overwrite -> delete -> verify)
    // ---------------------------------------------------------------

    #[Test]
    public function fullLifecycleScenario(): void
    {
        // Create
        $this->storage->put('lifecycle/report.csv', 'id,name');
        self::assertTrue($this->storage->exists('lifecycle/report.csv'));

        // Read
        self::assertSame('id,name', $this->storage->get('lifecycle/report.csv'));

        // Overwrite
        $this->storage->put('lifecycle/report.csv', 'id,name,email');
        self::assertSame('id,name,email', $this->storage->get('lifecycle/report.csv'));

        // List
        $objects = $this->storage->list('lifecycle');
        self::assertCount(1, $objects);

        // Delete
        $this->storage->delete('lifecycle/report.csv');
        self::assertFalse($this->storage->exists('lifecycle/report.csv'));
        self::assertSame([], $this->storage->list('lifecycle'));
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Recursively remove a directory tree within sys_get_temp_dir().
     *
     * Uses RecursiveDirectoryIterator so no manual path concatenation is needed.
     * The safety guard ensures this only operates inside the OS temp directory.
     */
    private function removeDirectory(string $dir): void
    {
        $realDir = realpath($dir);
        if ($realDir === false || !is_dir($realDir)) {
            return;
        }

        $tempRoot = realpath(sys_get_temp_dir());
        if ($tempRoot === false || !str_starts_with($realDir, $tempRoot)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($realDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                // getRealPath() returns the canonical absolute path — safe
                $resolved = $item->getRealPath();
                if ($resolved !== false) {
                    unlink($resolved);
                }
            }
        }

        rmdir($realDir);
    }
}
