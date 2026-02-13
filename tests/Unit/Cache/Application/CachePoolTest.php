<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Pulsar\Cache\Application\CacheItem;
use Pulsar\Cache\Application\CachePool;
use Pulsar\Cache\Application\Driver\ArrayDriver;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use Pulsar\Cache\Application\Event\CacheEventEmitter;
use Pulsar\Cache\Application\Exception\CacheException;
use Pulsar\Cache\Application\Serializer\JsonCacheSerializer;
use RuntimeException;

#[CoversClass(CachePool::class)]
final class CachePoolTest extends TestCase
{
    private CachePool $pool;

    protected function setUp(): void
    {
        $this->pool = new CachePool(
            poolName: 'test',
            driver: new ArrayDriver(),
            serializer: new JsonCacheSerializer(),
            eventEmitter: new CacheEventEmitter(),
        );
    }

    #[Test]
    public function getItemReturnsMissForNonExistentKey(): void
    {
        $item = $this->pool->getItem('missing');

        self::assertFalse($item->isHit());
        self::assertNull($item->get());
    }

    #[Test]
    public function saveAndGetItemReturnsHit(): void
    {
        $item = $this->pool->getItem('key1');
        $item->set('hello');
        $this->pool->save($item);

        $retrieved = $this->pool->getItem('key1');

        self::assertTrue($retrieved->isHit());
        self::assertSame('hello', $retrieved->get());
    }

    #[Test]
    public function deleteItemRemovesItem(): void
    {
        $item = $this->pool->getItem('key1');
        $item->set('value');
        $this->pool->save($item);

        $this->pool->deleteItem('key1');

        $retrieved = $this->pool->getItem('key1');
        self::assertFalse($retrieved->isHit());
    }

    #[Test]
    public function clearRemovesAllItems(): void
    {
        $item1 = $this->pool->getItem('key1');
        $item1->set('value1');
        $this->pool->save($item1);

        $item2 = $this->pool->getItem('key2');
        $item2->set('value2');
        $this->pool->save($item2);

        $result = $this->pool->clear();

        self::assertTrue($result);
        self::assertFalse($this->pool->getItem('key1')->isHit());
        self::assertFalse($this->pool->getItem('key2')->isHit());
    }

    #[Test]
    public function hasItemReturnsTrueForExistingKey(): void
    {
        $item = $this->pool->getItem('exists');
        $item->set('data');
        $this->pool->save($item);

        self::assertTrue($this->pool->hasItem('exists'));
    }

    #[Test]
    public function hasItemReturnsFalseForNonExistingKey(): void
    {
        self::assertFalse($this->pool->hasItem('nope'));
    }

    #[Test]
    public function saveDeferredAndCommitPersistsItems(): void
    {
        $item = CacheItem::hit('deferred-key', 'deferred-value');
        $this->pool->saveDeferred($item);

        $commitResult = $this->pool->commit();
        self::assertTrue($commitResult);

        // After commit, item persists in driver
        $afterCommit = $this->pool->getItem('deferred-key');
        self::assertTrue($afterCommit->isHit());
        self::assertSame('deferred-value', $afterCommit->get());
    }

    #[Test]
    public function rememberReturnsCachedValueOnSecondCall(): void
    {
        $callCount = 0;
        $callback = static function () use (&$callCount): string {
            $callCount++;

            return 'computed-value';
        };

        $first = $this->pool->remember('remember-key', $callback);
        $second = $this->pool->remember('remember-key', $callback);

        self::assertSame('computed-value', $first);
        self::assertSame('computed-value', $second);
        self::assertSame(1, $callCount);
    }

    #[Test]
    public function criticalModeThrowsCacheExceptionOnDriverError(): void
    {
        $driver = $this->createStub(CacheDriverInterface::class);
        $driver->method('get')->willThrowException(new RuntimeException('disk full'));
        $driver->method('name')->willReturn('failing');

        $criticalPool = new CachePool(
            poolName: 'critical-pool',
            driver: $driver,
            serializer: new JsonCacheSerializer(),
            eventEmitter: new CacheEventEmitter(),
            critical: true,
        );

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('failing');

        $criticalPool->getItem('any-key');
    }

    #[Test]
    public function nonCriticalModeReturnsFalseOnSaveWhenDriverThrows(): void
    {
        $driver = $this->createStub(CacheDriverInterface::class);
        $driver->method('set')->willThrowException(new RuntimeException('disk full'));
        $driver->method('name')->willReturn('failing');

        $pool = new CachePool(
            poolName: 'non-critical',
            driver: $driver,
            serializer: new JsonCacheSerializer(),
            eventEmitter: new CacheEventEmitter(),
            critical: false,
        );

        $item = CacheItem::miss('key1');
        $item->set('value');

        self::assertFalse($pool->save($item));
    }

