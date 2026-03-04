<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Lock;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Lock\LockHandle;

#[CoversClass(LockHandle::class)]
final class LockHandleExtendedTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $acquiredAt = 1700000000.123;
        $handle = new LockHandle(
            resource: 'my-lock',
            token: 'abc-def-123',
            acquiredAt: $acquiredAt,
            ttlSeconds: 60,
        );

        self::assertSame('my-lock', $handle->resource);
        self::assertSame('abc-def-123', $handle->token);
        self::assertSame($acquiredAt, $handle->acquiredAt);
        self::assertSame(60, $handle->ttlSeconds);
    }

    #[Test]
    public function differentHandlesHaveDifferentTokens(): void
    {
        $handle1 = new LockHandle('res', 'token-1', 0.0, 30);
        $handle2 = new LockHandle('res', 'token-2', 0.0, 30);

        self::assertNotSame($handle1->token, $handle2->token);
    }

    #[Test]
    public function handlePreservesResourceName(): void
    {
        $handle = new LockHandle('very-specific-resource-name', 'tok', 0.0, 10);

        self::assertSame('very-specific-resource-name', $handle->resource);
    }

    #[Test]
    public function ttlCanBeZero(): void
    {
        $handle = new LockHandle('res', 'tok', 0.0, 0);

        self::assertSame(0, $handle->ttlSeconds);
    }
}
