<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Aggregation\TimelineBuilder;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\Server\Controller\TimelineController;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\ResponseStatus;

#[CoversClass(TimelineController::class)]
final class TimelineControllerTest extends TestCase
{
    private function createRequest(): Request
    {
        return new Request(
            method: Method::GET,
            uri: '/studio/console/timeline/abc123',
            path: '/studio/console/timeline/abc123',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );
    }

    /**
     * Create a TimelineBuilder with a mocked store.
     *
     * Since TimelineBuilder is final, we mock the EventStoreInterface instead.
     *
     * @param list<array<string, mixed>> $requestEvents Events returned for request_id query
     * @param list<array<string, mixed>> $jobEvents Events returned for job_id query
     */
    private function createTimelineBuilder(
        array $requestEvents = [],
        array $jobEvents = [],
    ): TimelineBuilder {
        $store = $this->createStub(EventStoreInterface::class);

        // Configure store to return different events based on the filter
        $store->method('query')
            ->willReturnCallback(static function (array $filters) use ($requestEvents, $jobEvents): array {
                if (isset($filters['request_id'])) {
                    return $requestEvents;
                }
                if (isset($filters['job_id'])) {
                    return $jobEvents;
                }
                return [];
            });

        return new TimelineBuilder($store);
    }

    #[Test]
    public function handleReturnsNotFoundWhenNoEventsExist(): void
    {
        $timelineBuilder = $this->createTimelineBuilder([], []);
        $controller = new TimelineController($timelineBuilder);

        $response = $controller->handle($this->createRequest(), 'abc123');

        self::assertSame(ResponseStatus::NotFound, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->contentType());
        self::assertStringContainsString('No events found', $response->body);
    }

    #[Test]
    public function handleReturnsHtmlResponseWithEventsWhenFound(): void
    {
        $events = [
            [
                'id' => 1,
                'event_id' => 'evt-001',
                'event_type' => 'http.request',
                'timestamp_us' => 1700000000000000,
                'payload' => '{}',
            ],
            [
                'id' => 2,
                'event_id' => 'evt-002',
                'event_type' => 'db.query',
                'timestamp_us' => 1700000001000000,
                'payload' => '{}',
            ],
        ];

        $timelineBuilder = $this->createTimelineBuilder($events, []);
        $controller = new TimelineController($timelineBuilder);

        $response = $controller->handle($this->createRequest(), 'abc123');

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertSame('text/html; charset=utf-8', $response->contentType());
        self::assertStringContainsString('<!DOCTYPE html>', $response->body);
    }

    #[Test]
    public function handleIncludesCorrelationIdInPayload(): void
    {
        $events = [
            [
                'id' => 1,
                'event_id' => 'evt-001',
                'event_type' => 'http.request',
                'timestamp_us' => 1700000000000000,
            ],
        ];

        $timelineBuilder = $this->createTimelineBuilder($events, []);
        $controller = new TimelineController($timelineBuilder);

        $response = $controller->handle($this->createRequest(), 'correlation-xyz');

        self::assertStringContainsString('correlation_id', $response->body);
        self::assertStringContainsString('correlation-xyz', $response->body);
    }

    #[Test]
    public function handleIncludesEventsInPayload(): void
    {
        $events = [
            [
                'id' => 1,
                'event_id' => 'evt-001',
                'event_type' => 'http.request',
            ],
        ];

        $timelineBuilder = $this->createTimelineBuilder($events, []);
        $controller = new TimelineController($timelineBuilder);

        $response = $controller->handle($this->createRequest(), 'abc123');

        self::assertStringContainsString('events', $response->body);
        self::assertStringContainsString('evt-001', $response->body);
    }

    #[Test]
    public function handleEscapesPayloadForHtmlAttribute(): void
    {
        $events = [
            [
                'id' => 1,
                'event_id' => 'evt-001',
                'event_type' => 'http.request',
                'payload' => '{"message":"<script>alert(1)</script>"}',
            ],
        ];

        $timelineBuilder = $this->createTimelineBuilder($events, []);
        $controller = new TimelineController($timelineBuilder);

        $response = $controller->handle($this->createRequest(), 'abc123');

        self::assertStringNotContainsString('<script>alert(1)</script>', $response->body);
    }

    #[Test]
    public function handleIncludesPageIdentifier(): void
    {
        $events = [
            [
                'id' => 1,
                'event_id' => 'evt-001',
                'event_type' => 'http.request',
            ],
        ];

        $timelineBuilder = $this->createTimelineBuilder($events, []);
        $controller = new TimelineController($timelineBuilder);

        $response = $controller->handle($this->createRequest(), 'abc123');

        self::assertStringContainsString('data-page="timeline"', $response->body);
    }

    #[Test]
    public function handleIncludesStylesheetAndScriptReferences(): void
    {
        $events = [
            [
                'id' => 1,
                'event_id' => 'evt-001',
                'event_type' => 'http.request',
            ],
        ];

        $timelineBuilder = $this->createTimelineBuilder($events, []);
        $controller = new TimelineController($timelineBuilder);

        $response = $controller->handle($this->createRequest(), 'abc123');

        self::assertStringContainsString('href="/studio/assets/studio.css"', $response->body);
        self::assertStringContainsString('src="/studio/assets/main.js"', $response->body);
    }

    #[Test]
    public function handleSetsCorrectPageTitle(): void
    {
        $events = [
            [
                'id' => 1,
                'event_id' => 'evt-001',
                'event_type' => 'http.request',
            ],
        ];

        $timelineBuilder = $this->createTimelineBuilder($events, []);
        $controller = new TimelineController($timelineBuilder);

        $response = $controller->handle($this->createRequest(), 'abc123');

        self::assertStringContainsString('<title>Timeline - Pulsar Studio</title>', $response->body);
    }

    #[Test]
    public function handleCombinesRequestAndJobEvents(): void
    {
        $requestEvents = [
            [
                'id' => 1,
                'event_id' => 'evt-001',
                'event_type' => 'http.request',
                'timestamp_us' => 1700000000000000,
            ],
        ];
        $jobEvents = [
            [
                'id' => 2,
                'event_id' => 'evt-002',
                'event_type' => 'job.start',
                'timestamp_us' => 1700000001000000,
            ],
        ];

        $timelineBuilder = $this->createTimelineBuilder($requestEvents, $jobEvents);
        $controller = new TimelineController($timelineBuilder);

        $response = $controller->handle($this->createRequest(), 'abc123');

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertStringContainsString('evt-001', $response->body);
        self::assertStringContainsString('evt-002', $response->body);
    }
}
