<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Support\Collection;
use stdClass;

final class CollectionTest extends TestCase
{
    // ── Factory ────────────────────────────────────────────────────────

    #[Test]
    public function ofCreatesCollectionFromArray(): void
    {
        $collection = Collection::of([1, 2, 3]);

        self::assertSame([1, 2, 3], $collection->toArray());
    }

    #[Test]
    public function emptyCreatesEmptyCollection(): void
    {
        $collection = Collection::empty();

        self::assertSame([], $collection->toArray());
        self::assertTrue($collection->isEmpty());
    }

    // ── filter ─────────────────────────────────────────────────────────

    #[Test]
    public function filterRemovesItemsThatFailCallback(): void
    {
        $result = Collection::of([1, 2, 3, 4, 5])
            ->filter(fn(int $n): bool => $n > 3);

        self::assertSame([4, 5], $result->toArray());
    }

    #[Test]
    public function filterReturnsEmptyWhenNoItemsMatch(): void
    {
        $result = Collection::of([1, 2, 3])
            ->filter(fn(int $n): bool => $n > 100);

        self::assertTrue($result->isEmpty());
    }

    #[Test]
    public function filterPassesIndexToCallback(): void
    {
        $indices = [];
        $result = Collection::of(['a', 'b', 'c'])
            ->filter(function (string $item, int $index) use (&$indices): bool {
                $indices[] = $index;
                return true;
            });

        self::assertSame([0, 1, 2], $indices);
        self::assertCount(3, $result);
    }

    // ── map ────────────────────────────────────────────────────────────

    #[Test]
    public function mapTransformsEachItem(): void
    {
        $result = Collection::of([1, 2, 3])
            ->map(fn(int $n): int => $n * 2);

        self::assertSame([2, 4, 6], $result->toArray());
    }

    #[Test]
    public function mapPassesIndexToCallback(): void
    {
        $result = Collection::of(['a', 'b', 'c'])
            ->map(fn(string $item, int $index): string => $index . ':' . $item);

        self::assertSame(['0:a', '1:b', '2:c'], $result->toArray());
    }

    // ── pluck ──────────────────────────────────────────────────────────

    #[Test]
    public function pluckExtractsKeyFromArrayItems(): void
    {
        $items = [
            ['name' => 'Alice', 'age' => 30],
            ['name' => 'Bob', 'age' => 25],
        ];

        $names = Collection::of($items)->pluck('name');

        self::assertSame(['Alice', 'Bob'], $names->toArray());
    }

    #[Test]
    public function pluckExtractsPropertyFromObjects(): void
    {
        $a = new stdClass();
        $a->name = 'Alice';
        $b = new stdClass();
        $b->name = 'Bob';

        $names = Collection::of([$a, $b])->pluck('name');

        self::assertSame(['Alice', 'Bob'], $names->toArray());
    }

    #[Test]
    public function pluckSkipsItemsMissingKey(): void
    {
        $items = [
            ['name' => 'Alice'],
            ['age' => 25],
            ['name' => 'Charlie'],
        ];

        $names = Collection::of($items)->pluck('name');

        self::assertSame(['Alice', 'Charlie'], $names->toArray());
    }

    // ── unique ─────────────────────────────────────────────────────────

    #[Test]
    public function uniqueRemovesDuplicates(): void
    {
        $result = Collection::of([1, 2, 2, 3, 3, 3])
            ->unique();

        self::assertSame([1, 2, 3], $result->toArray());
    }

    // ── first / last ──────────────────────────────────────────────────

    #[Test]
    public function firstReturnsFirstItem(): void
    {
        self::assertSame(10, Collection::of([10, 20, 30])->first());
    }

    #[Test]
    public function firstReturnsNullOnEmptyCollection(): void
    {
        self::assertNull(Collection::empty()->first());
    }

    #[Test]
    public function firstWithCallbackReturnsFirstMatch(): void
    {
        $result = Collection::of([1, 2, 3, 4])
            ->first(fn(int $n): bool => $n > 2);

        self::assertSame(3, $result);
    }

    #[Test]
    public function firstWithCallbackReturnsNullWhenNoMatch(): void
    {
        $result = Collection::of([1, 2, 3])
            ->first(fn(int $n): bool => $n > 100);

        self::assertNull($result);
    }

    #[Test]
    public function lastReturnsLastItem(): void
    {
        self::assertSame(30, Collection::of([10, 20, 30])->last());
    }

    #[Test]
    public function lastReturnsNullOnEmptyCollection(): void
    {
        self::assertNull(Collection::empty()->last());
    }

    #[Test]
    public function lastWithCallbackReturnsLastMatch(): void
    {
        $result = Collection::of([1, 2, 3, 4])
            ->last(fn(int $n): bool => $n < 3);

        self::assertSame(2, $result);
    }

    // ── count / isEmpty ───────────────────────────────────────────────

    #[Test]
    public function countReturnsItemCount(): void
    {
        self::assertCount(3, Collection::of([1, 2, 3]));
        self::assertSame(3, Collection::of([1, 2, 3])->count());
    }

