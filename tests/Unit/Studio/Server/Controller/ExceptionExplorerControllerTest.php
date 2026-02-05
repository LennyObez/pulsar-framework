<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\ResponseStatus;
use Pulsar\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Studio\Security\ProductionSafetyMode;
use Pulsar\Studio\Server\Controller\ExceptionExplorerController;

#[CoversClass(ExceptionExplorerController::class)]
final class ExceptionExplorerControllerTest extends TestCase
{
    private function createRequest(): Request
    {
        return new Request(
            method: Method::GET,
            uri: '/studio/console/exceptions',
            path: '/studio/console/exceptions',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );
    }

    private function createSafetyMode(EnvironmentMode $mode = EnvironmentMode::Local): ProductionSafetyMode
    {
        return new ProductionSafetyMode($mode);
    }

    #[Test]
    public function handleReturnsHtmlResponse(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new ExceptionExplorerController($store, $this->createSafetyMode());

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

        $controller = new ExceptionExplorerController($store, $this->createSafetyMode());

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('data-page="exception-explorer"', $response->body);
    }

    #[Test]
    public function handleIncludesEventsInPayload(): void
    {
        $events = [
            [
                'id' => 1,
                'event_id' => 'evt-001',
                'event_type' => 'exception',
                'timestamp_us' => 1700000000000000,
                'payload' => '{"class":"RuntimeException","message":"Something went wrong"}',
            ],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);

        $controller = new ExceptionExplorerController($store, $this->createSafetyMode());

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('evt-001', $response->body);
        self::assertStringContainsString('exception', $response->body);
    }

    #[Test]
    public function handleQueriesStoreWithCorrectEventTypeFilter(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with(['event_type' => ['exception']], 100)
            ->willReturn([]);

        $controller = new ExceptionExplorerController($store, $this->createSafetyMode());

        $controller->handle($this->createRequest());
    }

    #[Test]
    public function handleIncludesShowTracesAsTrueInLocalMode(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $safetyMode = $this->createSafetyMode(EnvironmentMode::Local);
        $controller = new ExceptionExplorerController($store, $safetyMode);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('show_traces', $response->body);
        self::assertStringContainsString('true', $response->body);
    }

    #[Test]
    public function handleIncludesShowTracesAsFalseInStagingMode(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $safetyMode = $this->createSafetyMode(EnvironmentMode::Staging);
        $controller = new ExceptionExplorerController($store, $safetyMode);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('show_traces', $response->body);
        self::assertStringContainsString('&quot;show_traces&quot;:false', $response->body);
    }

    #[Test]
    public function handleIncludesShowTracesAsFalseInProductionMode(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $safetyMode = $this->createSafetyMode(EnvironmentMode::Production);
        $controller = new ExceptionExplorerController($store, $safetyMode);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('show_traces', $response->body);
        self::assertStringContainsString('&quot;show_traces&quot;:false', $response->body);
    }

    #[Test]
    public function handleEscapesPayloadForHtmlAttribute(): void
    {
        $events = [
            [
                'id' => 1,
                'event_id' => 'evt-001',
                'event_type' => 'exception',
                'payload' => '{"message":"<script>alert(1)</script>"}',
            ],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);

        $controller = new ExceptionExplorerController($store, $this->createSafetyMode());

        $response = $controller->handle($this->createRequest());

        self::assertStringNotContainsString('<script>alert(1)</script>', $response->body);
    }

    #[Test]
    public function handleIncludesStylesheetReference(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new ExceptionExplorerController($store, $this->createSafetyMode());

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('href="/studio/assets/studio.css"', $response->body);
    }

    #[Test]
    public function handleIncludesScriptReference(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new ExceptionExplorerController($store, $this->createSafetyMode());

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('src="/studio/assets/main.js"', $response->body);
    }

    #[Test]
    public function handleSetsCorrectPageTitle(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new ExceptionExplorerController($store, $this->createSafetyMode());

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('<title>Exceptions - Pulsar Studio</title>', $response->body);
    }

    #[Test]
    public function handleIncludesDataPayloadAttribute(): void
    {
        $events = [
            [
                'id' => 1,
                'event_id' => 'evt-001',
                'event_type' => 'exception',
            ],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);

        $controller = new ExceptionExplorerController($store, $this->createSafetyMode());

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('data-payload="', $response->body);
    }

    #[Test]
    public function handleReturnsEmptyEventsArrayWhenNoEvents(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new ExceptionExplorerController($store, $this->createSafetyMode());

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('&quot;events&quot;:[]', $response->body);
    }
}
