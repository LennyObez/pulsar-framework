<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Cache\Application\CacheManager;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use Pulsar\Cache\Application\Encryption\EncryptedCacheDecorator;
use Pulsar\Cache\Application\Event\CacheEvent;
use Pulsar\Cache\Application\Event\CacheHitEvent;
use Pulsar\Cache\Application\Event\CacheMissEvent;
use Pulsar\Cache\Application\Exception\CacheException;
use Pulsar\Cache\Application\Lock\FilesystemLock;
use Pulsar\Cache\Application\Lock\LockInterface;
use Pulsar\Cache\Application\Serializer\CacheSerializerInterface;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Config\CacheConfig;
use Pulsar\Config\CacheDriverType;
use Pulsar\Config\CachePoolConfig;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Security\Crypto\MasterKey;
use ReflectionMethod;

use function array_map;

#[CoversClass(CacheManager::class)]
final class CacheManagerTest extends TestCase
{
    private CacheManager $manager;

    protected function setUp(): void
    {
        $config = new CacheConfig(
            enabled: true,
            defaultPool: 'default',
            path: sys_get_temp_dir() . '/pulsar_cache_test',
            pools: [
                'default' => new CachePoolConfig(
                    name: 'default',
                    driver: CacheDriverType::Array,
                ),
                'secondary' => new CachePoolConfig(
                    name: 'secondary',
                    driver: CacheDriverType::Array,
                ),
            ],
        );

        $this->manager = new CacheManager($config);
    }

    #[Test]
    public function poolReturnsCacheItemPoolInterface(): void
    {
        $pool = $this->manager->pool('default');

        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
    }

    #[Test]
    public function resetRequestStateClearsTagVersionMemosOfCreatedStrategies(): void
    {
        // BestEffortTagStrategy memoizes tag versions per request (forced here:
        // 'auto' on the array driver would pick the memo-less strict strategy).
        // On persistent runtimes the manager is a long-lived singleton, so its
        // reset must propagate to strategies or a worker would never see other
        // workers' tag invalidations.
        $manager = new CacheManager(new CacheConfig(
            enabled: true,
            defaultPool: 'default',
            path: sys_get_temp_dir() . '/pulsar_cache_test',
            pools: [
                'default' => new CachePoolConfig(
                    name: 'default',
                    driver: CacheDriverType::Array,
                    tagsStrategy: 'best_effort',
                ),
            ],
        ));

        $tagged = $manager->tagged('default');
        $tagged->set('key-1', 'v', ['tag-x'], 60);

        self::assertSame('v', $tagged->get('key-1'));

        // Bump the tag version behind the memo's back, as another worker would
        // (tag-version keys live below the validator, directly on the driver).
        $manager->driver('default')->set('_tag:tag-x:ver', 'other-worker-version', null);

        self::assertSame('v', $tagged->get('key-1'), 'Memoized version still answers within the request');

        $manager->resetRequestState();

        self::assertNull($tagged->get('key-1'), 'After reset the foreign invalidation must be observed');
    }

