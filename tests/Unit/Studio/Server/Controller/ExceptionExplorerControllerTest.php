<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Config\I18nConfig;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\Security\ProductionSafetyMode;
use Pulsar\Extension\Studio\Server\Controller\ExceptionExplorerController;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;
use Pulsar\I18n\CatalogInterface;
use Pulsar\I18n\Translator;

#[CoversClass(ExceptionExplorerController::class)]
final class ExceptionExplorerControllerTest extends TestCase
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
            uri: '/studio/console/exceptions',
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

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('<!DOCTYPE html>', (string) $response->getBody());
    }

    #[Test]
    public function handleIncludesPageIdentifier(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new ExceptionExplorerController($store, $this->createSafetyMode());

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('data-page="exception-explorer"', (string) $response->getBody());
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

        $body = (string) $response->getBody();
        self::assertStringContainsString('evt-001', $body);
        self::assertStringContainsString('exception', $body);
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

        $body = (string) $response->getBody();
        self::assertStringContainsString('show_traces', $body);
        self::assertStringContainsString('true', $body);
    }

    #[Test]
    public function handleIncludesShowTracesAsFalseInStagingMode(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $safetyMode = $this->createSafetyMode(EnvironmentMode::Staging);
        $controller = new ExceptionExplorerController($store, $safetyMode);

        $response = $controller->handle($this->createRequest());

        $body = (string) $response->getBody();
        self::assertStringContainsString('show_traces', $body);
        self::assertStringContainsString('&quot;show_traces&quot;:false', $body);
    }

    #[Test]
    public function handleIncludesShowTracesAsFalseInProductionMode(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $safetyMode = $this->createSafetyMode(EnvironmentMode::Production);
        $controller = new ExceptionExplorerController($store, $safetyMode);

        $response = $controller->handle($this->createRequest());

        $body = (string) $response->getBody();
        self::assertStringContainsString('show_traces', $body);
        self::assertStringContainsString('&quot;show_traces&quot;:false', $body);
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

        self::assertStringNotContainsString('<script>alert(1)</script>', (string) $response->getBody());
    }

    #[Test]
    public function handleIncludesStylesheetReference(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new ExceptionExplorerController($store, $this->createSafetyMode());

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('href="/studio/assets/studio.css"', (string) $response->getBody());
    }

    #[Test]
    public function handleIncludesScriptReference(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new ExceptionExplorerController($store, $this->createSafetyMode());

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('src="/studio/assets/main.js"', (string) $response->getBody());
    }

    #[Test]
    public function handleSetsCorrectPageTitle(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new ExceptionExplorerController($store, $this->createSafetyMode());

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('<title>Exceptions - Pulsar Studio</title>', (string) $response->getBody());
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

        self::assertStringContainsString("data-payload='", (string) $response->getBody());
    }

    #[Test]
    public function handleReturnsEmptyEventsArrayWhenNoEvents(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new ExceptionExplorerController($store, $this->createSafetyMode());

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('&quot;events&quot;:[]', (string) $response->getBody());
    }
}
