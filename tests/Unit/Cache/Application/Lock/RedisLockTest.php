<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Lock;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Exception\LockAcquisitionException;
use Pulsar\Cache\Application\Lock\LockHandle;
use Pulsar\Cache\Application\Lock\RedisLock;
use Redis;

#[CoversClass(RedisLock::class)]
#[RequiresPhpExtension('redis')]
final class RedisLockTest extends TestCase
{
    #[Test]
    public function acquireSucceedsOnFirstAttempt(): void
    {
        $redis = $this->createStub(Redis::class);
        $redis->method('set')->willReturn(true);

        $lock = new RedisLock($redis);
        $handle = $lock->acquire('test-resource', 30);

        self::assertInstanceOf(LockHandle::class, $handle);
        self::assertSame('test-resource', $handle->resource);
        self::assertSame(30, $handle->ttlSeconds);
        self::assertNotEmpty($handle->token);
        self::assertGreaterThan(0, $handle->acquiredAt);
    }

    #[Test]
    public function acquireThrowsOnImmediateFailureWithZeroTimeout(): void
    {
        $redis = $this->createStub(Redis::class);
        $redis->method('set')->willReturn(false);

        $lock = new RedisLock($redis);

        $this->expectException(LockAcquisitionException::class);

        $lock->acquire('busy-resource', 30, 0);
    }

    #[Test]
    public function releaseReturnsTrueWhenScriptReturnsOne(): void
    {
        $redis = $this->createStub(Redis::class);
        $redis->method('eval')->willReturn(1);

        $lock = new RedisLock($redis);
        $handle = new LockHandle('res', 'tok123', microtime(true), 30);

        self::assertTrue($lock->release($handle));
    }

    #[Test]
    public function releaseReturnsFalseWhenScriptReturnsZero(): void
    {
        $redis = $this->createStub(Redis::class);
        $redis->method('eval')->willReturn(0);

        $lock = new RedisLock($redis);
        $handle = new LockHandle('res', 'wrong-token', microtime(true), 30);

        self::assertFalse($lock->release($handle));
    }

    #[Test]
    public function refreshReturnsTrueWhenScriptReturnsOne(): void
    {
        $redis = $this->createStub(Redis::class);
        $redis->method('eval')->willReturn(1);

        $lock = new RedisLock($redis);
        $handle = new LockHandle('res', 'tok123', microtime(true), 30);

        self::assertTrue($lock->refresh($handle, 60));
    }

    #[Test]
    public function refreshReturnsFalseWhenScriptReturnsZero(): void
    {
        $redis = $this->createStub(Redis::class);
        $redis->method('eval')->willReturn(0);

        $lock = new RedisLock($redis);
        $handle = new LockHandle('res', 'expired-token', microtime(true), 30);

        self::assertFalse($lock->refresh($handle, 60));
    }

    #[Test]
    public function acquireGeneratesUniqueTokens(): void
    {
        $callCount = 0;
        $redis = $this->createStub(Redis::class);
        $redis->method('set')->willReturnCallback(function () use (&$callCount): bool {
            $callCount++;
            return true;
        });

        $lock = new RedisLock($redis);
        $handle1 = $lock->acquire('res-1');
        $handle2 = $lock->acquire('res-2');

        self::assertNotSame($handle1->token, $handle2->token);
    }
}
