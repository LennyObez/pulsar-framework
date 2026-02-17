<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Lock;

use Memcached;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Exception\LockAcquisitionException;
use Pulsar\Cache\Application\Lock\LockHandle;
use Pulsar\Cache\Application\Lock\MemcachedLock;

/**
 * @phpstan-type MemcachedStub \Memcached&Stub
 */
#[CoversClass(MemcachedLock::class)]
#[RequiresPhpExtension('memcached')]
final class MemcachedLockTest extends TestCase
{
    /**
     * @return Memcached&Stub
     */
    private function createMemcachedStub(): Memcached&Stub
    {
        return $this->createStub(Memcached::class);
    }

    #[Test]
    public function acquireSucceedsWhenAddReturnsTrue(): void
    {
        $mc = $this->createMemcachedStub();
        $mc->method('add')->willReturn(true);

        $lock = new MemcachedLock($mc);
        $handle = $lock->acquire('test-lock', 30);

        self::assertInstanceOf(LockHandle::class, $handle);
        self::assertSame('test-lock', $handle->resource);
        self::assertSame(30, $handle->ttlSeconds);
        self::assertNotEmpty($handle->token);
    }

    #[Test]
    public function acquireThrowsOnFailureWithZeroTimeout(): void
    {
        $mc = $this->createMemcachedStub();
        $mc->method('add')->willReturn(false);

        $lock = new MemcachedLock($mc);

        $this->expectException(LockAcquisitionException::class);

        $lock->acquire('busy', 30, 0);
    }

    #[Test]
    public function releaseReturnsTrueWhenTokenMatches(): void
    {
        $mc = $this->createMemcachedStub();
        $mc->method('get')->willReturn('matching-token');
        $mc->method('delete')->willReturn(true);

        $lock = new MemcachedLock($mc);
        $handle = new LockHandle('res', 'matching-token', microtime(true), 30);

        self::assertTrue($lock->release($handle));
    }

    #[Test]
    public function releaseReturnsFalseWhenTokenDoesNotMatch(): void
    {
        $mc = $this->createMemcachedStub();
        $mc->method('get')->willReturn('different-token');

        $lock = new MemcachedLock($mc);
        $handle = new LockHandle('res', 'my-token', microtime(true), 30);

        self::assertFalse($lock->release($handle));
    }

    #[Test]
    public function releaseReturnsFalseWhenKeyNotFound(): void
    {
        $mc = $this->createMemcachedStub();
        $mc->method('get')->willReturn(false);

        $lock = new MemcachedLock($mc);
        $handle = new LockHandle('res', 'tok', microtime(true), 30);

        self::assertFalse($lock->release($handle));
    }

    #[Test]
    public function refreshReturnsFalseWhenGetExtendedReturnsFalse(): void
    {
        $mc = $this->createMemcachedStub();
        $mc->method('get')->willReturn(false);

        $lock = new MemcachedLock($mc);
        $handle = new LockHandle('res', 'tok', microtime(true), 30);

        self::assertFalse($lock->refresh($handle, 60));
    }

    #[Test]
    public function refreshReturnsFalseWhenTokenMismatches(): void
    {
        $mc = $this->createMemcachedStub();
        $mc->method('get')->willReturn([
            'value' => 'other-token',
            'cas' => 1.0,
        ]);

        $lock = new MemcachedLock($mc);
        $handle = new LockHandle('res', 'my-token', microtime(true), 30);

        self::assertFalse($lock->refresh($handle, 60));
    }

    #[Test]
    public function refreshReturnsTrueWhenCasSucceeds(): void
    {
        $mc = $this->createMemcachedStub();
        $mc->method('get')->willReturn([
            'value' => 'my-token',
            'cas' => 42.0,
        ]);
        $mc->method('cas')->willReturn(true);

        $lock = new MemcachedLock($mc);
        $handle = new LockHandle('res', 'my-token', microtime(true), 30);

        self::assertTrue($lock->refresh($handle, 60));
    }
}
