<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\CacheConfig;
use Pulsar\Config\CacheDriverType;
use Pulsar\Config\CachePoolConfig;
use Pulsar\Config\Environment;
use Pulsar\Config\Exception\ConfigException;

use function extension_loaded;

#[CoversClass(CacheConfig::class)]
final class CacheConfigTest extends TestCase
{
    private Environment $environment;

    protected function setUp(): void
    {
        putenv('CACHE_ENABLED');
        putenv('CACHE_DEFAULT_POOL');
        putenv('CACHE_PATH');
        $this->environment = Environment::load();
    }

    protected function tearDown(): void
    {
        putenv('CACHE_ENABLED');
        putenv('CACHE_DEFAULT_POOL');
        putenv('CACHE_PATH');
    }

    #[Test]
    public function fromArrayWithFullData(): void
    {
        $data = [
            'enabled' => true,
            'default_pool' => 'redis',
            'path' => '/tmp/cache',
            'pools' => [
                'redis' => [
                    'driver' => 'redis',
                    'serializer' => 'php',
                    'default_ttl_seconds' => 3600,
                    'critical' => true,
                    'encrypted' => false,
                    'tags_strategy' => 'strict',
                    'host' => '127.0.0.1',
                    'port' => 6379,
                    'path' => null,
                ],
            ],
        ];

        $config = CacheConfig::fromArray($data, $this->environment);

        self::assertTrue($config->enabled);
        self::assertSame('redis', $config->defaultPool);
        self::assertSame('/tmp/cache', $config->path);
        self::assertArrayHasKey('redis', $config->pools);

        $pool = $config->pools['redis'];
        self::assertSame('redis', $pool->name);
        self::assertSame(CacheDriverType::Redis, $pool->driver);
        self::assertSame('php', $pool->serializer);
        self::assertSame(3600, $pool->defaultTtlSeconds);
        self::assertTrue($pool->critical);
        self::assertFalse($pool->encrypted);
        self::assertSame('strict', $pool->tagsStrategy);
        self::assertSame('127.0.0.1', $pool->host);
        self::assertSame(6379, $pool->port);
    }

    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = CacheConfig::fromArray([], $this->environment);

        self::assertFalse($config->enabled);
        self::assertSame('default', $config->defaultPool);
        self::assertSame('var/cache', $config->path);
        self::assertArrayHasKey('default', $config->pools);

