<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\Server\Controller\DatabaseExplorerController;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\ResponseStatus;

#[CoversClass(DatabaseExplorerController::class)]
final class DatabaseExplorerControllerTest extends TestCase
{
    private function createRequest(): Request
    {
        return new Request(
            method: Method::GET,
            uri: '/studio/console/database',
            path: '/studio/console/database',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );
    }

    #[Test]
    public function handleReturnsEmptyStateWhenNoEventsExist(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new DatabaseExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertSame('text/html; charset=utf-8', $response->contentType());
        self::assertStringContainsString('No Database Query Events', $response->body);
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

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertSame('text/html; charset=utf-8', $response->contentType());
        self::assertStringContainsString('data-page="database-explorer"', $response->body);
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

        self::assertStringContainsString('evt-001', $response->body);
        self::assertStringContainsString('db.query', $response->body);
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

        self::assertStringNotContainsString('<script>alert(1)</script>', $response->body);
    }

    #[Test]
    public function handleEmptyStateIncludesNavigationLinks(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new DatabaseExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('href="/studio"', $response->body);
        self::assertStringContainsString('href="/studio/console"', $response->body);
        self::assertStringContainsString('href="/studio/console/requests"', $response->body);
        self::assertStringContainsString('href="/studio/console/logs"', $response->body);
        self::assertStringContainsString('href="/studio/console/exceptions"', $response->body);
    }

    #[Test]
    public function handleEmptyStateHighlightsDatabaseInNav(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new DatabaseExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('href="/studio/console/database" class="active"', $response->body);
    }

    #[Test]
    public function handleEmptyStateIncludesBackToConsoleLink(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new DatabaseExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('Back to Console Overview', $response->body);
        self::assertStringContainsString('class="btn"', $response->body);
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

        self::assertStringContainsString('href="/studio/assets/studio.css"', $response->body);
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

        self::assertStringContainsString('src="/studio/assets/main.js"', $response->body);
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

        self::assertStringContainsString('<title>Database Queries - Pulsar Studio</title>', $response->body);
    }

    #[Test]
    public function handleEmptyStateSetsCorrectPageTitle(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $controller = new DatabaseExplorerController($store);

        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('<title>Database Queries - Pulsar Studio</title>', $response->body);
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
