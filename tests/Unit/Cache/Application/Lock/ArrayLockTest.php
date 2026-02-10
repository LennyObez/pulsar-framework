<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Lock;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Exception\LockAcquisitionException;
use Pulsar\Cache\Application\Lock\ArrayLock;
use Pulsar\Cache\Application\Lock\LockHandle;

#[CoversClass(ArrayLock::class)]
final class ArrayLockTest extends TestCase
{
    private ArrayLock $lock;

    protected function setUp(): void
    {
        $this->lock = new ArrayLock();
    }

    #[Test]
    public function acquireSucceedsOnFreeResource(): void
    {
        $handle = $this->lock->acquire('resource-1', ttlSeconds: 30);

        self::assertInstanceOf(LockHandle::class, $handle);
        self::assertSame('resource-1', $handle->resource);
        self::assertSame(30, $handle->ttlSeconds);
        self::assertNotEmpty($handle->token);
    }

    #[Test]
    public function acquireFailsOnHeldResourceWithoutTimeout(): void
    {
        $this->lock->acquire('resource-1', ttlSeconds: 30);

        $this->expectException(LockAcquisitionException::class);

        $this->lock->acquire('resource-1', ttlSeconds: 30, timeoutMs: 0);
    }

    #[Test]
    public function releaseSucceedsWithCorrectToken(): void
    {
        $handle = $this->lock->acquire('resource-1', ttlSeconds: 30);

        $released = $this->lock->release($handle);

        self::assertTrue($released);
    }

    #[Test]
    public function releaseFailsWithWrongToken(): void
    {
        $this->lock->acquire('resource-1', ttlSeconds: 30);

        $fakeHandle = new LockHandle(
            resource: 'resource-1',
            token: 'wrong-token',
            acquiredAt: microtime(true),
            ttlSeconds: 30,
        );

        $released = $this->lock->release($fakeHandle);

        self::assertFalse($released);
    }

    #[Test]
    public function refreshExtendsLockTtl(): void
    {
        $handle = $this->lock->acquire('resource-1', ttlSeconds: 5);

        $refreshed = $this->lock->refresh($handle, ttlSeconds: 60);

        self::assertTrue($refreshed);
    }

    #[Test]
    public function refreshFailsWithWrongToken(): void
    {
        $this->lock->acquire('resource-1', ttlSeconds: 30);

        $fakeHandle = new LockHandle(
            resource: 'resource-1',
            token: 'wrong-token',
            acquiredAt: microtime(true),
            ttlSeconds: 30,
        );

        $refreshed = $this->lock->refresh($fakeHandle, ttlSeconds: 60);

        self::assertFalse($refreshed);
    }

    #[Test]
    public function acquireSucceedsAfterRelease(): void
    {
        $handle = $this->lock->acquire('resource-1', ttlSeconds: 30);
        $this->lock->release($handle);

        $newHandle = $this->lock->acquire('resource-1', ttlSeconds: 30);

        self::assertInstanceOf(LockHandle::class, $newHandle);
        self::assertNotSame($handle->token, $newHandle->token);
    }
}
