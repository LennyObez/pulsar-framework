<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Repl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Pulsar\Console\Repl\ReadOnlyCachePool;
use Pulsar\Console\Repl\ReplSafeModeException;

#[CoversClass(ReadOnlyCachePool::class)]
final class ReadOnlyCachePoolTest extends TestCase
{
    private CacheItemPoolInterface&Stub $inner;
    private ReadOnlyCachePool $pool;

    protected function setUp(): void
    {
        if (!interface_exists(CacheItemPoolInterface::class)) {
            self::markTestSkipped('PSR-6 CacheItemPool interface not available.');
        }

        $this->inner = $this->createStub(CacheItemPoolInterface::class);
        $this->pool = new ReadOnlyCachePool($this->inner);
    }

    #[Test]
    public function getItemDelegates(): void
    {
        $item = $this->createStub(CacheItemInterface::class);
        $this->inner->method('getItem')->willReturn($item);

        self::assertSame($item, $this->pool->getItem('key'));
    }

    #[Test]
    public function getItemsDelegates(): void
    {
        $items = [$this->createStub(CacheItemInterface::class)];
        $this->inner->method('getItems')->willReturn($items);

        self::assertSame($items, $this->pool->getItems(['key']));
    }

    #[Test]
    public function hasItemDelegates(): void
    {
        $this->inner->method('hasItem')->willReturn(true);

        self::assertTrue($this->pool->hasItem('key'));
    }

    #[Test]
    public function saveThrows(): void
    {
        $this->expectException(ReplSafeModeException::class);

        $item = $this->createStub(CacheItemInterface::class);
        $this->pool->save($item);
    }

    #[Test]
    public function saveDeferredThrows(): void
    {
        $this->expectException(ReplSafeModeException::class);

        $item = $this->createStub(CacheItemInterface::class);
        $this->pool->saveDeferred($item);
    }

    #[Test]
    public function commitThrows(): void
    {
        $this->expectException(ReplSafeModeException::class);

        $this->pool->commit();
    }

    #[Test]
    public function deleteItemThrows(): void
    {
        $this->expectException(ReplSafeModeException::class);

        $this->pool->deleteItem('key');
    }

    #[Test]
    public function deleteItemsThrows(): void
    {
        $this->expectException(ReplSafeModeException::class);

        $this->pool->deleteItems(['key']);
    }

    #[Test]
    public function clearThrows(): void
    {
        $this->expectException(ReplSafeModeException::class);

        $this->pool->clear();
    }
}
