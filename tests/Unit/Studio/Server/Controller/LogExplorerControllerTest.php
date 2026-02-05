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
use Pulsar\Studio\Server\Controller\LogExplorerController;

#[CoversClass(LogExplorerController::class)]
final class LogExplorerControllerTest extends TestCase
{
    private function createRequest(): Request
    {
        return new Request(
            method: Method::GET,
            uri: '/studio/console/logs',
            path: '/studio/console/logs',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );
    }

    #[Test]
    public function handleReturnsHtmlResponse(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new LogExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertSame('text/html; charset=utf-8', $response->contentType());
        self::assertStringContainsString('<!DOCTYPE html>', $response->body);
    }

    #[Test]
    public function handleIncludesPageIdentifier(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new LogExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('data-page="log-explorer"', $response->body);
    }

    #[Test]
    public function handleIncludesEventsInPayload(): void
    {
        $events = [
            [
                'id' => 1,
                'event_id' => 'evt-001',
                'event_type' => 'log.entry',
                'timestamp_us' => 1700000000000000,
                'payload' => '{"level":"info","message":"User logged in"}',
            ],
            [
                'id' => 2,
                'event_id' => 'evt-002',
                'event_type' => 'log.entry',
                'timestamp_us' => 1700000001000000,
                'payload' => '{"level":"error","message":"Database connection failed"}',
            ],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);

        $controller = new LogExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('evt-001', $response->body);
        self::assertStringContainsString('evt-002', $response->body);
        self::assertStringContainsString('log.entry', $response->body);
    }

    #[Test]
    public function handleQueriesStoreWithCorrectEventTypeFilter(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with(['event_type' => ['log.entry']], 100)
            ->willReturn([]);

        $controller = new LogExplorerController($store);

        $controller->handle($this->createRequest());
    }

    #[Test]
    public function handleEscapesPayloadForHtmlAttribute(): void
    {
        $events = [
            [
                'id' => 1,
                'event_id' => 'evt-001',
                'event_type' => 'log.entry',
                'payload' => '{"message":"<script>alert(1)</script>"}',
            ],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);

        $controller = new LogExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertStringNotContainsString('<script>alert(1)</script>', $response->body);
    }

    #[Test]
    public function handleIncludesStylesheetReference(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new LogExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('href="/studio/assets/studio.css"', $response->body);
    }

    #[Test]
    public function handleIncludesScriptReference(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new LogExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('src="/studio/assets/main.js"', $response->body);
    }

    #[Test]
    public function handleSetsCorrectPageTitle(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new LogExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('<title>Logs - Pulsar Studio</title>', $response->body);
    }

    #[Test]
    public function handleIncludesDataPayloadAttribute(): void
    {
        $events = [
            [
                'id' => 1,
                'event_id' => 'evt-001',
                'event_type' => 'log.entry',
            ],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);

        $controller = new LogExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('data-payload="', $response->body);
    }

    #[Test]
    public function handleReturnsEmptyEventsArrayWhenNoEvents(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new LogExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('&quot;events&quot;:[]', $response->body);
    }
}
