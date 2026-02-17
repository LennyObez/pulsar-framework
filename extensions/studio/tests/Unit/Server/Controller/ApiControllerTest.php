<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\Server\Controller\ApiController;

#[CoversClass(ApiController::class)]
final class ApiControllerTest extends TestCase
{
    #[Test]
    public function eventsReturnsJsonWithPagination(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([['event_id' => 'e1']]);
        $store->method('count')->willReturn(1);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn(null);

        $controller = new ApiController($store);
        $response = $controller->events($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $data['total']);
        self::assertCount(1, $data['events']);
    }

    #[Test]
    public function eventsPassesFiltersFromRequest(): void
    {
        $receivedFilters = null;
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturnCallback(
            function (array $filters) use (&$receivedFilters): array {
                $receivedFilters = $filters;
                return [];
            },
        );
        $store->method('count')->willReturn(0);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnMap([
            ['_query_types', null, 'http.request,http.response'],
            ['_query_request_id', null, 'req-abc'],
            ['_query_since', null, '1700000000'],
            ['_query_since_id', null, null],
            ['_query_limit', null, '25'],
            ['_query_offset', null, '10'],
        ]);

        $controller = new ApiController($store);
        $controller->events($request);

        self::assertSame(['http.request', 'http.response'], $receivedFilters['event_type']);
        self::assertSame('req-abc', $receivedFilters['request_id']);
        self::assertSame(1700000000, $receivedFilters['since_us']);
    }

    #[Test]
    public function eventsDefaultsLimitAndOffset(): void
    {
        $receivedLimit = null;
        $receivedOffset = null;
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturnCallback(
            function (array $filters, int $limit, int $offset) use (&$receivedLimit, &$receivedOffset): array {
                $receivedLimit = $limit;
                $receivedOffset = $offset;
                return [];
            },
        );
        $store->method('count')->willReturn(0);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn(null);

        $controller = new ApiController($store);
        $controller->events($request);

        self::assertSame(50, $receivedLimit);
        self::assertSame(0, $receivedOffset);
    }
}