    #[Test]
    public function nonCriticalModeReturnsFalseOnDeleteItemWhenDriverThrows(): void
    {
        $driver = $this->createStub(CacheDriverInterface::class);
        $driver->method('delete')->willThrowException(new RuntimeException('disk full'));
        $driver->method('name')->willReturn('failing');

        $pool = new CachePool(
            poolName: 'non-critical',
            driver: $driver,
            serializer: new JsonCacheSerializer(),
            eventEmitter: new CacheEventEmitter(),
            critical: false,
        );

        self::assertFalse($pool->deleteItem('key1'));
    }

    #[Test]
    public function nonCriticalModeClearReturnsFalseWhenDriverThrows(): void
    {
        $driver = $this->createStub(CacheDriverInterface::class);
        $driver->method('clear')->willThrowException(new RuntimeException('disk full'));
        $driver->method('name')->willReturn('failing');

        $pool = new CachePool(
            poolName: 'non-critical',
            driver: $driver,
            serializer: new JsonCacheSerializer(),
            eventEmitter: new CacheEventEmitter(),
            critical: false,
        );

        self::assertFalse($pool->clear());
    }

    #[Test]
    public function criticalModeThrowsCacheExceptionOnSaveDriverError(): void
    {
        $driver = $this->createStub(CacheDriverInterface::class);
        $driver->method('set')->willThrowException(new RuntimeException('disk full'));
        $driver->method('name')->willReturn('failing');

        $pool = new CachePool(
            poolName: 'critical',
            driver: $driver,
            serializer: new JsonCacheSerializer(),
            eventEmitter: new CacheEventEmitter(),
            critical: true,
        );

        $item = CacheItem::miss('key1');
        $item->set('value');

        $this->expectException(CacheException::class);
        $pool->save($item);
    }

    #[Test]
    public function criticalModeThrowsCacheExceptionOnDeleteItemDriverError(): void
    {
        $driver = $this->createStub(CacheDriverInterface::class);
        $driver->method('delete')->willThrowException(new RuntimeException('disk full'));
        $driver->method('name')->willReturn('failing');

        $pool = new CachePool(
            poolName: 'critical',
            driver: $driver,
            serializer: new JsonCacheSerializer(),
            eventEmitter: new CacheEventEmitter(),
            critical: true,
        );

        $this->expectException(CacheException::class);
        $pool->deleteItem('key1');
    }

    #[Test]
    public function criticalModeThrowsCacheExceptionOnClearDriverError(): void
    {
        $driver = $this->createStub(CacheDriverInterface::class);
        $driver->method('clear')->willThrowException(new RuntimeException('disk full'));
        $driver->method('name')->willReturn('failing');

        $pool = new CachePool(
            poolName: 'critical',
            driver: $driver,
            serializer: new JsonCacheSerializer(),
            eventEmitter: new CacheEventEmitter(),
            critical: true,
        );

        $this->expectException(CacheException::class);
        $pool->clear();
    }

    #[Test]
    public function getItemsReturnsCorrectItemsForMultipleKeys(): void
    {
        $item1 = CacheItem::miss('key1');
        $item1->set('value1');
        $this->pool->save($item1);

        $item2 = CacheItem::miss('key2');
        $item2->set('value2');
        $this->pool->save($item2);

        /** @var array<string, CacheItemInterface> $items */
        $items = $this->pool->getItems(['key1', 'key2', 'missing']);

        self::assertTrue($items['key1']->isHit());
        self::assertSame('value1', $items['key1']->get());
        self::assertTrue($items['key2']->isHit());
        self::assertSame('value2', $items['key2']->get());
        self::assertFalse($items['missing']->isHit());
    }

    #[Test]
    public function deleteItemsDeletesMultipleKeysAndReturnsBool(): void
    {
        $item1 = CacheItem::miss('key1');
        $item1->set('value1');
        $this->pool->save($item1);

        $item2 = CacheItem::miss('key2');
        $item2->set('value2');
        $this->pool->save($item2);

        $result = $this->pool->deleteItems(['key1', 'key2']);

        self::assertTrue($result);
        self::assertFalse($this->pool->getItem('key1')->isHit());
        self::assertFalse($this->pool->getItem('key2')->isHit());
    }

