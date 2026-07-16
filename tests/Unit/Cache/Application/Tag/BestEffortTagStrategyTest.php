<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Tag;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Driver\ArrayDriver;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use Pulsar\Cache\Application\Tag\BestEffortTagStrategy;

#[CoversClass(BestEffortTagStrategy::class)]
final class BestEffortTagStrategyTest extends TestCase
{
    private ArrayDriver $driver;
    private BestEffortTagStrategy $strategy;

    protected function setUp(): void
    {
        $this->driver = new ArrayDriver();
        $this->strategy = new BestEffortTagStrategy($this->driver);
    }

    #[Test]
    public function getTagVersionsInitializesMissingTags(): void
    {
        $versions = $this->strategy->getTagVersions(['tag-a', 'tag-b']);

        self::assertArrayHasKey('tag-a', $versions);
        self::assertArrayHasKey('tag-b', $versions);
        self::assertNotEmpty($versions['tag-a']);
        self::assertNotEmpty($versions['tag-b']);
    }

    #[Test]
    public function getTagVersionsReturnsConsistentVersionsOnSecondCall(): void
    {
        $first = $this->strategy->getTagVersions(['tag-a']);
        $second = $this->strategy->getTagVersions(['tag-a']);

        self::assertSame($first['tag-a'], $second['tag-a']);
    }

    #[Test]
    public function memoizesTagVersionsToAvoidRepeatedDriverReads(): void
    {
        // The driver must be consulted once; the second read for the same tag is
        // served from the in-instance memo (ADR-0018's local version caching).
        $driver = $this->createMock(CacheDriverInterface::class);
        $driver->expects(self::once())->method('getMultiple')->willReturn([]);
        $driver->method('set')->willReturn(true);

        $strategy = new BestEffortTagStrategy($driver);

        $first = $strategy->getTagVersions(['tag-a']);
        $second = $strategy->getTagVersions(['tag-a']);

        self::assertSame($first['tag-a'], $second['tag-a']);
    }

    #[Test]
    public function resetRequestStateClearsTheMemoSoCrossWorkerInvalidationsAreObserved(): void
    {
        // Simulate two workers sharing one driver: worker A memoizes v1, worker
        // B invalidates the tag on the shared backend. Without the reset, A's
        // memo would keep answering v1 forever (persistent-runtime staleness);
        // after resetRequestState() A must re-read the driver and see v2.
        $sharedDriver = new ArrayDriver();
        $workerA = new BestEffortTagStrategy($sharedDriver);
        $workerB = new BestEffortTagStrategy($sharedDriver);

        $before = $workerA->getTagVersions(['tag-a']);
        $workerB->invalidateTag('tag-a');

        self::assertSame($before, $workerA->getTagVersions(['tag-a']), 'Within one request the memo answers');

        $workerA->resetRequestState();

        self::assertNotSame($before['tag-a'], $workerA->getTagVersions(['tag-a'])['tag-a'], 'After the per-request reset the invalidation must be visible');
    }

    #[Test]
    public function invalidateTagChangesVersion(): void
    {
        $before = $this->strategy->getTagVersions(['tag-a']);

        $this->strategy->invalidateTag('tag-a');

        $after = $this->strategy->getTagVersions(['tag-a']);

        self::assertNotSame($before['tag-a'], $after['tag-a']);
    }

    #[Test]
    public function invalidateTagsChangesMultipleVersions(): void
    {
        $before = $this->strategy->getTagVersions(['tag-a', 'tag-b']);

        $this->strategy->invalidateTags(['tag-a', 'tag-b']);

        $after = $this->strategy->getTagVersions(['tag-a', 'tag-b']);

        self::assertNotSame($before['tag-a'], $after['tag-a']);
        self::assertNotSame($before['tag-b'], $after['tag-b']);
    }

    #[Test]
    public function getTagVersionsReturnsEmptyForEmptyTags(): void
    {
        $versions = $this->strategy->getTagVersions([]);

        self::assertSame([], $versions);
    }

    #[Test]
    public function invalidateTagDoesNotAffectOtherTags(): void
    {
        $before = $this->strategy->getTagVersions(['tag-a', 'tag-b']);

        $this->strategy->invalidateTag('tag-a');

        $after = $this->strategy->getTagVersions(['tag-a', 'tag-b']);

        self::assertNotSame($before['tag-a'], $after['tag-a']);
        self::assertSame($before['tag-b'], $after['tag-b']);
    }

    #[Test]
    public function getTagVersionsReturnsDifferentVersionsPerTag(): void
    {
        $versions = $this->strategy->getTagVersions(['tag-x', 'tag-y']);

        // Each tag gets its own independently generated version
        self::assertNotSame($versions['tag-x'], $versions['tag-y']);
    }

    #[Test]
    public function invalidateTagsWithEmptyArrayDoesNothing(): void
    {
        $before = $this->strategy->getTagVersions(['tag-a']);

        $this->strategy->invalidateTags([]);

        $after = $this->strategy->getTagVersions(['tag-a']);

        self::assertSame($before['tag-a'], $after['tag-a']);
    }

    #[Test]
    public function versionIsHexString(): void
    {
        $versions = $this->strategy->getTagVersions(['tag-hex']);

        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $versions['tag-hex']);
    }

    #[Test]
    public function doubleInvalidateProducesDifferentVersions(): void
    {
        $this->strategy->getTagVersions(['tag-a']);

        $this->strategy->invalidateTag('tag-a');
        $v1 = $this->strategy->getTagVersions(['tag-a']);

        $this->strategy->invalidateTag('tag-a');
        $v2 = $this->strategy->getTagVersions(['tag-a']);

        self::assertNotSame($v1['tag-a'], $v2['tag-a']);
    }

    #[Test]
    public function getTagVersionsMixesExistingAndNewTags(): void
    {
        // Initialize tag-a
        $first = $this->strategy->getTagVersions(['tag-a']);

        // Now ask for tag-a (existing) and tag-new (new)
        $second = $this->strategy->getTagVersions(['tag-a', 'tag-new']);

        self::assertSame($first['tag-a'], $second['tag-a']);
        self::assertArrayHasKey('tag-new', $second);
        self::assertNotEmpty($second['tag-new']);
    }
}