    #[Test]
    public function addEventListenerReceivesHitAndMissEvents(): void
    {
        /** @var list<CacheEvent> $events */
        $events = [];
        $this->manager->addEventListener(static function (CacheEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $pool = $this->manager->pool('default');

        // Absent key → miss; after a save the next read is a hit.
        $item = $pool->getItem('profiler.probe');
        $item->set('value');
        $pool->save($item);
        $pool->getItem('profiler.probe');

        $types = array_map(static fn(CacheEvent $event): string => $event::class, $events);
        self::assertContains(CacheMissEvent::class, $types);
        self::assertContains(CacheHitEvent::class, $types);
    }

    #[Test]
    public function phpSerializerReceivesConfiguredAllowedClasses(): void
    {
        // A pool configured serializer='php' with an allowedClasses list must
        // yield a PhpCacheSerializer that carries the list through. If the list
        // is dropped, every object decodes to __PHP_Incomplete_Class and
        // deserialize() throws a CacheException.
        $poolConfig = new CachePoolConfig(
            name: 'objects',
            serializer: 'php',
            allowedClasses: [CacheDtoFixture::class],
        );

        $resolve = new ReflectionMethod($this->manager, 'resolveSerializer');
        $serializer = $resolve->invoke($this->manager, $poolConfig);
        self::assertInstanceOf(CacheSerializerInterface::class, $serializer);

        $restored = $serializer->deserialize($serializer->serialize(new CacheDtoFixture('hello')));

        self::assertInstanceOf(CacheDtoFixture::class, $restored);
        self::assertSame('hello', $restored->value);
    }

    #[Test]
    public function simpleReturnsCacheInterface(): void
    {
        $simple = $this->manager->simple('default');

        self::assertInstanceOf(CacheInterface::class, $simple);
    }

    #[Test]
    public function taggedReturnsTaggedCacheInterface(): void
    {
        $tagged = $this->manager->tagged('default');

        self::assertInstanceOf(TaggedCacheInterface::class, $tagged);
    }

    #[Test]
    public function lockReturnsLockInterface(): void
    {
        $lock = $this->manager->lock('default');

        self::assertInstanceOf(LockInterface::class, $lock);
    }

    /**
     * Regression: the event emitter's key-hasher hashes every cache key with a
     * master-key-derived sub-key. The HMAC arguments were swapped, so the cache
     * KEY was used as the HMAC key — which sodium rejects above 64 bytes. A key
     * of 200 chars (well under the 250-char validator limit) therefore 500'd as
     * soon as a master key was configured.
     */
    #[Test]
    public function longCacheKeyWithMasterKeyConfiguredDoesNotThrow(): void
    {
        $config = new CacheConfig(
            enabled: true,
            defaultPool: 'default',
            path: sys_get_temp_dir() . '/pulsar_cache_hmac_test',
            pools: ['default' => new CachePoolConfig(name: 'default', driver: CacheDriverType::Array)],
        );
        $manager = new CacheManager($config, masterKey: MasterKey::fromHex(str_repeat('ab', 32)));

        $key = str_repeat('k', 200);
        $cache = $manager->simple('default');
        $cache->set($key, 'value');

        self::assertSame('value', $cache->get($key));
    }

    #[Test]
    public function driverReturnsCacheDriverInterface(): void
    {
        $driver = $this->manager->driver('default');

        self::assertInstanceOf(CacheDriverInterface::class, $driver);
        self::assertSame('array', $driver->name());
    }

    #[Test]
    public function poolThrowsForUnconfiguredPool(): void
    {
        $this->expectException(CacheException::class);

        $this->manager->pool('nonexistent');
    }

    #[Test]
    public function poolReturnsDefaultWhenNameIsNull(): void
    {
        $pool = $this->manager->pool();

        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
    }

    #[Test]
    public function poolReturnsSameInstanceOnSubsequentCalls(): void
    {
        $pool1 = $this->manager->pool('default');
        $pool2 = $this->manager->pool('default');

        self::assertSame($pool1, $pool2);
    }

    #[Test]
    public function simpleReturnsSameInstanceOnSubsequentCalls(): void
    {
        $simple1 = $this->manager->simple('default');
        $simple2 = $this->manager->simple('default');

        self::assertSame($simple1, $simple2);
    }

    #[Test]
    public function taggedReturnsSameInstanceOnSubsequentCalls(): void
    {
        $tagged1 = $this->manager->tagged('default');
        $tagged2 = $this->manager->tagged('default');

        self::assertSame($tagged1, $tagged2);
    }

    #[Test]
    public function driverWithEncryptedPoolWrapsInEncryptedCacheDecorator(): void
    {
        $config = new CacheConfig(
            enabled: true,
            defaultPool: 'encrypted',
            path: sys_get_temp_dir() . '/pulsar_cache_test',
            pools: [
                'encrypted' => new CachePoolConfig(
                    name: 'encrypted',
                    driver: CacheDriverType::Array,
                    encrypted: true,
                ),
            ],
        );

        $masterKey = MasterKey::fromHex(str_repeat('ab', 32));
        $manager = new CacheManager($config, masterKey: $masterKey);

        $driver = $manager->driver('encrypted');

        self::assertInstanceOf(EncryptedCacheDecorator::class, $driver);
    }

    #[Test]
    public function encryptedPoolWithNullMasterKeyThrowsCacheException(): void
    {
        $config = new CacheConfig(
            enabled: true,
            defaultPool: 'encrypted',
            path: sys_get_temp_dir() . '/pulsar_cache_test',
            pools: [
                'encrypted' => new CachePoolConfig(
                    name: 'encrypted',
                    driver: CacheDriverType::Array,
                    encrypted: true,
                ),
            ],
        );

        $manager = new CacheManager($config);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessageIsOrContains('encryption');
        $manager->driver('encrypted');
    }

    #[Test]
    public function lockReturnsSameInstanceOnSubsequentCalls(): void
    {
        $manager = $this->createManager();

        $lock1 = $manager->lock('default');
        $lock2 = $manager->lock('default');

        self::assertSame($lock1, $lock2);
    }

    #[Test]
    public function lockReturnsDefaultWhenNameIsNull(): void
    {
        $manager = $this->createManager();

        $lock = $manager->lock();

        self::assertInstanceOf(LockInterface::class, $lock);
    }

    #[Test]
    public function lockThrowsForUnconfiguredPool(): void
    {
        $manager = $this->createManager();

        $this->expectException(CacheException::class);
        $manager->lock('nonexistent');
    }

    #[Test]
    public function simpleThrowsForUnconfiguredPool(): void
    {
        $manager = $this->createManager();

        $this->expectException(CacheException::class);
        $manager->simple('nonexistent');
    }

    #[Test]
    public function taggedThrowsForUnconfiguredPool(): void
    {
        $manager = $this->createManager();

        $this->expectException(CacheException::class);
        $manager->tagged('nonexistent');
    }

    #[Test]
    public function driverReturnsSameInstanceOnSubsequentCalls(): void
    {
        $manager = $this->createManager();

        $driver1 = $manager->driver('default');
        $driver2 = $manager->driver('default');

        self::assertSame($driver1, $driver2);
    }

    #[Test]
    public function driverReturnsDefaultWhenNameIsNull(): void
    {
        $manager = $this->createManager();

        $driver = $manager->driver();

        self::assertSame('array', $driver->name());
    }

    #[Test]
    public function simpleReturnsDefaultWhenNameIsNull(): void
    {
        $manager = $this->createManager();

        $simple = $manager->simple();

        self::assertInstanceOf(CacheInterface::class, $simple);
    }

    #[Test]
    public function taggedReturnsDefaultWhenNameIsNull(): void
    {
        $manager = $this->createManager();

        $tagged = $manager->tagged();

        self::assertInstanceOf(TaggedCacheInterface::class, $tagged);
    }

    #[Test]
    public function filesystemDriverCreatesFilesystemLock(): void
    {
        $config = new CacheConfig(
            enabled: true,
            defaultPool: 'fs',
            path: sys_get_temp_dir() . '/pulsar_cache_mgr_test',
            pools: [
                'fs' => new CachePoolConfig(
                    name: 'fs',
                    driver: CacheDriverType::Filesystem,
                ),
            ],
        );

        $manager = new CacheManager($config);
        $lock = $manager->lock('fs');

        self::assertInstanceOf(FilesystemLock::class, $lock);
    }

    #[Test]
    public function databaseDriverThrowsWithoutConnection(): void
    {
        $config = new CacheConfig(
            enabled: true,
            defaultPool: 'db',
            path: sys_get_temp_dir(),
            pools: [
                'db' => new CachePoolConfig(
                    name: 'db',
                    driver: CacheDriverType::Database,
                ),
            ],
        );

        $manager = new CacheManager($config);

        $this->expectException(CacheException::class);
        $manager->driver('db');
    }

    #[Test]
    public function databaseLockThrowsWithoutConnection(): void
    {
        $config = new CacheConfig(
            enabled: true,
            defaultPool: 'db',
            path: sys_get_temp_dir(),
            pools: [
                'db' => new CachePoolConfig(
                    name: 'db',
                    driver: CacheDriverType::Database,
                ),
            ],
        );

        $manager = new CacheManager($config);

        $this->expectException(CacheException::class);
        $manager->lock('db');
    }

    #[Test]
    public function phpSerializerUsedWhenConfigured(): void
    {
        $config = new CacheConfig(
            enabled: true,
            defaultPool: 'php',
            path: sys_get_temp_dir(),
            pools: [
                'php' => new CachePoolConfig(
                    name: 'php',
                    driver: CacheDriverType::Array,
                    serializer: 'php',
                ),
            ],
        );

        $manager = new CacheManager($config);
        $pool = $manager->pool('php');

        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
    }

    #[Test]
    public function constructorWithMetricsAndLogger(): void
    {
        $config = new CacheConfig(
            enabled: true,
            defaultPool: 'default',
            path: sys_get_temp_dir(),
            pools: [
                'default' => new CachePoolConfig(
                    name: 'default',
                    driver: CacheDriverType::Array,
                ),
            ],
        );

        $metrics = new MetricRegistry();
        $logger = $this->createStub(LoggerInterface::class);

        $manager = new CacheManager($config, metrics: $metrics, logger: $logger);

        self::assertInstanceOf(CacheItemPoolInterface::class, $manager->pool());
    }

    #[Test]
    public function constructorWithMasterKeyCreatesKeyHasher(): void
    {
        $config = new CacheConfig(
            enabled: true,
            defaultPool: 'default',
            path: sys_get_temp_dir(),
            pools: [
                'default' => new CachePoolConfig(
                    name: 'default',
                    driver: CacheDriverType::Array,
                ),
            ],
        );

        $masterKey = MasterKey::fromHex(str_repeat('ab', 32));
        $manager = new CacheManager($config, masterKey: $masterKey);

        self::assertInstanceOf(CacheItemPoolInterface::class, $manager->pool());
    }

    #[Test]
    public function differentPoolsReturnDifferentInstances(): void
    {
        $config = new CacheConfig(
            enabled: true,
            defaultPool: 'a',
            path: sys_get_temp_dir(),
            pools: [
                'a' => new CachePoolConfig(name: 'a', driver: CacheDriverType::Array),
                'b' => new CachePoolConfig(name: 'b', driver: CacheDriverType::Array),
            ],
        );

        $manager = new CacheManager($config);

        $poolA = $manager->pool('a');
        $poolB = $manager->pool('b');

        self::assertNotSame($poolA, $poolB);
    }

    private function createManager(): CacheManager
    {
        $config = new CacheConfig(
            enabled: true,
            defaultPool: 'default',
            path: sys_get_temp_dir() . '/pulsar_cache_mgr_test',
            pools: [
                'default' => new CachePoolConfig(
                    name: 'default',
                    driver: CacheDriverType::Array,
                ),
            ],
        );

        return new CacheManager($config);
    }
}

/**
 * Simple value object used to verify the 'php' serializer allowlist round-trip.
 *
 * @internal
 */
final class CacheDtoFixture
{
    public function __construct(
        public string $value,
    ) {}
}
