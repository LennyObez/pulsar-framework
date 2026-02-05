<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Lock;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
}
