<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Prefix;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Driver\ArrayDriver;
use Pulsar\Cache\Application\Prefix\GenerationScopedCacheDecorator;

#[CoversClass(GenerationScopedCacheDecorator::class)]
final class GenerationScopedCacheDecoratorTest extends TestCase
{
    private ArrayDriver $store;
    private GenerationScopedCacheDecorator $cache;

    protected function setUp(): void
    {
        // One store stands in for the backend: the decorator uses it both for
        // data (via the stack) and for the generation counter (the raw driver).
        $this->store = new ArrayDriver();
        $this->cache = new GenerationScopedCacheDecorator($this->store, $this->store, 'app.');
    }

    #[Test]
    public function dataIsStoredUnderTheGenerationNamespaceAndRoundTrips(): void
    {
        self::assertTrue($this->cache->set('user.1', 'alice', null));
        self::assertSame('alice', $this->cache->get('user.1'));

        // Physically stored under the generation segment, not the bare prefix.
        self::assertSame('alice', $this->store->get('app.g0.user.1'));
        self::assertNull($this->store->get('app.user.1'));
    }

    #[Test]
    public function clearBumpsTheGenerationSoOldKeysBecomeUnreachable(): void
    {
        $this->cache->set('a', '1', null);
        $this->cache->set('b', '2', null);

        self::assertTrue($this->cache->clear());

        // The same logical keys now resolve to a fresh, empty generation.
        self::assertNull($this->cache->get('a'));
        self::assertNull($this->cache->get('b'));

        // The counter advanced, and new writes land in the new generation.
        self::assertSame('1', $this->store->get('app.gen'));
        $this->cache->set('a', 'new', null);
        self::assertSame('new', $this->cache->get('a'));
        self::assertSame('new', $this->store->get('app.g1.a'));
    }

    #[Test]
    public function repeatedClearsKeepAdvancingTheGeneration(): void
    {
        $this->cache->clear();
        $this->cache->clear();
        $this->cache->clear();

        self::assertSame('3', $this->store->get('app.gen'));
    }

    #[Test]
    public function resetRequestStateObservesAClearMadeByAnotherWorker(): void
    {
        $this->cache->set('k', 'v', null);
        self::assertSame('v', $this->cache->get('k'));

        // Another worker (a second decorator on the same store) clears.
        $other = new GenerationScopedCacheDecorator($this->store, $this->store, 'app.');
        $other->clear();

        // This decorator still has generation 0 memoized — it sees the old key.
        self::assertSame('v', $this->cache->get('k'));

        // After a request boundary it re-reads the counter and sees the clear.
        $this->cache->resetRequestState();
        self::assertNull($this->cache->get('k'));
    }

    #[Test]
    public function getMultipleAndSetMultipleMapThroughTheGeneration(): void
    {
        $this->cache->setMultiple(['x' => '1', 'y' => '2'], null);

        self::assertSame(['x' => '1', 'y' => '2', 'z' => null], $this->cache->getMultiple(['x', 'y', 'z']));
        self::assertSame('1', $this->store->get('app.g0.x'));
    }

    #[Test]
    public function deleteAndHasRespectTheGeneration(): void
    {
        $this->cache->set('k', 'v', null);

        self::assertTrue($this->cache->has('k'));
        self::assertTrue($this->cache->delete('k'));
        self::assertFalse($this->cache->has('k'));
    }

    #[Test]
    public function countersAreGenerationScoped(): void
    {
        self::assertSame(1, $this->cache->increment('hits'));
        self::assertSame(3, $this->cache->increment('hits', 2));
        self::assertSame('3', $this->store->get('app.g0.hits'));

        // A clear resets the counter's namespace too.
        $this->cache->clear();
        self::assertSame(5, $this->cache->increment('hits', 5));
    }

    #[Test]
    public function addIsGenerationScoped(): void
    {
        self::assertTrue($this->cache->add('once', 'first', null));
        self::assertFalse($this->cache->add('once', 'second', null));
        self::assertSame('first', $this->cache->get('once'));
    }

    #[Test]
    public function nameReflectsTheDecorator(): void
    {
        self::assertSame('generation:array', $this->cache->name());
    }
}
