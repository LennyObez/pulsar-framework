<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Driver\ArrayDriver;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use Pulsar\Cache\Application\Event\CacheEventEmitter;
use Pulsar\Cache\Application\Exception\CacheException;
use Pulsar\Cache\Application\Serializer\JsonCacheSerializer;
use Pulsar\Cache\Application\Tag\BestEffortTagStrategy;
use Pulsar\Cache\Application\TaggedCache;
use RuntimeException;

#[CoversClass(TaggedCache::class)]
final class TaggedCacheTest extends TestCase
{
    private TaggedCache $cache;
    private ArrayDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new ArrayDriver();

        $this->cache = new TaggedCache(
            poolName: 'tagged-test',
            driver: $this->driver,
            serializer: new JsonCacheSerializer(),
            tagStrategy: new BestEffortTagStrategy($this->driver),
            eventEmitter: new CacheEventEmitter(),
        );
    }

    #[Test]
    public function setWithTagsAndGetReturnsValue(): void
    {
        $this->cache->set('item1', 'value1', ['tag-a']);

        self::assertSame('value1', $this->cache->get('item1'));
    }

    #[Test]
    public function invalidateTagCausesGetToReturnNull(): void
    {
        $this->cache->set('item1', 'value1', ['tag-a']);

        $this->cache->invalidateTag('tag-a');

        self::assertNull($this->cache->get('item1'));
    }

    #[Test]
    public function invalidateTagsInvalidatesMultipleTags(): void
    {
        $this->cache->set('item1', 'value1', ['tag-a']);
        $this->cache->set('item2', 'value2', ['tag-b']);

        $this->cache->invalidateTags(['tag-a', 'tag-b']);

        self::assertNull($this->cache->get('item1'));
        self::assertNull($this->cache->get('item2'));
    }

    #[Test]
    public function itemWithoutMatchingTagStillAvailableAfterInvalidation(): void
    {
        $this->cache->set('item1', 'value1', ['tag-a']);
        $this->cache->set('item2', 'value2', ['tag-b']);

        $this->cache->invalidateTag('tag-a');

        self::assertNull($this->cache->get('item1'));
        self::assertSame('value2', $this->cache->get('item2'));
    }

    #[Test]
    public function criticalModeThrowsCacheExceptionOnDriverErrorDuringGet(): void
    {
        $driver = $this->createStub(CacheDriverInterface::class);
        $driver->method('get')->willThrowException(new RuntimeException('connection lost'));
        $driver->method('name')->willReturn('failing');

        $cache = new TaggedCache(
            poolName: 'critical-tagged',
            driver: $driver,
            serializer: new JsonCacheSerializer(),
            tagStrategy: new BestEffortTagStrategy($this->driver),
            eventEmitter: new CacheEventEmitter(),
            critical: true,
        );

        $this->expectException(CacheException::class);
        $cache->get('any-key');
    }

    #[Test]
    public function criticalModeThrowsCacheExceptionOnDriverErrorDuringSet(): void
    {
        $driver = $this->createStub(CacheDriverInterface::class);
        $driver->method('set')->willThrowException(new RuntimeException('connection lost'));
        $driver->method('name')->willReturn('failing');

        $tagDriver = new ArrayDriver();
        $cache = new TaggedCache(
            poolName: 'critical-tagged',
            driver: $driver,
            serializer: new JsonCacheSerializer(),
            tagStrategy: new BestEffortTagStrategy($tagDriver),
            eventEmitter: new CacheEventEmitter(),
            critical: true,
        );

        $this->expectException(CacheException::class);
        $cache->set('any-key', 'value', ['tag-a']);
    }

    #[Test]
    public function deleteRemovesTaggedItem(): void
    {
        $this->cache->set('item1', 'value1', ['tag-a']);

        $result = $this->cache->delete('item1');

        self::assertTrue($result);
        self::assertNull($this->cache->get('item1'));
    }

    #[Test]
    public function setWithEmptyTagsArrayStoresValue(): void
    {
        $this->cache->set('no-tags', 'value', []);

        self::assertSame('value', $this->cache->get('no-tags'));
    }

    #[Test]
    public function getReturnsNullForCorruptJsonEnvelopeInDriver(): void
    {
        // Store a raw string that is not a valid JSON envelope
        $this->driver->set('corrupt', 'not-json{{{', null);

        self::assertNull($this->cache->get('corrupt'));
    }
}