    #[Test]
    public function isEmptyReturnsTrueForEmpty(): void
    {
        self::assertTrue(Collection::empty()->isEmpty());
        self::assertFalse(Collection::of([1])->isEmpty());
    }

    #[Test]
    public function isNotEmptyReturnsTrueForNonEmpty(): void
    {
        self::assertTrue(Collection::of([1])->isNotEmpty());
        self::assertFalse(Collection::empty()->isNotEmpty());
    }

    // ── reduce ────────────────────────────────────────────────────────

    #[Test]
    public function reduceAccumulatesValues(): void
    {
        $sum = Collection::of([1, 2, 3, 4])
            ->reduce(fn(int $carry, int $item): int => $carry + $item, 0);

        self::assertSame(10, $sum);
    }

    #[Test]
    public function reduceReturnsInitialForEmptyCollection(): void
    {
        $result = Collection::empty()
            ->reduce(fn(int $carry, mixed $item): int => $carry + 1, 42);

        self::assertSame(42, $result);
    }

    // ── flatMap ────────────────────────────────────────────────────────

    #[Test]
    public function flatMapFlattensOneLevel(): void
    {
        $result = Collection::of([1, 2, 3])
            ->flatMap(fn(int $n): array => [$n, $n * 10]);

        self::assertSame([1, 10, 2, 20, 3, 30], $result->toArray());
    }

    // ── groupBy ───────────────────────────────────────────────────────

    #[Test]
    public function groupByKeyGroupsArrayItems(): void
    {
        $items = [
            ['dept' => 'eng', 'name' => 'Alice'],
            ['dept' => 'eng', 'name' => 'Bob'],
            ['dept' => 'sales', 'name' => 'Charlie'],
        ];

        $groups = Collection::of($items)->groupBy('dept');

        self::assertCount(2, $groups);
        self::assertCount(2, $groups['eng']);
        self::assertCount(1, $groups['sales']);
    }

    #[Test]
    public function groupByCallbackGroupsUsingClosure(): void
    {
        $groups = Collection::of([1, 2, 3, 4, 5])
            ->groupBy(fn(int $n): string => $n % 2 === 0 ? 'even' : 'odd');

        self::assertSame([1, 3, 5], $groups['odd']);
        self::assertSame([2, 4], $groups['even']);
    }

    // ── sortBy ────────────────────────────────────────────────────────

    #[Test]
    public function sortByKeySortsArrayItems(): void
    {
        $items = [
            ['name' => 'Charlie', 'age' => 30],
            ['name' => 'Alice', 'age' => 25],
            ['name' => 'Bob', 'age' => 28],
        ];

        $sorted = Collection::of($items)->sortBy('name');

        $first = $sorted->first();
        $last = $sorted->last();
        self::assertIsArray($first);
        self::assertIsArray($last);
        self::assertSame('Alice', $first['name']);
        self::assertSame('Charlie', $last['name']);
    }

    #[Test]
    public function sortByCallbackSortsUsingClosure(): void
    {
        $sorted = Collection::of([3, 1, 4, 1, 5])
            ->sortBy(fn(int $n): int => $n);

        self::assertSame([1, 1, 3, 4, 5], $sorted->toArray());
    }

    // ── contains ──────────────────────────────────────────────────────

    #[Test]
    public function containsChecksByValue(): void
    {
        $collection = Collection::of([1, 2, 3]);

        self::assertTrue($collection->contains(2));
        self::assertFalse($collection->contains(99));
    }

    #[Test]
    public function containsChecksWithCallback(): void
    {
        $collection = Collection::of([1, 2, 3]);

        self::assertTrue($collection->contains(fn(int $n): bool => $n > 2));
        self::assertFalse($collection->contains(fn(int $n): bool => $n > 100));
    }

    // ── take / skip ───────────────────────────────────────────────────

    #[Test]
    public function takeLimitsItems(): void
    {
        $result = Collection::of([1, 2, 3, 4, 5])->take(3);

        self::assertSame([1, 2, 3], $result->toArray());
    }

    #[Test]
    public function skipDropsItems(): void
    {
        $result = Collection::of([1, 2, 3, 4, 5])->skip(2);

        self::assertSame([3, 4, 5], $result->toArray());
    }

    // ── iterable ──────────────────────────────────────────────────────

    #[Test]
    public function collectionIsIterable(): void
    {
        $items = [];

        foreach (Collection::of([1, 2, 3]) as $item) {
            $items[] = $item;
        }

        self::assertSame([1, 2, 3], $items);
    }

    // ── immutability ──────────────────────────────────────────────────

    #[Test]
    public function operationsReturnNewInstances(): void
    {
        $original = Collection::of([1, 2, 3, 4, 5]);
        $filtered = $original->filter(fn(int $n): bool => $n > 3);

        self::assertNotSame($original, $filtered);
        self::assertSame([1, 2, 3, 4, 5], $original->toArray());
        self::assertSame([4, 5], $filtered->toArray());
    }
}
