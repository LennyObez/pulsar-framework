<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Lock;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Exception\FenceTokenMismatchException;
use Pulsar\Cache\Application\Lock\ArrayLock;
use Pulsar\Cache\Application\Lock\FencedExecutor;
use Pulsar\Cache\Application\Lock\LockHandle;

#[CoversClass(FencedExecutor::class)]
final class FencedExecutorTest extends TestCase
{
    #[Test]
    public function executeCallsCallbackWhenLockIsValid(): void
    {
        $lock = new ArrayLock();
        $handle = $lock->acquire('resource-1', ttlSeconds: 30);
        $executor = new FencedExecutor($lock);

        $result = $executor->execute($handle, static fn(LockHandle $h): string => 'executed-' . $h->resource);

        self::assertSame('executed-resource-1', $result);
    }

    #[Test]
    public function executeThrowsWhenLockIsExpired(): void
    {
        $lock = new ArrayLock();
        $executor = new FencedExecutor($lock);

        // Create a handle with a fake token that won't match any held lock
        $fakeHandle = new LockHandle(
            resource: 'resource-2',
            token: 'invalid-token',
            acquiredAt: microtime(true),
            ttlSeconds: 30,
        );

        $this->expectException(FenceTokenMismatchException::class);

        $executor->execute($fakeHandle, static fn(LockHandle $h): string => 'should-not-run');
    }
}
