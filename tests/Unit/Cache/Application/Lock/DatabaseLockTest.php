<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Lock;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Exception\LockAcquisitionException;
use Pulsar\Cache\Application\Lock\DatabaseLock;
use Pulsar\Cache\Application\Lock\LockHandle;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;

#[CoversClass(DatabaseLock::class)]
final class DatabaseLockTest extends TestCase
{
    private PdoConnection $connection;
    private DatabaseLock $lock;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $this->lock = new DatabaseLock($this->connection);
    }

    #[Test]
    public function acquireSucceeds(): void
    {
        $handle = $this->lock->acquire('db-resource', ttlSeconds: 30);

        self::assertInstanceOf(LockHandle::class, $handle);
        self::assertSame('db-resource', $handle->resource);
        self::assertNotEmpty($handle->token);
    }

    #[Test]
    public function releaseSucceedsWithCorrectToken(): void
    {
        $handle = $this->lock->acquire('db-resource', ttlSeconds: 30);

        $released = $this->lock->release($handle);

        self::assertTrue($released);
    }

    #[Test]
    public function releaseFailsWithWrongToken(): void
    {
        $this->lock->acquire('db-resource', ttlSeconds: 30);

        $fakeHandle = new LockHandle(
            resource: 'db-resource',
            token: 'wrong-token',
            acquiredAt: microtime(true),
            ttlSeconds: 30,
        );

        $released = $this->lock->release($fakeHandle);

        self::assertFalse($released);
    }

    #[Test]
    public function acquireSucceedsAfterRelease(): void
    {
        $handle = $this->lock->acquire('db-resource', ttlSeconds: 30);
        $this->lock->release($handle);

        $newHandle = $this->lock->acquire('db-resource', ttlSeconds: 30);

        self::assertInstanceOf(LockHandle::class, $newHandle);
        self::assertNotSame($handle->token, $newHandle->token);
    }

    #[Test]
    public function refreshExtendsLockTtl(): void
    {
        $handle = $this->lock->acquire('db-resource', ttlSeconds: 5);

        $refreshed = $this->lock->refresh($handle, ttlSeconds: 60);

        self::assertTrue($refreshed);
    }

    #[Test]
    public function refreshFailsWithWrongToken(): void
    {
        $this->lock->acquire('db-resource', ttlSeconds: 30);

        $fakeHandle = new LockHandle(
            resource: 'db-resource',
            token: 'wrong-token',
            acquiredAt: microtime(true),
            ttlSeconds: 30,
        );

        $refreshed = $this->lock->refresh($fakeHandle, ttlSeconds: 60);

        self::assertFalse($refreshed);
    }

    #[Test]
    public function acquireThrowsWhenAlreadyLockedWithNoTimeout(): void
    {
        $this->lock->acquire('exclusive-resource', ttlSeconds: 30);

        $this->expectException(LockAcquisitionException::class);

        $this->lock->acquire('exclusive-resource', ttlSeconds: 30, timeoutMs: 0);
    }

    #[Test]
    public function releaseNonExistentResourceReturnsFalse(): void
    {
        // Ensure table exists by acquiring and releasing first
        $handle = $this->lock->acquire('setup-resource', ttlSeconds: 30);
        $this->lock->release($handle);

        $fakeHandle = new LockHandle(
            resource: 'nonexistent-resource',
            token: 'some-token',
            acquiredAt: microtime(true),
            ttlSeconds: 30,
        );

        $released = $this->lock->release($fakeHandle);

        self::assertFalse($released);
    }

    #[Test]
    public function refreshNonExistentResourceReturnsFalse(): void
    {
        // Ensure table exists
        $handle = $this->lock->acquire('setup-resource', ttlSeconds: 30);
        $this->lock->release($handle);

        $fakeHandle = new LockHandle(
            resource: 'nonexistent-resource',
            token: 'some-token',
            acquiredAt: microtime(true),
            ttlSeconds: 30,
        );

        $refreshed = $this->lock->refresh($fakeHandle, ttlSeconds: 60);

        self::assertFalse($refreshed);
    }

    #[Test]
    public function acquireHandleContainsCorrectTtl(): void
    {
        $handle = $this->lock->acquire('ttl-resource', ttlSeconds: 45);

        self::assertSame(45, $handle->ttlSeconds);
        self::assertGreaterThan(0.0, $handle->acquiredAt);
    }

    #[Test]
    public function acquireDifferentResourcesSucceeds(): void
    {
        $handle1 = $this->lock->acquire('resource-a', ttlSeconds: 30);
        $handle2 = $this->lock->acquire('resource-b', ttlSeconds: 30);

        self::assertSame('resource-a', $handle1->resource);
        self::assertSame('resource-b', $handle2->resource);
        self::assertNotSame($handle1->token, $handle2->token);

        self::assertTrue($this->lock->release($handle1));
        self::assertTrue($this->lock->release($handle2));
    }

    #[Test]
    public function doubleReleaseReturnsFalse(): void
    {
        $handle = $this->lock->acquire('double-release', ttlSeconds: 30);

        self::assertTrue($this->lock->release($handle));
        self::assertFalse($this->lock->release($handle));
    }

    #[Test]
    public function acquireGeneratesUniqueTokens(): void
    {
        $tokens = [];
        for ($i = 0; $i < 5; $i++) {
            $handle = $this->lock->acquire('unique-token-resource', ttlSeconds: 30);
            $tokens[] = $handle->token;
            $this->lock->release($handle);
        }

        // All tokens must be unique
        self::assertSame(5, count(array_unique($tokens)));
    }
}
