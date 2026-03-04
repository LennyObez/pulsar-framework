<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Pagination;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Pagination\PaginatedResponse;
use Pulsar\Pagination\PaginationResult;
use Pulsar\Pagination\Paginator;

final class PaginatedResponseTest extends TestCase
{
    #[Test]
    public function fromPaginatorCreatesResponseWithCorrectData(): void
    {
        $paginator = new Paginator(['a', 'b'], total: 10, currentPage: 1, perPage: 5);
        $response = PaginatedResponse::fromPaginator($paginator);

        self::assertSame(200, $response->statusCode);
        self::assertSame(['a', 'b'], $response->result->items);
    }

    #[Test]
    public function toJsonProducesValidJsonWithItemsAndMeta(): void
    {
        $result = new PaginationResult(['x'], total: 1, perPage: 10, currentPage: 1);
        $response = new PaginatedResponse($result);

        $json = $response->toJson();

        /** @var array{items: list<string>, meta: array{current_page: int, per_page: int, total: int, last_page: int, has_more: bool}} $decoded */
        $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);

        self::assertSame(['x'], $decoded['items']);
        self::assertSame(1, $decoded['meta']['current_page']);
        self::assertSame(10, $decoded['meta']['per_page']);
        self::assertSame(1, $decoded['meta']['total']);
        self::assertSame(1, $decoded['meta']['last_page']);
        self::assertFalse($decoded['meta']['has_more']);
    }

    #[Test]
    public function getHeadersIncludesContentType(): void
    {
        $result = new PaginationResult([], total: 0, perPage: 10, currentPage: 1);
        $response = new PaginatedResponse($result);

        self::assertSame('application/json', $response->getHeaders()['Content-Type']);
    }

    #[Test]
    public function customHeadersMergeWithContentType(): void
    {
        $result = new PaginationResult([], total: 0, perPage: 10, currentPage: 1);
        $response = new PaginatedResponse($result, headers: ['X-Custom' => 'value']);

        $headers = $response->getHeaders();
        self::assertSame('application/json', $headers['Content-Type']);
        self::assertSame('value', $headers['X-Custom']);
    }

    #[Test]
    public function customStatusCodeIsPreserved(): void
    {
        $result = new PaginationResult([], total: 0, perPage: 10, currentPage: 1);
        $response = new PaginatedResponse($result, statusCode: 206);

        self::assertSame(206, $response->statusCode);
    }
}
