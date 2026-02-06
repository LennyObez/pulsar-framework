<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\Server\Controller\RequestExplorerController;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;

#[CoversClass(RequestExplorerController::class)]
final class RequestExplorerControllerTest extends TestCase
{
    private function createRequest(): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: '/studio/console/requests',
        );
    }

    #[Test]
    public function handleReturnsHtmlResponse(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new RequestExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('<!DOCTYPE html>', (string) $response->getBody());
    }

    #[Test]
    public function handleIncludesPageIdentifier(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new RequestExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('data-page="request-explorer"', (string) $response->getBody());
    }

    #[Test]
    public function handleIncludesEventsInPayload(): void
    {
        $events = [
            [
                'id' => 1,
                'event_id' => 'evt-001',
                'event_type' => 'http.request',
                'timestamp_us' => 1700000000000000,
                'payload' => '{"method":"GET","path":"/api/users"}',
            ],
            [
                'id' => 2,
                'event_id' => 'evt-002',
                'event_type' => 'http.response',
                'timestamp_us' => 1700000001000000,
                'payload' => '{"status":200}',
            ],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);

        $controller = new RequestExplorerController($store);

        $response = $controller->handle($this->createRequest());

        $body = (string) $response->getBody();
        self::assertStringContainsString('evt-001', $body);
        self::assertStringContainsString('evt-002', $body);
        self::assertStringContainsString('http.request', $body);
        self::assertStringContainsString('http.response', $body);
    }

    #[Test]
    public function handleQueriesStoreWithCorrectEventTypeFilter(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with(['event_type' => ['http.request', 'http.response']], 100)
            ->willReturn([]);

        $controller = new RequestExplorerController($store);

        $controller->handle($this->createRequest());
    }

    #[Test]
    public function handleEscapesPayloadForHtmlAttribute(): void
    {
        $events = [
            [
                'id' => 1,
                'event_id' => 'evt-001',
                'event_type' => 'http.request',
                'payload' => '{"path":"/<script>alert(1)</script>"}',
            ],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);

        $controller = new RequestExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertStringNotContainsString('<script>alert(1)</script>', (string) $response->getBody());
    }

    #[Test]
    public function handleIncludesStylesheetReference(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new RequestExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('href="/studio/assets/studio.css"', (string) $response->getBody());
    }

    #[Test]
    public function handleIncludesScriptReference(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new RequestExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('src="/studio/assets/main.js"', (string) $response->getBody());
    }

    #[Test]
    public function handleSetsCorrectPageTitle(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new RequestExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('<title>HTTP Requests - Pulsar Studio</title>', (string) $response->getBody());
    }

    #[Test]
    public function handleIncludesDataPayloadAttribute(): void
    {
        $events = [
            [
                'id' => 1,
                'event_id' => 'evt-001',
                'event_type' => 'http.request',
            ],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);

        $controller = new RequestExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('data-payload="', (string) $response->getBody());
    }

    #[Test]
    public function handleReturnsEmptyEventsArrayWhenNoEvents(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new RequestExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('&quot;events&quot;:[]', (string) $response->getBody());
    }
}
