<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Lock;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Exception\LockAcquisitionException;
use Pulsar\Cache\Application\Lock\LockHandle;
use Pulsar\Cache\Application\Lock\LockInterface;

#[CoversClass(LockInterface::class)]
final class LockInterfaceTest extends TestCase
{
    #[Test]
    public function acquireReturnsLockHandle(): void
    {
        $lock = new class implements LockInterface {
            public function acquire(string $resource, int $ttlSeconds = 30, int $timeoutMs = 0): LockHandle
            {
                return new LockHandle($resource, 'token-abc', microtime(true), $ttlSeconds);
            }

            public function release(LockHandle $handle): bool
            {
                return true;
            }

            public function refresh(LockHandle $handle, int $ttlSeconds = 30): bool
            {
                return true;
            }
        };

        $handle = $lock->acquire('my-resource', 60);

        self::assertSame('my-resource', $handle->resource);
        self::assertSame('token-abc', $handle->token);
    }

    #[Test]
    public function releaseReturnsTrueOnSuccess(): void
    {
        $lock = new class implements LockInterface {
            public function acquire(string $resource, int $ttlSeconds = 30, int $timeoutMs = 0): LockHandle
            {
                return new LockHandle($resource, 'token', microtime(true), $ttlSeconds);
            }

            public function release(LockHandle $handle): bool
            {
                return true;
            }

            public function refresh(LockHandle $handle, int $ttlSeconds = 30): bool
            {
                return true;
            }
        };

        $handle = $lock->acquire('res');
        $result = $lock->release($handle);

        self::assertTrue($result);
    }

    #[Test]
    public function releaseReturnsFalseWhenExpired(): void
    {
        $lock = new class implements LockInterface {
            public function acquire(string $resource, int $ttlSeconds = 30, int $timeoutMs = 0): LockHandle
            {
                return new LockHandle($resource, 'token', microtime(true), $ttlSeconds);
            }

            public function release(LockHandle $handle): bool
            {
                return false;
            }

            public function refresh(LockHandle $handle, int $ttlSeconds = 30): bool
            {
                return false;
            }
        };

        $handle = $lock->acquire('res');

        self::assertFalse($lock->release($handle));
    }

    #[Test]
    public function refreshExtendsTtl(): void
    {
        $lock = new class implements LockInterface {
            public function acquire(string $resource, int $ttlSeconds = 30, int $timeoutMs = 0): LockHandle
            {
                return new LockHandle($resource, 'token', microtime(true), $ttlSeconds);
            }

            public function release(LockHandle $handle): bool
            {
                return true;
            }

            public function refresh(LockHandle $handle, int $ttlSeconds = 30): bool
            {
                return true;
            }
        };

        $handle = $lock->acquire('res', 10);

        self::assertTrue($lock->refresh($handle, 60));
    }

    #[Test]
    public function acquireThrowsOnTimeout(): void
    {
        $lock = new class implements LockInterface {
            public function acquire(string $resource, int $ttlSeconds = 30, int $timeoutMs = 0): LockHandle
            {
                throw LockAcquisitionException::timeout($resource, $timeoutMs);
            }

            public function release(LockHandle $handle): bool
            {
                return true;
            }

            public function refresh(LockHandle $handle, int $ttlSeconds = 30): bool
            {
                return true;
            }
        };

        $this->expectException(LockAcquisitionException::class);
        $lock->acquire('contested-resource', 30, 5000);
    }

    #[Test]
    public function acquireUsesDefaultTtlAndTimeout(): void
    {
        $lock = new class implements LockInterface {
            public int $receivedTtl = 0;
            public int $receivedTimeout = -1;

            public function acquire(string $resource, int $ttlSeconds = 30, int $timeoutMs = 0): LockHandle
            {
                $this->receivedTtl = $ttlSeconds;
                $this->receivedTimeout = $timeoutMs;

                return new LockHandle($resource, 'tok', microtime(true), $ttlSeconds);
            }

            public function release(LockHandle $handle): bool
            {
                return true;
            }
            public function refresh(LockHandle $handle, int $ttlSeconds = 30): bool
            {
                return true;
            }
        };

        $lock->acquire('res');

        self::assertSame(30, $lock->receivedTtl);
        self::assertSame(0, $lock->receivedTimeout);
    }
}
