<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Tag;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Driver\ArrayDriver;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use Pulsar\Cache\Application\Tag\StrictTagStrategy;

#[CoversClass(StrictTagStrategy::class)]
final class StrictTagStrategyTest extends TestCase
{
    private ArrayDriver $driver;
    private StrictTagStrategy $strategy;

    protected function setUp(): void
    {
        $this->driver = new ArrayDriver();
        $this->strategy = new StrictTagStrategy($this->driver);
    }

    #[Test]
    public function getTagVersionsInitializesMissingTags(): void
    {
        $versions = $this->strategy->getTagVersions(['tag-a', 'tag-b']);

        // A missing tag is initialized to a fresh, non-empty version (no longer a
        // shared constant — see evictedTagVersionDoesNotCollideAfterReinitialization).
        self::assertArrayHasKey('tag-a', $versions);
        self::assertArrayHasKey('tag-b', $versions);
        self::assertNotSame('', $versions['tag-a']);
        self::assertNotSame('', $versions['tag-b']);
    }

    #[Test]
    public function getTagVersionsReturnsExistingVersions(): void
    {
        // Initialize tags, then fetch again — the persisted version is returned.
        $initial = $this->strategy->getTagVersions(['tag-a']);
        $again = $this->strategy->getTagVersions(['tag-a']);

        self::assertSame($initial['tag-a'], $again['tag-a']);
    }

    #[Test]
    public function invalidateTagBumpsVersion(): void
    {
        $before = $this->strategy->getTagVersions(['tag-a'])['tag-a'];

        $this->strategy->invalidateTag('tag-a');

        $after = $this->strategy->getTagVersions(['tag-a'])['tag-a'];

        self::assertNotSame($before, $after);
    }

    #[Test]
    public function invalidateTagsBumpsMultipleVersions(): void
    {
        $before = $this->strategy->getTagVersions(['tag-a', 'tag-b']);

        $this->strategy->invalidateTags(['tag-a', 'tag-b']);

        $after = $this->strategy->getTagVersions(['tag-a', 'tag-b']);

        self::assertNotSame($before['tag-a'], $after['tag-a']);
        self::assertNotSame($before['tag-b'], $after['tag-b']);
    }

    #[Test]
    public function evictedTagVersionDoesNotCollideAfterReinitialization(): void
    {
        // When a version key is evicted (LRU) and later reinitialized, the
        // new version must not equal the one a prior snapshot was taken against —
        // a constant reset value collides and surfaces stale data as a cache hit
        // despite an intervening invalidateTag().
        $first = $this->strategy->getTagVersions(['tag-a'])['tag-a'];

        // Evict the version key, then reinitialize it.
        $this->driver->delete('_tag:tag-a:ver');
        $reinitialized = $this->strategy->getTagVersions(['tag-a'])['tag-a'];

        self::assertNotSame($first, $reinitialized);
    }

    #[Test]
    public function invalidateTagFallsBackWhenDriverIncrementReturnsFalse(): void
    {
        $driver = $this->createMock(CacheDriverInterface::class);

        // getMultiple is called by getTagVersions to init the tag
        $driver->method('getMultiple')->willReturn([]);
        $driver->method('set')->willReturn(true);
        $driver->method('increment')->willReturn(false);

        $strategy = new StrictTagStrategy($driver);

        // Initialize the tag
        $strategy->getTagVersions(['tag-a']);

        // invalidateTag calls increment which returns false,
        // so it falls back to set() with hrtime value
        $driver->expects(self::atLeastOnce())->method('set');

        $strategy->invalidateTag('tag-a');
    }
}
