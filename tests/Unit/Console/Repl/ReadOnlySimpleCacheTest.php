<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Repl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Pulsar\Console\Repl\ReadOnlySimpleCache;
use Pulsar\Console\Repl\ReplSafeModeException;

#[CoversClass(ReadOnlySimpleCache::class)]
final class ReadOnlySimpleCacheTest extends TestCase
{
    private CacheInterface&Stub $inner;
    private ReadOnlySimpleCache $cache;

    protected function setUp(): void
    {
        if (!interface_exists(CacheInterface::class)) {
            self::markTestSkipped('PSR-16 SimpleCache interface not available.');
        }

        $this->inner = $this->createStub(CacheInterface::class);
        $this->cache = new ReadOnlySimpleCache($this->inner);
    }

    #[Test]
    public function getDelegates(): void
    {
        $this->inner->method('get')->willReturn('value');

        self::assertSame('value', $this->cache->get('key'));
    }

    #[Test]
    public function getMultipleDelegates(): void
    {
        $keys = ['a', 'b'];
        $expected = ['a' => 1, 'b' => 2];
        $this->inner->method('getMultiple')->willReturn($expected);

        self::assertSame($expected, $this->cache->getMultiple($keys));
    }

    #[Test]
    public function hasDelegates(): void
    {
        $this->inner->method('has')->willReturn(true);

        self::assertTrue($this->cache->has('key'));
    }

    #[Test]
    public function setThrows(): void
    {
        $this->expectException(ReplSafeModeException::class);

        $this->cache->set('key', 'value');
    }

    #[Test]
    public function setMultipleThrows(): void
    {
        $this->expectException(ReplSafeModeException::class);

        $this->cache->setMultiple(['key' => 'value']);
    }

    #[Test]
    public function deleteThrows(): void
    {
        $this->expectException(ReplSafeModeException::class);

        $this->cache->delete('key');
    }

    #[Test]
    public function deleteMultipleThrows(): void
    {
        $this->expectException(ReplSafeModeException::class);

        $this->cache->deleteMultiple(['key']);
    }

    #[Test]
    public function clearThrows(): void
    {
        $this->expectException(ReplSafeModeException::class);

        $this->cache->clear();
    }
}
