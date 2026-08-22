<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Driver\ArrayDriver;
use Pulsar\Cache\Application\Event\CacheEventEmitter;
use Pulsar\Cache\Application\Serializer\JsonCacheSerializer;
use Pulsar\Cache\Application\Tag\BestEffortTagStrategy;
use Pulsar\Cache\Application\TaggedCache;

#[CoversClass(TaggedCache::class)]
final class TaggedCacheInterfaceContractTest extends TestCase
{
    private TaggedCache $tagged;
    private ArrayDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new ArrayDriver();
        $serializer = new JsonCacheSerializer();
        $tagStrategy = new BestEffortTagStrategy($this->driver);
        $emitter = new CacheEventEmitter();

        $this->tagged = new TaggedCache(
            poolName: 'test-pool',
            driver: $this->driver,
            serializer: $serializer,
            tagStrategy: $tagStrategy,
            eventEmitter: $emitter,
        );
    }

    #[Test]
    public function setAndGetWithTagsRoundTrips(): void
    {
        $this->tagged->set('user.1', ['name' => 'Alice'], ['users'], 3600);

        self::assertSame(['name' => 'Alice'], $this->tagged->get('user.1'));
    }

    #[Test]
    public function invalidateTagCausesGetToReturnNull(): void
    {
        $this->tagged->set('product.1', 'widget', ['products'], 3600);
        $this->tagged->invalidateTag('products');

        self::assertNull($this->tagged->get('product.1'));
    }

    #[Test]
    public function invalidateTagsInvalidatesMultipleTags(): void
    {
        $this->tagged->set('item.1', 'a', ['tag-a'], 3600);
        $this->tagged->set('item.2', 'b', ['tag-b'], 3600);
        $this->tagged->invalidateTags(['tag-a', 'tag-b']);

        self::assertNull($this->tagged->get('item.1'));
        self::assertNull($this->tagged->get('item.2'));
    }

    #[Test]
    public function deleteRemovesSpecificKey(): void
    {
        $this->tagged->set('key-1', 'value', ['tag'], 3600);

        self::assertTrue($this->tagged->delete('key-1'));
        self::assertNull($this->tagged->get('key-1'));
    }

    #[Test]
    public function getReturnsNullForNonExistentKey(): void
    {
        self::assertNull($this->tagged->get('nonexistent'));
    }

    #[Test]
    public function multipleTagsAllMustBeValidForHit(): void
    {
        $this->tagged->set('multi', 'data', ['tag-x', 'tag-y'], 3600);

        // Invalidate only one tag
        $this->tagged->invalidateTag('tag-x');

        // The value should be gone since one of its tags was invalidated
        self::assertNull($this->tagged->get('multi'));
    }

    #[Test]
    public function setWithEmptyTagsStoresWithoutTagValidation(): void
    {
        $this->tagged->set('no-tags', 42, [], 3600);

        self::assertSame(42, $this->tagged->get('no-tags'));
    }
}
