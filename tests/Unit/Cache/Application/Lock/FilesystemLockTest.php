<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Lock;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Exception\LockAcquisitionException;
use Pulsar\Cache\Application\Lock\FilesystemLock;
use Pulsar\Cache\Application\Lock\LockHandle;

use function glob;
use function is_dir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversClass(FilesystemLock::class)]
final class FilesystemLockTest extends TestCase
{
    private string $directory;
    private FilesystemLock $lock;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/pulsar_lock_test_' . uniqid();
        $this->lock = new FilesystemLock($this->directory);
    }

    protected function tearDown(): void
    {
        $files = glob($this->directory . '/*');

        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }

        if (is_dir($this->directory)) {
            @rmdir($this->directory);
        }
    }

    #[Test]
    public function acquireSucceeds(): void
    {
        $handle = $this->lock->acquire('test-resource', ttlSeconds: 30);

        self::assertInstanceOf(LockHandle::class, $handle);
        self::assertSame('test-resource', $handle->resource);
        self::assertNotEmpty($handle->token);
    }

    #[Test]
    public function releaseSucceeds(): void
    {
        $handle = $this->lock->acquire('test-resource', ttlSeconds: 30);

        $released = $this->lock->release($handle);

        self::assertTrue($released);
    }

    #[Test]
    public function acquireSucceedsAfterRelease(): void
    {
        $handle = $this->lock->acquire('test-resource', ttlSeconds: 30);
        $this->lock->release($handle);

        $newHandle = $this->lock->acquire('test-resource', ttlSeconds: 30);

        self::assertInstanceOf(LockHandle::class, $newHandle);
    }

    #[Test]
    public function refreshExtendsLockTtl(): void
    {
        $handle = $this->lock->acquire('test-resource', ttlSeconds: 5);

        $refreshed = $this->lock->refresh($handle, ttlSeconds: 60);

        self::assertTrue($refreshed);
    }

    #[Test]
    public function acquireThrowsWhenLockIsAlreadyHeld(): void
    {
        $this->lock->acquire('test-resource', ttlSeconds: 30);

        $this->expectException(LockAcquisitionException::class);
        $this->lock->acquire('test-resource', ttlSeconds: 30, timeoutMs: 0);
    }

    #[Test]
    public function releaseFailsWithWrongToken(): void
    {
        $this->lock->acquire('test-resource', ttlSeconds: 30);

        $fakeHandle = new LockHandle(
            resource: 'test-resource',
            token: 'wrong-token',
            acquiredAt: microtime(true),
            ttlSeconds: 30,
        );

        self::assertFalse($this->lock->release($fakeHandle));
    }
}
