<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\ResponseStatus;
use Pulsar\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Studio\Server\Controller\ApiController;

#[CoversClass(ApiController::class)]
final class ApiControllerTest extends TestCase
{
    /**
     * @param array<string, mixed> $attributes
     */
    private function createRequest(array $attributes = []): Request
    {
        return new Request(
            method: Method::GET,
            uri: '/studio/api/events',
            path: '/studio/api/events',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
            attributes: $attributes,
        );
    }

    #[Test]
    public function eventsReturnsJsonResponse(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->method('count')->willReturn(0);

        $controller = new ApiController($store);

        $response = $controller->events($this->createRequest());

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->contentType());
    }

    #[Test]
    public function eventsIncludesEventsArray(): void
    {
        $events = [
            [
                'id' => 1,
                'event_id' => 'evt-001',
                'event_type' => 'http.request',
                'timestamp_us' => 1700000000000000,
            ],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);
        $store->method('count')->willReturn(1);

        $controller = new ApiController($store);

        $response = $controller->events($this->createRequest());

        /** @var array{events: list<array{event_id: string}>} $data */
        $data = json_decode($response->body, true);
        self::assertIsArray($data);
        self::assertArrayHasKey('events', $data);
        self::assertCount(1, $data['events']);
        self::assertSame('evt-001', $data['events'][0]['event_id']);
    }

    #[Test]
    public function eventsIncludesTotalCount(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->method('count')->willReturn(42);

        $controller = new ApiController($store);

        $response = $controller->events($this->createRequest());

        /** @var array{total: int} $data */
        $data = json_decode($response->body, true);
        self::assertSame(42, $data['total']);
    }

    #[Test]
    public function eventsIncludesDefaultPaginationParameters(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->method('count')->willReturn(0);

        $controller = new ApiController($store);

        $response = $controller->events($this->createRequest());

        /** @var array{limit: int, offset: int} $data */
        $data = json_decode($response->body, true);
        self::assertSame(50, $data['limit']);
        self::assertSame(0, $data['offset']);
    }

    #[Test]
    public function eventsUsesCustomLimitFromQueryParameter(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with([], 25, 0)
            ->willReturn([]);
        $store->method('count')->willReturn(0);

        $controller = new ApiController($store);
        $request = $this->createRequest(['_query_limit' => '25']);

        $response = $controller->events($request);

        /** @var array{limit: int} $data */
        $data = json_decode($response->body, true);
        self::assertSame(25, $data['limit']);
    }

    #[Test]
    public function eventsUsesCustomOffsetFromQueryParameter(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with([], 50, 10)
            ->willReturn([]);
        $store->method('count')->willReturn(0);

        $controller = new ApiController($store);
        $request = $this->createRequest(['_query_offset' => '10']);

        $response = $controller->events($request);

        /** @var array{offset: int} $data */
        $data = json_decode($response->body, true);
        self::assertSame(10, $data['offset']);
    }

    #[Test]
    public function eventsFiltersbyEventTypes(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with(['event_type' => ['http.request', 'http.response']], 50, 0)
            ->willReturn([]);
        $store->method('count')->willReturn(0);

        $controller = new ApiController($store);
        $request = $this->createRequest(['_query_types' => 'http.request,http.response']);

        $controller->events($request);
    }

    #[Test]
    public function eventsFiltersByRequestId(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with(['request_id' => 'req-123'], 50, 0)
            ->willReturn([]);
        $store->method('count')->willReturn(0);

        $controller = new ApiController($store);
        $request = $this->createRequest(['_query_request_id' => 'req-123']);

        $controller->events($request);
    }

    #[Test]
    public function eventsFiltersBySinceTimestamp(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with(['since_us' => 1700000000000000], 50, 0)
            ->willReturn([]);
        $store->method('count')->willReturn(0);

        $controller = new ApiController($store);
        $request = $this->createRequest(['_query_since' => '1700000000000000']);

        $controller->events($request);
    }

    #[Test]
    public function eventsFiltersBySinceId(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with(['since_id' => 100], 50, 0)
            ->willReturn([]);
        $store->method('count')->willReturn(0);

        $controller = new ApiController($store);
        $request = $this->createRequest(['_query_since_id' => '100']);

        $controller->events($request);
    }

    #[Test]
    public function eventsHandlesIntegerLimitAttribute(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with([], 30, 0)
            ->willReturn([]);
        $store->method('count')->willReturn(0);

        $controller = new ApiController($store);
        $request = $this->createRequest(['_query_limit' => 30]);

        $controller->events($request);
    }

    #[Test]
    public function eventsHandlesIntegerOffsetAttribute(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with([], 50, 15)
            ->willReturn([]);
        $store->method('count')->willReturn(0);

        $controller = new ApiController($store);
        $request = $this->createRequest(['_query_offset' => 15]);

        $controller->events($request);
    }

    #[Test]
    public function eventsHandlesIntegerSinceAttribute(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with(['since_us' => 1700000000], 50, 0)
            ->willReturn([]);
        $store->method('count')->willReturn(0);

        $controller = new ApiController($store);
        $request = $this->createRequest(['_query_since' => 1700000000]);

        $controller->events($request);
    }

    #[Test]
    public function eventsHandlesIntegerSinceIdAttribute(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with(['since_id' => 50], 50, 0)
            ->willReturn([]);
        $store->method('count')->willReturn(0);

        $controller = new ApiController($store);
        $request = $this->createRequest(['_query_since_id' => 50]);

        $controller->events($request);
    }

    #[Test]
    public function eventsCombinesMultipleFilters(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with(
                [
                    'event_type' => ['db.query'],
                    'request_id' => 'req-456',
                    'since_us' => 1700000000000000,
                    'since_id' => 10,
                ],
                25,
                5,
            )
            ->willReturn([]);
        $store->method('count')->willReturn(0);

        $controller = new ApiController($store);
        $request = $this->createRequest([
            '_query_types' => 'db.query',
            '_query_request_id' => 'req-456',
            '_query_since' => '1700000000000000',
            '_query_since_id' => '10',
            '_query_limit' => '25',
            '_query_offset' => '5',
        ]);

        $controller->events($request);
    }

    #[Test]
    public function eventsPassesFiltersToCountMethod(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->expects(self::once())
            ->method('count')
            ->with(['event_type' => ['http.request']])
            ->willReturn(10);

        $controller = new ApiController($store);
        $request = $this->createRequest(['_query_types' => 'http.request']);

        $response = $controller->events($request);

        /** @var array{total: int} $data */
        $data = json_decode($response->body, true);
        self::assertSame(10, $data['total']);
    }

    #[Test]
    public function eventsReturnsEmptyArrayWhenNoEvents(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);
        $store->method('count')->willReturn(0);

        $controller = new ApiController($store);

        $response = $controller->events($this->createRequest());

        /** @var array{events: list<mixed>, total: int} $data */
        $data = json_decode($response->body, true);
        self::assertSame([], $data['events']);
        self::assertSame(0, $data['total']);
    }
}
