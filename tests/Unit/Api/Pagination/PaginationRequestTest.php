<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Pagination;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationRequest;

#[CoversClass(PaginationRequest::class)]
final class PaginationRequestTest extends TestCase
{
    #[Test]
    public function from_query_uses_defaults_when_empty(): void
    {
        $req = PaginationRequest::fromQuery([]);

        self::assertSame(25, $req->perPage);
        self::assertSame(1, $req->page);
        self::assertNull($req->cursor);
        self::assertNull($req->after);
        self::assertNull($req->before);
    }

    #[Test]
    public function from_query_reads_per_page_and_page(): void
    {
        $req = PaginationRequest::fromQuery([
            'per_page' => 10,
            'page' => 3,
        ]);

        self::assertSame(10, $req->perPage);
        self::assertSame(3, $req->page);
    }

    #[Test]
    public function from_query_accepts_limit_alias_for_per_page(): void
    {
        $req = PaginationRequest::fromQuery(['limit' => 15]);

        self::assertSame(15, $req->perPage);
    }

    #[Test]
    public function from_query_per_page_takes_priority_over_limit(): void
    {
        $req = PaginationRequest::fromQuery([
            'per_page' => 20,
            'limit' => 50,
        ]);

        self::assertSame(20, $req->perPage);
    }

    #[Test]
    public function from_query_clamps_per_page_to_max(): void
    {
        $req = PaginationRequest::fromQuery(['per_page' => 500], maxSize: 100);

        self::assertSame(100, $req->perPage);
    }

    #[Test]
    public function from_query_clamps_per_page_minimum_to_one(): void
    {
        $req = PaginationRequest::fromQuery(['per_page' => 0]);

        self::assertSame(1, $req->perPage);
    }

    #[Test]
    public function from_query_clamps_negative_per_page(): void
    {
        $req = PaginationRequest::fromQuery(['per_page' => -5]);

        self::assertSame(1, $req->perPage);
    }

    #[Test]
    public function from_query_clamps_page_minimum_to_one(): void
    {
        $req = PaginationRequest::fromQuery(['page' => 0]);

        self::assertSame(1, $req->page);
    }

    #[Test]
    public function from_query_clamps_negative_page(): void
    {
        $req = PaginationRequest::fromQuery(['page' => -3]);

        self::assertSame(1, $req->page);
    }

    #[Test]
    #[DataProvider('stringNumericProvider')]
    public function from_query_coerces_numeric_strings(string $key, string $value, int $expected): void
    {
        $req = PaginationRequest::fromQuery([$key => $value]);

        $actual = $key === 'per_page' ? $req->perPage : $req->page;
        self::assertSame($expected, $actual);
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function stringNumericProvider(): iterable
    {
        yield 'per_page string' => ['per_page', '42', 42];
        yield 'page string' => ['page', '7', 7];
    }

    #[Test]
    public function from_query_uses_default_for_non_numeric_per_page(): void
    {
        $req = PaginationRequest::fromQuery(['per_page' => 'abc'], defaultSize: 30);

        self::assertSame(30, $req->perPage);
    }

    #[Test]
    public function from_query_uses_default_for_non_numeric_page(): void
    {
        $req = PaginationRequest::fromQuery(['page' => 'xyz']);

        self::assertSame(1, $req->page);
    }

    #[Test]
    public function from_query_parses_cursor_params(): void
    {
        $req = PaginationRequest::fromQuery([
            'cursor' => 'eyJpZCI6NDJ9',
            'after' => 'after-token',
            'before' => 'before-token',
        ]);

        self::assertSame('eyJpZCI6NDJ9', $req->cursor);
        self::assertSame('after-token', $req->after);
        self::assertSame('before-token', $req->before);
    }

    #[Test]
    public function from_query_treats_empty_cursor_strings_as_null(): void
    {
        $req = PaginationRequest::fromQuery([
            'cursor' => '',
            'after' => '',
            'before' => '',
        ]);

        self::assertNull($req->cursor);
        self::assertNull($req->after);
        self::assertNull($req->before);
    }

    #[Test]
    public function offset_calculation(): void
    {
        $req = new PaginationRequest(perPage: 25, page: 1);
        self::assertSame(0, $req->offset());

        $req2 = new PaginationRequest(perPage: 25, page: 3);
        self::assertSame(50, $req2->offset());

        $req3 = new PaginationRequest(perPage: 10, page: 5);
        self::assertSame(40, $req3->offset());
    }

    #[Test]
    public function from_query_custom_default_and_max(): void
    {
        $req = PaginationRequest::fromQuery([], defaultSize: 50, maxSize: 200);

        self::assertSame(50, $req->perPage);
    }
}