    #[Test]
    public function saveDeferredReturnsFalseForForeignCacheItemImplementation(): void
    {
        $foreignItem = $this->createStub(CacheItemInterface::class);
        $foreignItem->method('getKey')->willReturn('foreign-key');

        self::assertFalse($this->pool->saveDeferred($foreignItem));
    }

    #[Test]
    public function getItemReturnsDeferredItemClone(): void
    {
        $pool = $this->createPool();

        $item = CacheItem::hit('deferred', 'value1');
        $pool->saveDeferred($item);

        $retrieved = $pool->getItem('deferred');
        self::assertTrue($retrieved->isHit());
        self::assertSame('value1', $retrieved->get());
        $retrieved->set('modified');
        $original = $pool->getItem('deferred');
        self::assertSame('value1', $original->get());
    }

    #[Test]
    public function getItemsReturnsEmptyForEmptyKeys(): void
    {
        $pool = $this->createPool();

        $items = $pool->getItems([]);

        self::assertSame([], $items);
    }

    #[Test]
    public function getItemsReturnsDeferredItemsAndDriverItems(): void
    {
        $pool = $this->createPool();

        $item1 = CacheItem::miss('driver-key');
        $item1->set('driver-val');
        $pool->save($item1);

        $deferred = CacheItem::hit('deferred-key', 'deferred-val');
        $pool->saveDeferred($deferred);

        /** @var array<string, CacheItemInterface> $items */
        $items = $pool->getItems(['driver-key', 'deferred-key', 'missing-key']);

        self::assertCount(3, $items);
        self::assertTrue($items['driver-key']->isHit());
        self::assertSame('driver-val', $items['driver-key']->get());
        self::assertTrue($items['deferred-key']->isHit());
        self::assertSame('deferred-val', $items['deferred-key']->get());
        self::assertFalse($items['missing-key']->isHit());
    }

    #[Test]
    public function getItemsHandlesDriverError(): void
    {
        $driver = $this->createStub(CacheDriverInterface::class);
        $driver->method('getMultiple')->willThrowException(new RuntimeException('read err'));
        $driver->method('name')->willReturn('failing');

        $pool = new CachePool(
            poolName: 'test',
            driver: $driver,
            serializer: new JsonCacheSerializer(),
            eventEmitter: new CacheEventEmitter(),
            critical: false,
        );

        /** @var array<string, CacheItemInterface> $items */
        $items = $pool->getItems(['k1', 'k2']);

        self::assertCount(2, $items);
        self::assertFalse($items['k1']->isHit());
        self::assertFalse($items['k2']->isHit());
    }

    #[Test]
    public function getItemsCriticalModeThrowsOnDriverError(): void
    {
        $driver = $this->createStub(CacheDriverInterface::class);
        $driver->method('getMultiple')->willThrowException(new RuntimeException('read err'));
        $driver->method('name')->willReturn('failing');

        $pool = new CachePool(
            poolName: 'test',
            driver: $driver,
            serializer: new JsonCacheSerializer(),
            eventEmitter: new CacheEventEmitter(),
            critical: true,
        );

        $this->expectException(CacheException::class);
        $pool->getItems(['k1']);
    }

    #[Test]
    public function hasItemReturnsTrueForDeferredItem(): void
    {
        $pool = $this->createPool();

        $item = CacheItem::hit('has-deferred', 'val');
        $pool->saveDeferred($item);

        self::assertTrue($pool->hasItem('has-deferred'));
    }

    #[Test]
    public function hasItemReturnsFalseOnDriverException(): void
    {
        $driver = $this->createStub(CacheDriverInterface::class);
        $driver->method('has')->willThrowException(new RuntimeException('check err'));
        $driver->method('name')->willReturn('failing');

        $pool = new CachePool(
            poolName: 'test',
            driver: $driver,
            serializer: new JsonCacheSerializer(),
            eventEmitter: new CacheEventEmitter(),
        );

        self::assertFalse($pool->hasItem('any-key'));
    }

    #[Test]
    public function deleteItemsReturnsEmptyArray(): void
    {
        $pool = $this->createPool();

        self::assertTrue($pool->deleteItems([]));
    }

    #[Test]
    public function deleteItemsRemovesDeferredItems(): void
    {
        $pool = $this->createPool();

        $item = CacheItem::hit('del-deferred', 'v');
        $pool->saveDeferred($item);

        $pool->deleteItems(['del-deferred']);

        $retrieved = $pool->getItem('del-deferred');
        self::assertFalse($retrieved->isHit());
    }

