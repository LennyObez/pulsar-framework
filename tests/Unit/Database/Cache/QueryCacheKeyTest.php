<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Cache\QueryCacheKey;
use Pulsar\Database\Routing\ConnectionRole;

#[CoversClass(QueryCacheKey::class)]
final class QueryCacheKeyTest extends TestCase
{
    #[Test]
    public function deterministicKeyForSameInputs(): void
    {
        $key1 = QueryCacheKey::build('SELECT * FROM users', ['id' => 1], 'tenant-1', ConnectionRole::Read, 'v1');
        $key2 = QueryCacheKey::build('SELECT * FROM users', ['id' => 1], 'tenant-1', ConnectionRole::Read, 'v1');

        self::assertSame($key1, $key2);
    }

    #[Test]
    public function differentInputsProduceDifferentKeys(): void
    {
        $key1 = QueryCacheKey::build('SELECT * FROM users', ['id' => 1], 'tenant-1', ConnectionRole::Read, 'v1');
        $key2 = QueryCacheKey::build('SELECT * FROM orders', ['id' => 1], 'tenant-1', ConnectionRole::Read, 'v1');

        self::assertNotSame($key1, $key2);
    }

    #[Test]
    public function bindingOrderDoesNotAffectKey(): void
    {
        $key1 = QueryCacheKey::build('SELECT * FROM users', ['a' => 1, 'b' => 2], null, ConnectionRole::Read, null);
        $key2 = QueryCacheKey::build('SELECT * FROM users', ['b' => 2, 'a' => 1], null, ConnectionRole::Read, null);

        self::assertSame($key1, $key2);
    }

    #[Test]
    public function tenantIdIncludedInKey(): void
    {
        $key1 = QueryCacheKey::build('SELECT 1', [], 'tenant-a', ConnectionRole::Read, null);
        $key2 = QueryCacheKey::build('SELECT 1', [], 'tenant-b', ConnectionRole::Read, null);

        self::assertNotSame($key1, $key2);
    }

    #[Test]
    public function roleIncludedInKey(): void
    {
        $key1 = QueryCacheKey::build('SELECT 1', [], null, ConnectionRole::Read, null);
        $key2 = QueryCacheKey::build('SELECT 1', [], null, ConnectionRole::Write, null);

        self::assertNotSame($key1, $key2);
    }

    #[Test]
    public function schemaVersionIncludedInKey(): void
    {
        $key1 = QueryCacheKey::build('SELECT 1', [], null, ConnectionRole::Read, 'v1');
        $key2 = QueryCacheKey::build('SELECT 1', [], null, ConnectionRole::Read, 'v2');

        self::assertNotSame($key1, $key2);
    }
}
