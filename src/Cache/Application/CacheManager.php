<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application;

use Memcached;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Api\Api;
use Pulsar\Cache\Application\Driver\ApcuDriver;
use Pulsar\Cache\Application\Driver\ArrayDriver;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use Pulsar\Cache\Application\Driver\DatabaseDriver;
use Pulsar\Cache\Application\Driver\FilesystemDriver;
use Pulsar\Cache\Application\Driver\MemcachedDriver;
use Pulsar\Cache\Application\Driver\RedisDriver;
use Pulsar\Cache\Application\Encryption\EncryptedCacheDecorator;
use Pulsar\Cache\Application\Event\CacheEventEmitter;
use Pulsar\Cache\Application\Exception\CacheException;
use Pulsar\Cache\Application\Exception\UnsupportedCapabilityException;
use Pulsar\Cache\Application\Lock\ApcuLock;
use Pulsar\Cache\Application\Lock\ArrayLock;
use Pulsar\Cache\Application\Lock\DatabaseLock;
use Pulsar\Cache\Application\Lock\FilesystemLock;
use Pulsar\Cache\Application\Lock\LockInterface;
use Pulsar\Cache\Application\Lock\MemcachedLock;
use Pulsar\Cache\Application\Lock\RedisLock;
use Pulsar\Cache\Application\Serializer\CacheSerializerInterface;
use Pulsar\Cache\Application\Serializer\JsonCacheSerializer;
use Pulsar\Cache\Application\Serializer\PhpCacheSerializer;
use Pulsar\Cache\Application\Tag\BestEffortTagStrategy;
use Pulsar\Cache\Application\Tag\StrictTagStrategy;
use Pulsar\Cache\Application\Tag\TagStrategyInterface;
use Pulsar\Config\CacheConfig;
use Pulsar\Config\CacheDriverType;
use Pulsar\Config\CachePoolConfig;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Security\Crypto\Hmac;
use Pulsar\Security\Crypto\MasterKey;
use Redis;

/**
 * Application cache manager with lazy pool/driver resolution.
 * @api
 */
#[Api(since: '1.0.0')]
final class CacheManager implements CacheManagerInterface
{
    /** @var array<string, CacheDriverInterface> */
    private array $drivers = [];

    /** @var array<string, CachePool> */
    private array $pools = [];

    /** @var array<string, SimpleCache> */
    private array $simpleCaches = [];

    /** @var array<string, TaggedCache> */
    private array $taggedCaches = [];

    /** @var array<string, LockInterface> */
    private array $locks = [];

    /** @var array<string, Redis|Memcached> */
    private array $connections = [];

    private readonly CacheEventEmitter $eventEmitter;

    public function __construct(
        private readonly CacheConfig $config,
        private readonly ?ConnectionInterface $connection = null,
        private readonly ?MasterKey $masterKey = null,
        ?MetricRegistry $metrics = null,
        ?LoggerInterface $logger = null,
    ) {
        $masterKey = $this->masterKey;
        // computeHex(message, key): the cache key is the MESSAGE (arbitrary
        // length) and the 32-byte derived sub-key is the KEY. Passing them the
        // other way round made the variable-length cache key the HMAC key, which
        // sodium_crypto_generichash rejects above 64 bytes — so any cache key
        // longer than 64 bytes (well under the 250-char validator limit) threw.
        $keyHasher = $masterKey !== null
            ? fn(string $key): string => Hmac::computeHex($key, $masterKey->deriveSubKey(9, 'app_cobs'))
            : null;

        $this->eventEmitter = new CacheEventEmitter($metrics, $logger, $keyHasher);
    }

