<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\I18nConfig;
use Pulsar\Extension\Studio\Console\Aggregation\TimelineBuilder;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\Server\Controller\TimelineController;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;
use Pulsar\I18n\CatalogInterface;
use Pulsar\I18n\Translator;

#[CoversClass(TimelineController::class)]
final class TimelineControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('get')->willReturn(null);
        $catalog->method('has')->willReturn(false);
        Translator::setGlobalInstance(new Translator($catalog, new I18nConfig(
            defaultLocale: 'en',
            supportedLocales: ['en'],
            fallbackLocales: [],
            catalogPath: null,
            regulated: false,
            maxSupportedLocales: 50,
            strictMode: false,
        )));
    }

    protected function tearDown(): void
    {
        Translator::resetGlobalInstance();
    }
    private function createRequest(): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: '/studio/console/timeline/abc123',
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

        self::assertSame(ResponseStatus::NotFound->value, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('No events found', (string) $response->getBody());
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

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('<!DOCTYPE html>', (string) $response->getBody());
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

        $body = (string) $response->getBody();
        self::assertStringContainsString('correlation_id', $body);
        self::assertStringContainsString('correlation-xyz', $body);
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

        $body = (string) $response->getBody();
        self::assertStringContainsString('events', $body);
        self::assertStringContainsString('evt-001', $body);
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

        self::assertStringNotContainsString('<script>alert(1)</script>', (string) $response->getBody());
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

        self::assertStringContainsString('data-page="timeline"', (string) $response->getBody());
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

        $body = (string) $response->getBody();
        self::assertStringContainsString('href="/studio/assets/studio.css"', $body);
        self::assertStringContainsString('src="/studio/assets/main.js"', $body);
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

        self::assertStringContainsString('<title>Timeline - Pulsar Studio</title>', (string) $response->getBody());
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

        $body = (string) $response->getBody();
        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertStringContainsString('evt-001', $body);
        self::assertStringContainsString('evt-002', $body);
    }
}