    #[Test]
    public function deleteItemsHandlesDriverError(): void
    {
        $driver = $this->createStub(CacheDriverInterface::class);
        $driver->method('deleteMultiple')->willThrowException(new RuntimeException('del err'));
        $driver->method('name')->willReturn('failing');

        $pool = new CachePool(
            poolName: 'test',
            driver: $driver,
            serializer: new JsonCacheSerializer(),
            eventEmitter: new CacheEventEmitter(),
            critical: false,
        );

        self::assertFalse($pool->deleteItems(['k1', 'k2']));
    }

    #[Test]
    public function deleteItemsCriticalModeThrowsOnDriverError(): void
    {
        $driver = $this->createStub(CacheDriverInterface::class);
        $driver->method('deleteMultiple')->willThrowException(new RuntimeException('del err'));
        $driver->method('name')->willReturn('failing');

        $pool = new CachePool(
            poolName: 'test',
            driver: $driver,
            serializer: new JsonCacheSerializer(),
            eventEmitter: new CacheEventEmitter(),
            critical: true,
        );

        $this->expectException(CacheException::class);
        $pool->deleteItems(['k1']);
    }

    #[Test]
    public function clearRemovesDeferredItems(): void
    {
        $pool = $this->createPool();

        $item = CacheItem::hit('deferred-clear', 'v');
        $pool->saveDeferred($item);

        $pool->clear();

        $retrieved = $pool->getItem('deferred-clear');
        self::assertFalse($retrieved->isHit());
    }

    #[Test]
    public function saveForeignCacheItemReturnsFalse(): void
    {
        $pool = $this->createPool();
        $foreignItem = $this->createStub(CacheItemInterface::class);

        self::assertFalse($pool->save($foreignItem));
    }

    #[Test]
    public function commitSavesAllDeferredItems(): void
    {
        $pool = $this->createPool();

        $item1 = CacheItem::hit('c1', 'val1');
        $item2 = CacheItem::hit('c2', 'val2');
        $pool->saveDeferred($item1);
        $pool->saveDeferred($item2);

        $result = $pool->commit();
        self::assertTrue($result);

        self::assertTrue($pool->getItem('c1')->isHit());
        self::assertTrue($pool->getItem('c2')->isHit());
    }

    #[Test]
    public function commitReturnsFalseIfAnySaveFails(): void
    {
        $driver = $this->createStub(CacheDriverInterface::class);
        $driver->method('set')->willThrowException(new RuntimeException('write err'));
        $driver->method('name')->willReturn('failing');

        $pool = new CachePool(
            poolName: 'test',
            driver: $driver,
            serializer: new JsonCacheSerializer(),
            eventEmitter: new CacheEventEmitter(),
            critical: false,
        );

        $item = CacheItem::hit('commit-fail', 'v');
        $pool->saveDeferred($item);

        self::assertFalse($pool->commit());
    }

    #[Test]
    public function rememberUsesCustomTtl(): void
    {
        $pool = $this->createPool();

        $value = $pool->remember('ttl-key', static fn(): string => 'ttl-val', 60);

        self::assertSame('ttl-val', $value);

        $cached = $pool->remember('ttl-key', static fn(): string => 'should-not-be-called', 60);
        self::assertSame('ttl-val', $cached);
    }

    #[Test]
    public function rememberUsesDefaultTtl(): void
    {
        $pool = new CachePool(
            poolName: 'test',
            driver: new ArrayDriver(),
            serializer: new JsonCacheSerializer(),
            eventEmitter: new CacheEventEmitter(),
            defaultTtlSeconds: 300,
        );

        $value = $pool->remember('default-ttl', static fn(): string => 'val');

        self::assertSame('val', $value);
    }

    #[Test]
    public function saveItemWithExpirationSetsCorrectTtl(): void
    {
        $pool = $this->createPool();

        $item = CacheItem::miss('expire-key');
        $item->set('expire-val');
        $item->expiresAt(new DateTimeImmutable('+1 hour'));

        self::assertTrue($pool->save($item));

        $retrieved = $pool->getItem('expire-key');
        self::assertTrue($retrieved->isHit());
    }

    #[Test]
    public function deleteItemRemovesDeferredItem(): void
    {
        $pool = $this->createPool();

        $item = CacheItem::hit('del-def', 'v');
        $pool->saveDeferred($item);

        $pool->deleteItem('del-def');

        $retrieved = $pool->getItem('del-def');
        self::assertFalse($retrieved->isHit());
    }

    private function createPool(): CachePool
    {
        return new CachePool(
            poolName: 'test',
            driver: new ArrayDriver(),
            serializer: new JsonCacheSerializer(),
            eventEmitter: new CacheEventEmitter(),
        );
    }
}