    /**
     * @throws CacheException If the pool is not configured
     */
    public function pool(?string $name = null): CacheItemPoolInterface
    {
        $name ??= $this->config->defaultPool;

        if (!isset($this->pools[$name])) {
            $poolConfig = $this->resolvePoolConfig($name);
            $driver = $this->driver($name);
            $serializer = $this->resolveSerializer($poolConfig);

            // Stampede protection reuses the pool's own lock backend (ADR-0018).
            // Disabled per pool via stampede_protection => false, in which case
            // remember() stays a plain get-or-compute.
            $stampedeLock = $poolConfig->stampedeProtection ? $this->lock($name) : null;

            $this->pools[$name] = new CachePool(
                poolName: $name,
                driver: $driver,
                serializer: $serializer,
                eventEmitter: $this->eventEmitter,
                defaultTtlSeconds: $poolConfig->defaultTtlSeconds,
                critical: $poolConfig->critical,
                stampedeLock: $stampedeLock,
            );
        }

        return $this->pools[$name];
    }

    /**
     * @throws CacheException If the pool is not configured
     */
    public function simple(?string $name = null): CacheInterface
    {
        $name ??= $this->config->defaultPool;

        if (!isset($this->simpleCaches[$name])) {
            $pool = $this->pool($name);
            /** @var CachePool $pool */
            $this->simpleCaches[$name] = new SimpleCache($pool);
        }

        return $this->simpleCaches[$name];
    }

    /**
     * @throws CacheException If the pool is not configured
     * @throws UnsupportedCapabilityException If strict tags are requested but unsupported
     */
    public function tagged(?string $name = null): TaggedCacheInterface
    {
        $name ??= $this->config->defaultPool;

        if (!isset($this->taggedCaches[$name])) {
            $poolConfig = $this->resolvePoolConfig($name);
            $driver = $this->driver($name);
            $serializer = $this->resolveSerializer($poolConfig);
            $tagStrategy = $this->resolveTagStrategy($name, $driver, $poolConfig);

            $this->taggedCaches[$name] = new TaggedCache(
                poolName: $name,
                driver: $driver,
                serializer: $serializer,
                tagStrategy: $tagStrategy,
                eventEmitter: $this->eventEmitter,
                defaultTtlSeconds: $poolConfig->defaultTtlSeconds,
                critical: $poolConfig->critical,
            );
        }

        return $this->taggedCaches[$name];
    }

    /**
     * Register a listener invoked for every cache hit/miss/operation event
     * (e.g. to feed the request profiler).
     *
     * @param callable(\Pulsar\Cache\Application\Event\CacheEvent): void $listener
     */
    public function addEventListener(callable $listener): void
    {
        $this->eventEmitter->addListener($listener);
    }

    /**
     * @throws CacheException If the pool is not configured or lock resolution fails
     */
    public function lock(?string $name = null): LockInterface
    {
        $name ??= $this->config->defaultPool;

        if (!isset($this->locks[$name])) {
            $poolConfig = $this->resolvePoolConfig($name);
            $this->locks[$name] = $this->resolveLock($poolConfig);
        }

        return $this->locks[$name];
    }

    /**
     * @throws CacheException If the pool is not configured or encryption is unavailable
     */
    public function driver(?string $name = null): CacheDriverInterface
    {
        $name ??= $this->config->defaultPool;

        if (!isset($this->drivers[$name])) {
            $poolConfig = $this->resolvePoolConfig($name);
            $driver = $this->resolveDriver($poolConfig);

            if ($poolConfig->encrypted) {
                if ($this->masterKey === null) {
                    throw CacheException::encryptionUnavailable($name);
                }

                $driver = new EncryptedCacheDecorator(
                    inner: $driver,
                    masterKey: $this->masterKey,
                    poolName: $name,
                );
            }

            $this->drivers[$name] = $driver;
        }

        return $this->drivers[$name];
    }

    private function resolvePoolConfig(string $name): CachePoolConfig
    {
        if (!isset($this->config->pools[$name])) {
            throw CacheException::poolNotConfigured($name);
        }

        return $this->config->pools[$name];
    }

