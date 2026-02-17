<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Support\Arr;
use stdClass;

final class ArrTest extends TestCase
{
    // ── get ────────────────────────────────────────────────────────────

    #[Test]
    public function getRetrievesTopLevelKey(): void
    {
        self::assertSame('bar', Arr::get(['foo' => 'bar'], 'foo'));
    }

    #[Test]
    public function getRetrievesNestedKeyWithDotNotation(): void
    {
        $array = ['user' => ['name' => 'Alice', 'address' => ['city' => 'NYC']]];

        self::assertSame('Alice', Arr::get($array, 'user.name'));
        self::assertSame('NYC', Arr::get($array, 'user.address.city'));
    }

    #[Test]
    public function getReturnsDefaultForMissingKey(): void
    {
        self::assertSame('default', Arr::get([], 'missing', 'default'));
        self::assertNull(Arr::get([], 'missing'));
    }

    #[Test]
    public function getReturnsDefaultForMissingNestedKey(): void
    {
        $array = ['user' => ['name' => 'Alice']];

        self::assertNull(Arr::get($array, 'user.email'));
        self::assertNull(Arr::get($array, 'user.address.city'));
    }

    // ── set ────────────────────────────────────────────────────────────

    #[Test]
    public function setSetsTopLevelKey(): void
    {
        $result = Arr::set([], 'foo', 'bar');

        self::assertSame(['foo' => 'bar'], $result);
    }

    #[Test]
    public function setSetsNestedKeyWithDotNotation(): void
    {
        $result = Arr::set([], 'user.name', 'Alice');

        self::assertSame(['user' => ['name' => 'Alice']], $result);
    }

    #[Test]
    public function setDoesNotMutateOriginal(): void
    {
        $original = ['foo' => 'bar'];
        $result = Arr::set($original, 'baz', 'qux');

        self::assertSame('bar', $original['foo']);
        self::assertArrayNotHasKey('baz', $original);
        self::assertSame('qux', $result['baz']);
    }

    // ── has ────────────────────────────────────────────────────────────

    #[Test]
    public function hasChecksTopLevelKey(): void
    {
        self::assertTrue(Arr::has(['foo' => 'bar'], 'foo'));
        self::assertFalse(Arr::has(['foo' => 'bar'], 'baz'));
    }

    #[Test]
    public function hasChecksNestedKey(): void
    {
        $array = ['user' => ['name' => 'Alice']];

        self::assertTrue(Arr::has($array, 'user.name'));
        self::assertFalse(Arr::has($array, 'user.email'));
    }

    // ── pluck ──────────────────────────────────────────────────────────

    #[Test]
    public function pluckExtractsKeyFromArrays(): void
    {
        $items = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ];

        self::assertSame(['Alice', 'Bob'], Arr::pluck($items, 'name'));
    }

    #[Test]
    public function pluckExtractsPropertyFromObjects(): void
    {
        $a = new stdClass();
        $a->name = 'Alice';
        $b = new stdClass();
        $b->name = 'Bob';

        self::assertSame(['Alice', 'Bob'], Arr::pluck([$a, $b], 'name'));
    }

    // ── unique ─────────────────────────────────────────────────────────

    #[Test]
    public function uniqueRemovesDuplicates(): void
    {
        self::assertSame([1, 2, 3], Arr::unique([1, 2, 2, 3, 3]));
    }

    // ── flatten ────────────────────────────────────────────────────────

    #[Test]
    public function flattenFlattensNestedArrays(): void
    {
        self::assertSame([1, 2, 3, 4], Arr::flatten([[1, 2], [3, 4]]));
        self::assertSame([1, 2, 3, 4, 5], Arr::flatten([[1, [2, 3]], [4, 5]]));
    }

    #[Test]
    public function flattenRespectsDepthLimit(): void
    {
        $result = Arr::flatten([[1, [2, [3]]]], 1);

        self::assertSame([1, [2, [3]]], $result);
    }

    // ── groupBy ───────────────────────────────────────────────────────

    #[Test]
    public function groupByGroupsItemsByKey(): void
    {
        $items = [
            ['type' => 'fruit', 'name' => 'apple'],
            ['type' => 'veg', 'name' => 'carrot'],
            ['type' => 'fruit', 'name' => 'banana'],
        ];

        $groups = Arr::groupBy($items, 'type');

        self::assertCount(2, $groups);
        self::assertCount(2, $groups['fruit']);
        self::assertCount(1, $groups['veg']);
    }

    // ── sortBy ────────────────────────────────────────────────────────

    #[Test]
    public function sortBySortsByKey(): void
    {
        $items = [
            ['name' => 'Charlie', 'age' => 30],
            ['name' => 'Alice', 'age' => 25],
            ['name' => 'Bob', 'age' => 28],
        ];

        $sorted = Arr::sortBy($items, 'age');

        self::assertSame(25, $sorted[0]['age']);
        self::assertSame(28, $sorted[1]['age']);
        self::assertSame(30, $sorted[2]['age']);
    }

    // ── first / last ──────────────────────────────────────────────────

    #[Test]
    public function firstReturnsFirstElement(): void
    {
        self::assertSame(1, Arr::first([1, 2, 3]));
        self::assertNull(Arr::first([]));
    }

    #[Test]
    public function firstWithCallbackReturnsFirstMatch(): void
    {
        self::assertSame(3, Arr::first([1, 2, 3, 4], fn(mixed $n): bool => $n > 2));
        self::assertNull(Arr::first([1, 2], fn(mixed $n): bool => $n > 100));
    }

    #[Test]
    public function lastReturnsLastElement(): void
    {
        self::assertSame(3, Arr::last([1, 2, 3]));
        self::assertNull(Arr::last([]));
    }

    #[Test]
    public function lastWithCallbackReturnsLastMatch(): void
    {
        self::assertSame(2, Arr::last([1, 2, 3, 4], fn(mixed $n): bool => $n < 3));
    }

    // ── except / only ─────────────────────────────────────────────────

    #[Test]
    public function exceptRemovesKeys(): void
    {
        $result = Arr::except(['a' => 1, 'b' => 2, 'c' => 3], ['b', 'c']);

        self::assertSame(['a' => 1], $result);
    }

    #[Test]
    public function exceptAcceptsSingleKey(): void
    {
        $result = Arr::except(['a' => 1, 'b' => 2], 'b');

        self::assertSame(['a' => 1], $result);
    }

    #[Test]
    public function onlyKeepsSpecifiedKeys(): void
    {
        $result = Arr::only(['a' => 1, 'b' => 2, 'c' => 3], ['a', 'c']);

        self::assertSame(['a' => 1, 'c' => 3], $result);
    }

    // ── where ─────────────────────────────────────────────────────────

    #[Test]
    public function whereFiltersItems(): void
    {
        $result = Arr::where([1, 2, 3, 4, 5], fn(mixed $n, int $i): bool => $n > 3);

        self::assertSame([4, 5], $result);
    }

    // ── wrap ──────────────────────────────────────────────────────────

    #[Test]
    public function wrapWrapsScalarInArray(): void
    {
        self::assertSame([42], Arr::wrap(42));
        self::assertSame(['hello'], Arr::wrap('hello'));
    }

    #[Test]
    public function wrapReturnsArrayAsIs(): void
    {
        self::assertSame([1, 2], Arr::wrap([1, 2]));
    }

    #[Test]
    public function wrapReturnsEmptyArrayForNull(): void
    {
        self::assertSame([], Arr::wrap(null));
    }
}
