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

        self::assertSame('1', $versions['tag-a']);
        self::assertSame('1', $versions['tag-b']);
    }

    #[Test]
    public function getTagVersionsReturnsExistingVersions(): void
    {
        // Initialize tags
        $this->strategy->getTagVersions(['tag-a']);

        // Fetch again — should return the same version
        $versions = $this->strategy->getTagVersions(['tag-a']);

        self::assertSame('1', $versions['tag-a']);
    }

    #[Test]
    public function invalidateTagBumpsVersion(): void
    {
        $this->strategy->getTagVersions(['tag-a']);

        $this->strategy->invalidateTag('tag-a');

        $versions = $this->strategy->getTagVersions(['tag-a']);

        self::assertSame('2', $versions['tag-a']);
    }

    #[Test]
    public function invalidateTagsBumpsMultipleVersions(): void
    {
        $this->strategy->getTagVersions(['tag-a', 'tag-b']);

        $this->strategy->invalidateTags(['tag-a', 'tag-b']);

        $versions = $this->strategy->getTagVersions(['tag-a', 'tag-b']);

        self::assertSame('2', $versions['tag-a']);
        self::assertSame('2', $versions['tag-b']);
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