        $defaultPool = $config->pools['default'];
        self::assertSame('default', $defaultPool->name);
        self::assertSame(CacheDriverType::Filesystem, $defaultPool->driver);
    }

    #[Test]
    public function environmentVariableOverridesCacheEnabled(): void
    {
        putenv('CACHE_ENABLED=true');
        $environment = Environment::load();

        $config = CacheConfig::fromArray([
            'enabled' => false,
        ], $environment);

        self::assertTrue($config->enabled);
    }

    #[Test]
    public function environmentVariableOverridesCacheEnabledToFalse(): void
    {
        putenv('CACHE_ENABLED=false');
        $environment = Environment::load();

        $config = CacheConfig::fromArray([
            'enabled' => true,
        ], $environment);

        self::assertFalse($config->enabled);
    }

    #[Test]
    public function environmentVariableOverridesDefaultPool(): void
    {
        putenv('CACHE_DEFAULT_POOL=redis');
        $environment = Environment::load();

        $config = CacheConfig::fromArray([
            'default_pool' => 'file',
        ], $environment);

        self::assertSame('redis', $config->defaultPool);
    }

    #[Test]
    public function environmentVariableOverridesCachePath(): void
    {
        putenv('CACHE_PATH=/custom/cache');
        $environment = Environment::load();

        $config = CacheConfig::fromArray([
            'path' => '/original/cache',
        ], $environment);

        self::assertSame('/custom/cache', $config->path);
    }

    #[Test]
    public function anUnknownDriverThrowsInsteadOfSilentlyFallingBackToFilesystem(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('unknown cache driver "redys"');

        (void) CacheConfig::fromArray([
            'pools' => [
                'sessions' => ['driver' => 'redys'],
            ],
        ], $this->environment);
    }

    #[Test]
    public function unknownTopLevelAndPoolKeysAreCollectedForWarning(): void
    {
        $config = CacheConfig::fromArray([
            'enabled' => true,
            'typo_top' => 'x',
            'pools' => [
                'sessions' => [
                    'driver' => 'filesystem',
                    'tlt' => 3600,
                ],
            ],
        ], $this->environment);

        self::assertContains('cache.typo_top', $config->unknownKeys);
        self::assertContains('cache.pools.sessions.tlt', $config->unknownKeys);
    }

    #[Test]
    public function aFullyKnownConfigurationHasNoUnknownKeys(): void
    {
        $config = CacheConfig::fromArray([
            'enabled' => true,
            'default_pool' => 'main',
            'path' => 'var/cache',
            'pools' => [
                'main' => [
                    'driver' => 'filesystem',
                    'default_ttl_seconds' => 60,
                    'stampede_protection' => false,
                    'gc_divisor' => 0,
                ],
            ],
        ], $this->environment);

        self::assertSame([], $config->unknownKeys);
    }

    #[Test]
    public function anUnknownSerializerThrowsInsteadOfSilentlyBecomingJson(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('unknown cache serializer "igbinry"');

        (void) CacheConfig::fromArray([
            'pools' => [
                'objects' => ['serializer' => 'igbinry'],
            ],
        ], $this->environment);
    }

    #[Test]
    public function theIgbinarySerializerRequiresTheExtensionAtBoot(): void
    {
        if (extension_loaded('igbinary')) {
            $config = CacheConfig::fromArray([
                'pools' => ['fast' => ['serializer' => 'igbinary']],
            ], $this->environment);

            self::assertSame('igbinary', $config->pools['fast']->serializer);

            return;
        }

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('requires ext-igbinary');

        (void) CacheConfig::fromArray([
            'pools' => ['fast' => ['serializer' => 'igbinary']],
        ], $this->environment);
    }

    #[Test]
    public function anUnknownCompressionValueThrows(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('unknown compression "gzip"');

        (void) CacheConfig::fromArray([
            'pools' => ['pages' => ['compression' => 'gzip']],
        ], $this->environment);
    }

    #[Test]
    public function compressionFalseAndAutoAreAccepted(): void
    {
        $config = CacheConfig::fromArray([
            'pools' => [
                'off' => ['compression' => false],
                'nego' => ['compression' => 'auto'],
                'zlib' => ['compression' => 'zlib'],
            ],
        ], $this->environment);

        self::assertNull($config->pools['off']->compression);
        self::assertSame('auto', $config->pools['nego']->compression);
        self::assertSame('zlib', $config->pools['zlib']->compression);
    }

    #[Test]
    public function compressingAnEncryptedPoolRequiresExplicitOracleAcknowledgement(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('CRIME-class oracle');

        (void) CacheConfig::fromArray([
            'pools' => [
                'secure' => ['encrypted' => true, 'compression' => 'zlib'],
            ],
        ], $this->environment);
    }

    #[Test]
    public function acknowledgedCompressionOnAnEncryptedPoolIsAccepted(): void
    {
        $config = CacheConfig::fromArray([
            'pools' => [
                'secure' => [
                    'encrypted' => true,
                    'compression' => 'zlib',
                    'compression_length_oracle_acknowledged' => true,
                ],
            ],
        ], $this->environment);

        self::assertSame('zlib', $config->pools['secure']->compression);
        self::assertTrue($config->pools['secure']->compressionLengthOracleAcknowledged);
    }

    #[Test]
    public function anAbsentDriverStillDefaultsToFilesystem(): void
    {
        $config = CacheConfig::fromArray([
            'pools' => [
                'files' => ['critical' => true],
            ],
        ], $this->environment);

        self::assertSame(CacheDriverType::Filesystem, $config->pools['files']->driver);
    }

    #[Test]
    public function defaultPoolAutoCreatedIfNotInPools(): void
    {
        $config = CacheConfig::fromArray([
            'default_pool' => 'custom',
            'pools' => [],
        ], $this->environment);

        self::assertSame('custom', $config->defaultPool);
        self::assertArrayHasKey('custom', $config->pools);
        self::assertSame('custom', $config->pools['custom']->name);
        self::assertSame(CacheDriverType::Filesystem, $config->pools['custom']->driver);
    }

    #[Test]
    public function buildPoolConfigCreatesCachePoolConfigCorrectly(): void
    {
        $data = [
            'pools' => [
                'memcached' => [
                    'driver' => 'memcached',
                    'serializer' => 'json',
                    'default_ttl_seconds' => 600,
                    'critical' => false,
                    'encrypted' => true,
                    'tags_strategy' => 'best_effort',
                    'host' => 'localhost',
                    'port' => 11211,
                    'path' => null,
                ],
            ],
        ];

        $config = CacheConfig::fromArray($data, $this->environment);

        self::assertArrayHasKey('memcached', $config->pools);

        $pool = $config->pools['memcached'];
        self::assertInstanceOf(CachePoolConfig::class, $pool);
        self::assertSame('memcached', $pool->name);
        self::assertSame(CacheDriverType::Memcached, $pool->driver);
        self::assertSame('json', $pool->serializer);
        self::assertSame(600, $pool->defaultTtlSeconds);
        self::assertFalse($pool->critical);
        self::assertTrue($pool->encrypted);
        self::assertSame('best_effort', $pool->tagsStrategy);
        self::assertSame('localhost', $pool->host);
        self::assertSame(11211, $pool->port);
    }

    #[Test]
    public function cacheEnabledWithNonBooleanStringResultsInFalse(): void
    {
        putenv('CACHE_ENABLED=yes');
        $environment = Environment::load();

        $config = CacheConfig::fromArray([
            'enabled' => true,
        ], $environment);

        self::assertFalse($config->enabled);
    }
}
