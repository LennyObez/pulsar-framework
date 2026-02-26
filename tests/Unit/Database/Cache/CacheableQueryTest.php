<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Cache\CacheableQuery;

#[CoversClass(CacheableQuery::class)]
final class CacheableQueryTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $query = new CacheableQuery(
            sql: 'SELECT * FROM users WHERE id = ?',
            bindings: [':id' => 1],
            ttlSeconds: 120,
            tags: ['users'],
        );

        self::assertSame('SELECT * FROM users WHERE id = ?', $query->sql);
        self::assertSame([':id' => 1], $query->bindings);
        self::assertSame(120, $query->ttlSeconds);
        self::assertSame(['users'], $query->tags);
    }

    #[Test]
    public function factoryMethod(): void
    {
        $query = CacheableQuery::forQuery(
            'SELECT * FROM orders',
            [':status' => 'active'],
            60,
            ['orders'],
        );

        self::assertInstanceOf(CacheableQuery::class, $query);
        self::assertSame('SELECT * FROM orders', $query->sql);
        self::assertSame([':status' => 'active'], $query->bindings);
        self::assertSame(60, $query->ttlSeconds);
        self::assertSame(['orders'], $query->tags);
    }
}
