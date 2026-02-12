<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application;

use DateInterval;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\CachePool;
use Pulsar\Cache\Application\Driver\ArrayDriver;
use Pulsar\Cache\Application\Event\CacheEventEmitter;
use Pulsar\Cache\Application\Serializer\JsonCacheSerializer;
use Pulsar\Cache\Application\SimpleCache;

#[CoversClass(SimpleCache::class)]
final class SimpleCacheTest extends TestCase
{
    private SimpleCache $cache;

    protected function setUp(): void
    {
        $pool = new CachePool(
            poolName: 'test',
            driver: new ArrayDriver(),
            serializer: new JsonCacheSerializer(),
            eventEmitter: new CacheEventEmitter(),
        );

        $this->cache = new SimpleCache($pool);
    }

    #[Test]
    public function getReturnsDefaultForNonExistentKey(): void
    {
        self::assertSame('fallback', $this->cache->get('missing', 'fallback'));
    }

    #[Test]
    public function setAndGetRoundTrip(): void
    {
        $this->cache->set('key', 'value');

        self::assertSame('value', $this->cache->get('key'));
    }

    #[Test]
    public function deleteRemovesItem(): void
    {
        $this->cache->set('key', 'value');
        $this->cache->delete('key');

        self::assertNull($this->cache->get('key'));
    }

    #[Test]
    public function clearRemovesAll(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');

        $result = $this->cache->clear();

        self::assertTrue($result);
        self::assertNull($this->cache->get('key1'));
        self::assertNull($this->cache->get('key2'));
    }

    #[Test]
    public function hasReturnsTrueForExistingKey(): void
    {
        $this->cache->set('exists', 'data');

        self::assertTrue($this->cache->has('exists'));
    }

    #[Test]
    public function hasReturnsFalseForNonExistingKey(): void
    {
        self::assertFalse($this->cache->has('nope'));
    }

    #[Test]
    public function getMultipleReturnsValuesAndDefaults(): void
    {
        $this->cache->set('a', 'alpha');
        $this->cache->set('b', 'beta');

        /** @var array<string, mixed> $result */
        $result = $this->cache->getMultiple(['a', 'b', 'c'], 'default');

        self::assertSame('alpha', $result['a']);
        self::assertSame('beta', $result['b']);
        self::assertSame('default', $result['c']);
    }

    #[Test]
    public function setMultipleStoresAllValues(): void
    {
        $result = $this->cache->setMultiple(['x' => 'ex', 'y' => 'why']);

        self::assertTrue($result);
        self::assertSame('ex', $this->cache->get('x'));
        self::assertSame('why', $this->cache->get('y'));
    }

    #[Test]
    public function deleteMultipleRemovesAllKeys(): void
    {
        $this->cache->set('a', 'alpha');
        $this->cache->set('b', 'beta');

        $result = $this->cache->deleteMultiple(['a', 'b']);

        self::assertTrue($result);
        self::assertNull($this->cache->get('a'));
        self::assertNull($this->cache->get('b'));
    }

    #[Test]
    public function rememberReturnsCachedValueOnSecondCall(): void
    {
        $callCount = 0;
        $callback = static function () use (&$callCount): string {
            $callCount++;

            return 'computed';
        };

        $first = $this->cache->remember('key', $callback);
        $second = $this->cache->remember('key', $callback);

        self::assertSame('computed', $first);
        self::assertSame('computed', $second);
        self::assertSame(1, $callCount);
    }

    #[Test]
    public function rememberWithIntTtlStoresValue(): void
    {
        $result = $this->cache->remember('ttl-key', static fn(): string => 'ttl-value', 3600);

        self::assertSame('ttl-value', $result);
        self::assertSame('ttl-value', $this->cache->get('ttl-key'));
    }

    #[Test]
    public function rememberWithDateIntervalTtlStoresValue(): void
    {
        $interval = new DateInterval('PT1H');

        $result = $this->cache->remember('interval-key', static fn(): string => 'interval-value', $interval);

        self::assertSame('interval-value', $result);
        self::assertSame('interval-value', $this->cache->get('interval-key'));
    }

    #[Test]
    public function setWithIntTtlStoresValue(): void
    {
        $this->cache->set('int-ttl', 'value', 3600);

        self::assertSame('value', $this->cache->get('int-ttl'));
    }

    #[Test]
    public function setWithDateIntervalTtlStoresValue(): void
    {
        $interval = new DateInterval('PT1H');
        $this->cache->set('interval-ttl', 'value', $interval);

        self::assertSame('value', $this->cache->get('interval-ttl'));
    }
}
