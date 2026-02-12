<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\Server\Controller\DatabaseExplorerController;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;

#[CoversClass(DatabaseExplorerController::class)]
final class DatabaseExplorerControllerTest extends TestCase
{
    private function createRequest(): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: '/studio/console/database',
        );
    }

    #[Test]
    public function handleReturnsEmptyStateWhenNoEventsExist(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new DatabaseExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('No database query events', (string) $response->getBody());
    }

    #[Test]
    public function handleReturnsAppViewWithEventsWhenFound(): void
    {
        $events = [
            [
                'id' => 1,
                'event_id' => 'evt-001',
                'event_type' => 'db.query',
                'timestamp_us' => 1700000000000000,
                'payload' => '{"query":"SELECT * FROM users"}',
            ],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);

        $controller = new DatabaseExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('data-page="database-explorer"', (string) $response->getBody());
    }

    #[Test]
    public function handleIncludesEventsInPayload(): void
    {
        $events = [
            [
                'id' => 1,
                'event_id' => 'evt-001',
                'event_type' => 'db.query',
                'payload' => '{"query":"SELECT 1"}',
            ],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);

        $controller = new DatabaseExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('evt-001', (string) $response->getBody());
        self::assertStringContainsString('db.query', (string) $response->getBody());
    }

    #[Test]
    public function handleEscapesPayloadForHtmlAttribute(): void
    {
        $events = [
            [
                'id' => 1,
                'event_id' => 'evt-001',
                'event_type' => 'db.query',
                'payload' => '{"query":"SELECT * FROM users WHERE name = \'<script>alert(1)</script>\'"}',
            ],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);

        $controller = new DatabaseExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertStringNotContainsString('<script>alert(1)</script>', (string) $response->getBody());
    }

    #[Test]
    public function handleEmptyStateIncludesNavigationLinks(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new DatabaseExplorerController($store);

        $response = $controller->handle($this->createRequest());

        $body = (string) $response->getBody();
        self::assertStringContainsString('href="/studio"', $body);
        self::assertStringContainsString('href="/studio/console"', $body);
        self::assertStringContainsString('href="/studio/console/requests"', $body);
        self::assertStringContainsString('href="/studio/console/logs"', $body);
        self::assertStringContainsString('href="/studio/console/exceptions"', $body);
    }

    #[Test]
    public function handleEmptyStateHighlightsDatabaseInNav(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new DatabaseExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('href="/studio/console/database" class="active"', (string) $response->getBody());
    }

    #[Test]
    public function handleEmptyStateIncludesBackToConsoleLink(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new DatabaseExplorerController($store);

        $response = $controller->handle($this->createRequest());

        $body = (string) $response->getBody();
        self::assertStringContainsString('Back to console overview', $body);
        self::assertStringContainsString('class="btn"', $body);
    }

    #[Test]
    public function handleIncludesStylesheetReference(): void
    {
        $events = [
            [
                'id' => 1,
                'event_id' => 'evt-001',
                'event_type' => 'db.query',
            ],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);

        $controller = new DatabaseExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('href="/studio/assets/studio.css"', (string) $response->getBody());
    }

    #[Test]
    public function handleIncludesScriptReferenceWhenEventsExist(): void
    {
        $events = [
            [
                'id' => 1,
                'event_id' => 'evt-001',
                'event_type' => 'db.query',
            ],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);

        $controller = new DatabaseExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('src="/studio/assets/main.js"', (string) $response->getBody());
    }

    #[Test]
    public function handleSetsCorrectPageTitle(): void
    {
        $events = [
            [
                'id' => 1,
                'event_id' => 'evt-001',
                'event_type' => 'db.query',
            ],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);

        $controller = new DatabaseExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('<title>Database queries - Pulsar Studio</title>', (string) $response->getBody());
    }

    #[Test]
    public function handleEmptyStateSetsCorrectPageTitle(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new DatabaseExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('<title>Database queries - Pulsar Studio</title>', (string) $response->getBody());
    }

    #[Test]
    public function handleQueriesStoreWithCorrectEventTypeFilter(): void
    {
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with(['event_type' => ['db.query']], 100)
            ->willReturn([]);

        $controller = new DatabaseExplorerController($store);

        $controller->handle($this->createRequest());
    }
}
