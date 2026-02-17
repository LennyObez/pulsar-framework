<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Support\Json;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Support\Json\JsonPath;

#[CoversClass(JsonPath::class)]
final class JsonPathTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $store;

    protected function setUp(): void
    {
        JsonPath::clearCache();

        $this->store = [
            'store' => [
                'book' => [
                    ['category' => 'reference', 'author' => 'Nigel Rees', 'title' => 'Sayings', 'price' => 8.95],
                    ['category' => 'fiction', 'author' => 'Evelyn Waugh', 'title' => 'Sword', 'price' => 12.99],
                    ['category' => 'fiction', 'author' => 'Herman Melville', 'title' => 'Moby Dick', 'price' => 8.99],
                    ['category' => 'fiction', 'author' => 'Tolkien', 'title' => 'LOTR', 'price' => 22.99],
                ],
                'bicycle' => ['color' => 'red', 'price' => 399.0],
            ],
        ];
    }

    public function testDotNotationAccessesNestedKey(): void
    {
        /** @var list<array<string, mixed>> $result */
        $result = JsonPath::query($this->store, '$.store.bicycle.color');

        self::assertSame(['red'], $result);
    }

    public function testArrayIndexAccess(): void
    {
        /** @var list<array<string, mixed>> $result */
        $result = JsonPath::query($this->store, '$.store.book[0].author');

        self::assertSame(['Nigel Rees'], $result);
    }

    public function testWildcardAccess(): void
    {
        /** @var list<array<string, mixed>> $result */
        $result = JsonPath::query($this->store, '$.store.book[*].author');

        self::assertSame(['Nigel Rees', 'Evelyn Waugh', 'Herman Melville', 'Tolkien'], $result);
    }

    public function testRecursiveDescentFindsAllAuthors(): void
    {
        /** @var list<array<string, mixed>> $result */
        $result = JsonPath::query($this->store, '$..author');

        self::assertSame(['Nigel Rees', 'Evelyn Waugh', 'Herman Melville', 'Tolkien'], $result);
    }

    public function testRecursiveDescentFindsAllPrices(): void
    {
        /** @var list<array<string, mixed>> $result */
        $result = JsonPath::query($this->store, '$..price');

        self::assertCount(5, $result);
        self::assertContains(8.95, $result);
        self::assertContains(399.0, $result);
    }

    public function testFilterLessThan(): void
    {
        /** @var list<array<string, mixed>> $result */
        $result = JsonPath::query($this->store, '$.store.book[?(@.price < 10)]');

        self::assertCount(2, $result);
        self::assertSame('Sayings', $result[0]['title']);
        self::assertSame('Moby Dick', $result[1]['title']);
    }

    public function testFilterGreaterThan(): void
    {
        /** @var list<array<string, mixed>> $result */
        $result = JsonPath::query($this->store, '$.store.book[?(@.price > 20)]');

        self::assertCount(1, $result);
        self::assertSame('LOTR', $result[0]['title']);
    }

    public function testFilterEquality(): void
    {
        /** @var list<array<string, mixed>> $result */
        $result = JsonPath::query($this->store, "$.store.book[?(@.category == 'reference')]");

        self::assertCount(1, $result);
        self::assertSame('Sayings', $result[0]['title']);
    }

    public function testFilterInequality(): void
    {
        /** @var list<array<string, mixed>> $result */
        $result = JsonPath::query($this->store, "$.store.book[?(@.category != 'fiction')]");

        self::assertCount(1, $result);
        self::assertSame('reference', $result[0]['category']);
    }

    public function testFilterExistence(): void
    {
        $data = [
            'items' => [
                ['name' => 'a', 'color' => 'red'],
                ['name' => 'b'],
                ['name' => 'c', 'color' => 'blue'],
            ],
        ];

        $result = JsonPath::query($data, '$.items[?(@.color)]');

        self::assertCount(2, $result);
    }

    public function testNegativeIndex(): void
    {
        /** @var list<array<string, mixed>> $result */
        $result = JsonPath::query($this->store, '$.store.book[-1].title');

        self::assertSame(['LOTR'], $result);
    }

    public function testNegativeIndexSecondFromEnd(): void
    {
        /** @var list<array<string, mixed>> $result */
        $result = JsonPath::query($this->store, '$.store.book[-2].title');

        self::assertSame(['Moby Dick'], $result);
    }

    public function testSliceFirstThree(): void
    {
        /** @var list<array<string, mixed>> $result */
        $result = JsonPath::query($this->store, '$.store.book[0:3]');

        self::assertCount(3, $result);
        self::assertSame('Sayings', $result[0]['title']);
        self::assertSame('Moby Dick', $result[2]['title']);
    }

    public function testSliceFromIndex(): void
    {
        /** @var list<array<string, mixed>> $result */
        $result = JsonPath::query($this->store, '$.store.book[2:]');

        self::assertCount(2, $result);
        self::assertSame('Moby Dick', $result[0]['title']);
    }

    public function testFirstReturnsFirstMatch(): void
    {
        $result = JsonPath::first($this->store, '$..author');

        self::assertSame('Nigel Rees', $result);
    }

    public function testFirstReturnsNullForNoMatch(): void
    {
        $result = JsonPath::first($this->store, '$.nonexistent');

        self::assertNull($result);
    }

    public function testExistsReturnsTrueForMatch(): void
    {
        self::assertTrue(JsonPath::exists($this->store, '$.store.bicycle'));
    }

    public function testExistsReturnsFalseForNoMatch(): void
    {
        self::assertFalse(JsonPath::exists($this->store, '$.store.airplane'));
    }

    public function testBracketNotationWithQuotedKey(): void
    {
        /** @var list<array<string, mixed>> $result */
        $result = JsonPath::query($this->store, "$.store['bicycle'].color");

        self::assertSame(['red'], $result);
    }

    public function testQueryOnScalar(): void
    {
        $result = JsonPath::query('hello', '$.length');

        self::assertSame([], $result);
    }

    public function testQueryOnNull(): void
    {
        $result = JsonPath::query(null, '$.key');

        self::assertSame([], $result);
    }

    public function testInvalidPathMissingDollar(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("must start with '\$'");

        (void) JsonPath::query([], 'store.book');
    }

    public function testCachingReturnsSameResults(): void
    {
        $path = '$.store.book[0].title';

        $first = JsonPath::query($this->store, $path);
        $second = JsonPath::query($this->store, $path);

        self::assertSame($first, $second);
    }

    public function testClearCacheWorks(): void
    {
        (void) JsonPath::query($this->store, '$.store.bicycle.color');
        JsonPath::clearCache();

        // Should still work after cache clear
        /** @var list<array<string, mixed>> $result */
        $result = JsonPath::query($this->store, '$.store.bicycle.color');
        self::assertSame(['red'], $result);
    }

    public function testRecursiveDescentWithWildcard(): void
    {
        $data = [
            'a' => ['x' => 1, 'y' => 2],
            'b' => ['x' => 3],
        ];

        $result = JsonPath::query($data, '$..x');

        self::assertNotEmpty($result);
        self::assertContains(1, $result);
        self::assertContains(3, $result);
    }

    public function testOutOfBoundsIndexReturnsEmpty(): void
    {
        /** @var list<array<string, mixed>> $result */
        $result = JsonPath::query($this->store, '$.store.book[99]');

        self::assertSame([], $result);
    }

    public function testFilterLessThanOrEqual(): void
    {
        /** @var list<array<string, mixed>> $result */
        $result = JsonPath::query($this->store, '$.store.book[?(@.price <= 9)]');

        self::assertCount(2, $result);
    }

    public function testFilterGreaterThanOrEqual(): void
    {
        /** @var list<array<string, mixed>> $result */
        $result = JsonPath::query($this->store, '$.store.book[?(@.price >= 20)]');

        self::assertCount(1, $result);
        self::assertSame('LOTR', $result[0]['title']);
    }

    public function testSliceWithStep(): void
    {
        $data = ['items' => [0, 1, 2, 3, 4, 5]];

        $result = JsonPath::query($data, '$.items[0:6:2]');

        self::assertSame([0, 2, 4], $result);
    }

    public function testSliceStepZeroThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('step cannot be zero');

        (void) JsonPath::query(['items' => [1, 2, 3]], '$.items[::0]');
    }
}
