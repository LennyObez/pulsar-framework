<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\CacheDriverType;
use Pulsar\Config\CachePoolConfig;

#[CoversClass(CachePoolConfig::class)]
final class CachePoolConfigTest extends TestCase
{
    #[Test]
    public function constructorDefaults(): void
    {
        $config = new CachePoolConfig(name: 'test');

        self::assertSame('test', $config->name);
        self::assertSame(CacheDriverType::Filesystem, $config->driver);
        self::assertSame('json', $config->serializer);
        self::assertNull($config->defaultTtlSeconds);
        self::assertFalse($config->critical);
        self::assertFalse($config->encrypted);
        self::assertSame('auto', $config->tagsStrategy);
        self::assertNull($config->host);
        self::assertNull($config->port);
        self::assertNull($config->path);
    }

    #[Test]
    public function allPropertiesSetCorrectly(): void
    {
        $config = new CachePoolConfig(
            name: 'redis-pool',
            driver: CacheDriverType::Redis,
            serializer: 'php',
            defaultTtlSeconds: 3600,
            critical: true,
            encrypted: true,
            tagsStrategy: 'strict',
            host: '127.0.0.1',
            port: 6379,
            path: '/data/cache',
        );

        self::assertSame('redis-pool', $config->name);
        self::assertSame(CacheDriverType::Redis, $config->driver);
        self::assertSame('php', $config->serializer);
        self::assertSame(3600, $config->defaultTtlSeconds);
        self::assertTrue($config->critical);
        self::assertTrue($config->encrypted);
        self::assertSame('strict', $config->tagsStrategy);
        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(6379, $config->port);
        self::assertSame('/data/cache', $config->path);
    }
}
