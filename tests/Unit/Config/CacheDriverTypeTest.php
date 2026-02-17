<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\CacheDriverType;

#[CoversClass(CacheDriverType::class)]
final class CacheDriverTypeTest extends TestCase
{
    #[Test]
    #[DataProvider('driverProvider')]
    public function backingValuesAreCorrect(CacheDriverType $driver, string $expected): void
    {
        self::assertSame($expected, $driver->value);
    }

    /**
     * @return iterable<string, array{CacheDriverType, string}>
     */
    public static function driverProvider(): iterable
    {
        yield 'array' => [CacheDriverType::Array, 'array'];
        yield 'filesystem' => [CacheDriverType::Filesystem, 'filesystem'];
        yield 'database' => [CacheDriverType::Database, 'database'];
        yield 'redis' => [CacheDriverType::Redis, 'redis'];
        yield 'memcached' => [CacheDriverType::Memcached, 'memcached'];
        yield 'apcu' => [CacheDriverType::Apcu, 'apcu'];
    }

    #[Test]
    public function allCases(): void
    {
        self::assertCount(6, CacheDriverType::cases());
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(CacheDriverType::tryFrom('invalid'));
    }
}
