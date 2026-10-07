<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Lock;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Exception\LockAcquisitionException;
use Pulsar\Cache\Application\Lock\ApcuLock;
use Pulsar\Cache\Application\Lock\LockHandle;

use function function_exists;
use function ini_get;

#[CoversClass(ApcuLock::class)]
final class ApcuLockTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('apcu_add') || !ini_get('apc.enable_cli')) {
            self::markTestSkipped('APCu extension is not available or not enabled for CLI');
        }

        // Clear APCu cache for test isolation
        apcu_clear_cache();
    }

    protected function tearDown(): void
    {
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }
    }

    #[Test]
    public function acquireSucceedsOnFirstAttempt(): void
    {
        $lock = new ApcuLock();
        $handle = $lock->acquire('test-resource', 30);

        self::assertInstanceOf(LockHandle::class, $handle);
        self::assertSame('test-resource', $handle->resource);
        self::assertSame(30, $handle->ttlSeconds);
        self::assertNotEmpty($handle->token);
        self::assertGreaterThan(0, $handle->acquiredAt);
    }

    #[Test]
    public function acquireFailsWhenResourceAlreadyLocked(): void
    {
        $lock = new ApcuLock();
        $lock->acquire('busy-resource', 30);

        $this->expectException(LockAcquisitionException::class);

        $lock->acquire('busy-resource', 30, 0);
    }

    #[Test]
    public function releaseSucceedsWithCorrectToken(): void
    {
        $lock = new ApcuLock();
        $handle = $lock->acquire('releasable', 30);

        self::assertTrue($lock->release($handle));
    }

    #[Test]
    public function releaseFailsWithWrongToken(): void
    {
        $lock = new ApcuLock();
        $lock->acquire('owned', 30);

        $fakeHandle = new LockHandle('owned', 'wrong-token', microtime(true), 30);

        self::assertFalse($lock->release($fakeHandle));
    }

    #[Test]
    public function releaseFailsWhenKeyDoesNotExist(): void
    {
        $lock = new ApcuLock();
        $handle = new LockHandle('nonexistent', 'tok', microtime(true), 30);

        self::assertFalse($lock->release($handle));
    }

    #[Test]
    public function refreshSucceedsWithCorrectToken(): void
    {
        $lock = new ApcuLock();
        $handle = $lock->acquire('refreshable', 30);

        self::assertTrue($lock->refresh($handle, 60));
    }

    #[Test]
    public function refreshFailsWithWrongToken(): void
    {
        $lock = new ApcuLock();
        $lock->acquire('owned2', 30);

        $fakeHandle = new LockHandle('owned2', 'wrong', microtime(true), 30);

        self::assertFalse($lock->refresh($fakeHandle, 60));
    }

    #[Test]
    public function refreshFailsWhenKeyDoesNotExist(): void
    {
        $lock = new ApcuLock();
        $handle = new LockHandle('gone', 'tok', microtime(true), 30);

        self::assertFalse($lock->refresh($handle, 60));
    }

    #[Test]
    public function acquireGeneratesUniqueTokens(): void
    {
        $lock = new ApcuLock();

        $handle1 = $lock->acquire('res-1', 30);
        $handle2 = $lock->acquire('res-2', 30);

        self::assertNotSame($handle1->token, $handle2->token);
    }
}
