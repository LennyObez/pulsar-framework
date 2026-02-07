<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\CacheException;
use Pulsar\Cache\CacheLock;

#[CoversClass(CacheLock::class)]
final class CacheLockTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_cache_lock_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function acquireCreatesLockFile(): void
    {
        $lock = new CacheLock($this->tempDir);
        $lock->acquire();

        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . '.lock');

        $lock->release();
    }

    #[Test]
    public function acquireAndReleaseCompleteWithoutError(): void
    {
        $this->expectNotToPerformAssertions();

        $lock = new CacheLock($this->tempDir);
        $lock->acquire();
        $lock->release();
    }

    #[Test]
    public function releaseWithoutAcquireIsNoOp(): void
    {
        $this->expectNotToPerformAssertions();

        $lock = new CacheLock($this->tempDir);
        $lock->release();
    }

    #[Test]
    public function releaseCanBeCalledMultipleTimesSafely(): void
    {
        $this->expectNotToPerformAssertions();

        $lock = new CacheLock($this->tempDir);
        $lock->acquire();
        $lock->release();
        $lock->release();
    }

    #[Test]
    public function acquireCreatesCacheDirIfMissing(): void
    {
        $nestedDir = $this->tempDir . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'cache';
        $lock = new CacheLock($nestedDir);
        $lock->acquire();

        self::assertDirectoryExists($nestedDir);
        self::assertFileExists($nestedDir . DIRECTORY_SEPARATOR . '.lock');

        $lock->release();
    }

    #[Test]
    public function destructorReleasesLock(): void
    {
        $lock = new CacheLock($this->tempDir);
        $lock->acquire();

        $lockFilePath = $this->tempDir . DIRECTORY_SEPARATOR . '.lock';
        self::assertFileExists($lockFilePath);

        // Destroying the object should release the lock
        unset($lock);

        // After destruction, we should be able to acquire the lock again
        $lock2 = new CacheLock($this->tempDir);
        $lock2->acquire();

        self::assertFileExists($lockFilePath);

        $lock2->release();
    }

    #[Test]
    public function lockCanBeReacquiredAfterRelease(): void
    {
        $this->expectNotToPerformAssertions();

        $lock = new CacheLock($this->tempDir);

        $lock->acquire();
        $lock->release();

        $lock->acquire();
        $lock->release();
    }

    #[Test]
    public function acquireWorksWithExistingDirectory(): void
    {
        self::assertDirectoryExists($this->tempDir);

        $lock = new CacheLock($this->tempDir);
        $lock->acquire();

        self::assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . '.lock');

        $lock->release();
    }

    #[Test]
    public function acquireThrowsCacheExceptionWhenDirectoryCannotBeCreated(): void
    {
        // Create a regular file where the cache directory should be,
        // so mkdir() inside acquire() cannot create the directory.
        $blockingFile = $this->tempDir . DIRECTORY_SEPARATOR . 'blocked';
        file_put_contents($blockingFile, '');

        // Point the lock at a path UNDER the blocking file (file cannot be a directory)
        $invalidDir = $blockingFile . DIRECTORY_SEPARATOR . 'sub';

        $lock = new CacheLock($invalidDir);

        $caughtException = null;

        // Suppress the expected mkdir() warning from the source code
        set_error_handler(static fn(): bool => true);

        try {
            $lock->acquire();
        } catch (CacheException $e) {
            $caughtException = $e;
        } finally {
            restore_error_handler();
        }

        self::assertNotNull($caughtException);
        self::assertStringContainsString('Failed to acquire cache lock', $caughtException->getMessage());
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
