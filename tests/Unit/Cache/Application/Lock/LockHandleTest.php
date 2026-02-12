<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Lock;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Lock\LockHandle;
use ReflectionClass;

#[CoversClass(LockHandle::class)]
final class LockHandleTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $handle = new LockHandle(
            resource: 'my-resource',
            token: 'abc123',
            acquiredAt: 1700000000.123,
            ttlSeconds: 60,
        );

        self::assertSame('my-resource', $handle->resource);
        self::assertSame('abc123', $handle->token);
        self::assertSame(1700000000.123, $handle->acquiredAt);
        self::assertSame(60, $handle->ttlSeconds);
    }

    #[Test]
    public function propertiesAreReadonly(): void
    {
        $handle = new LockHandle(
            resource: 'res',
            token: 'tok',
            acquiredAt: 1.0,
            ttlSeconds: 30,
        );

        $reflection = new ReflectionClass($handle);

        self::assertTrue($reflection->isReadOnly());
    }
}
