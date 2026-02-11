<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Lock;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Lock\LockHandle;

#[CoversClass(LockHandle::class)]
final class LockHandleTest extends TestCase
{
    #[Test]
    public function twoHandlesWithSameResourceButDifferentTokensAreDistinct(): void
    {
        $a = new LockHandle(resource: 'my-resource', token: 'tok-a', acquiredAt: 1.0, ttlSeconds: 60);
        $b = new LockHandle(resource: 'my-resource', token: 'tok-b', acquiredAt: 1.0, ttlSeconds: 60);

        self::assertNotSame($a->token, $b->token);
        self::assertSame($a->resource, $b->resource);
    }

    #[Test]
    public function handlePreservesSubSecondAcquiredAtPrecision(): void
    {
        $handle = new LockHandle(
            resource: 'res',
            token: 'tok',
            acquiredAt: 1700000000.123456,
            ttlSeconds: 30,
        );

        self::assertSame(1700000000.123456, $handle->acquiredAt);
    }

    #[Test]
    public function zeroTtlIsValid(): void
    {
        $handle = new LockHandle(
            resource: 'res',
            token: 'tok',
            acquiredAt: 1.0,
            ttlSeconds: 0,
        );

        self::assertSame(0, $handle->ttlSeconds);
    }
}