    private function resolveDriver(CachePoolConfig $poolConfig): CacheDriverInterface
    {
        return match ($poolConfig->driver) {
            CacheDriverType::Array => new ArrayDriver(),
            CacheDriverType::Filesystem => new FilesystemDriver(
                $this->filesystemCachePath($poolConfig),
                $poolConfig->gcDivisor,
            ),
            CacheDriverType::Database => $this->connection !== null
                ? new DatabaseDriver($this->connection, $poolConfig->name)
                : throw CacheException::driverError('database', 'No database connection available'),
            CacheDriverType::Redis => new RedisDriver($this->resolveRedisConnection($poolConfig)),
            CacheDriverType::Memcached => new MemcachedDriver($this->resolveMemcachedConnection($poolConfig)),
            CacheDriverType::Apcu => new ApcuDriver(),
        };
    }

    /**
     * Resolve the configured filesystem cache directory to an absolute path so
     * the pool driver and its lock share one stable location regardless of the
     * process CWD (a relative default like `var/cache` would otherwise land
     * wherever the worker happened to be started).
     */
    private function filesystemCachePath(CachePoolConfig $poolConfig): string
    {
        return resolve_path($poolConfig->path ?? $this->config->path);
    }

    private function resolveRedisConnection(CachePoolConfig $poolConfig): Redis
    {
        $connectionKey = 'redis:' . ($poolConfig->host ?? '127.0.0.1') . ':' . ($poolConfig->port ?? 6379);

        if (!isset($this->connections[$connectionKey])) {
            $redis = new Redis();
            $redis->connect(
                $poolConfig->host ?? '127.0.0.1',
                $poolConfig->port ?? 6379,
            );
            $this->connections[$connectionKey] = $redis;
        }

        /** @var Redis */
        return $this->connections[$connectionKey];
    }

    private function resolveMemcachedConnection(CachePoolConfig $poolConfig): Memcached
    {
        $connectionKey = 'memcached:' . ($poolConfig->host ?? '127.0.0.1') . ':' . ($poolConfig->port ?? 11211);

        if (!isset($this->connections[$connectionKey])) {
            $memcached = new Memcached();
            $memcached->addServer(
                $poolConfig->host ?? '127.0.0.1',
                $poolConfig->port ?? 11211,
            );
            $this->connections[$connectionKey] = $memcached;
        }

        /** @var Memcached */
        return $this->connections[$connectionKey];
    }

    private function resolveSerializer(CachePoolConfig $poolConfig): CacheSerializerInterface
    {
        return match ($poolConfig->serializer) {
            'php' => new PhpCacheSerializer($poolConfig->allowedClasses ?? []),
            default => new JsonCacheSerializer(),
        };
    }

    private function resolveTagStrategy(string $poolName, CacheDriverInterface $driver, CachePoolConfig $poolConfig): TagStrategyInterface
    {
        $strategy = $poolConfig->tagsStrategy;
        $capabilities = $driver->capabilities();

        if ($strategy === 'strict' || ($strategy === 'auto' && $capabilities->supportsAtomicIncrement)) {
            if (!$capabilities->supportsTagsStrict && $strategy === 'strict') {
                throw UnsupportedCapabilityException::strictTagsUnsupported($driver->name());
            }

            return new StrictTagStrategy($driver);
        }

        return new BestEffortTagStrategy($driver);
    }

    private function resolveLock(CachePoolConfig $poolConfig): LockInterface
    {
        return match ($poolConfig->driver) {
            CacheDriverType::Array => new ArrayLock(),
            CacheDriverType::Filesystem => new FilesystemLock(
                $this->filesystemCachePath($poolConfig),
            ),
            CacheDriverType::Database => $this->connection !== null
                ? new DatabaseLock($this->connection)
                : throw CacheException::databaseConnectionRequired('database'),
            CacheDriverType::Redis => new RedisLock($this->resolveRedisConnection($poolConfig)),
            CacheDriverType::Memcached => new MemcachedLock($this->resolveMemcachedConnection($poolConfig)),
            CacheDriverType::Apcu => new ApcuLock(),
        };
    }
}
