<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Aggregation\DashboardAggregator;
use Pulsar\Extension\Studio\Console\Storage\SqliteEventStore;
use Pulsar\Extension\Studio\Server\Controller\BenchmarkController;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;

#[CoversClass(BenchmarkController::class)]
final class BenchmarkControllerTest extends TestCase
{
    private function createRequest(): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: '/studio/console/benchmarks',
        );
    }

    #[Test]
    public function handleReturnsHtmlResponse(): void
    {
        $store = SqliteEventStore::inMemory();
        $aggregator = new DashboardAggregator($store);

        $controller = new BenchmarkController($aggregator);
        $response = $controller->handle($this->createRequest());

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('<!DOCTYPE html>', (string) $response->getBody());
    }

    #[Test]
    public function handleIncludesPageIdentifier(): void
    {
        $store = SqliteEventStore::inMemory();
        $aggregator = new DashboardAggregator($store);

        $controller = new BenchmarkController($aggregator);
        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('data-page="benchmark-dashboard"', (string) $response->getBody());
    }

    #[Test]
    public function handleIncludesPayloadAttribute(): void
    {
        $store = SqliteEventStore::inMemory();
        $aggregator = new DashboardAggregator($store);

        $controller = new BenchmarkController($aggregator);
        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('data-payload="', (string) $response->getBody());
    }

    #[Test]
    public function handleReturnsEmptyRunsWhenNoData(): void
    {
        $store = SqliteEventStore::inMemory();
        $aggregator = new DashboardAggregator($store);

        $controller = new BenchmarkController($aggregator);
        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('&quot;runs&quot;:[]', (string) $response->getBody());
        self::assertStringContainsString('&quot;latest_profiles&quot;:[]', (string) $response->getBody());
    }

    #[Test]
    public function handleSetsCorrectPageTitle(): void
    {
        $store = SqliteEventStore::inMemory();
        $aggregator = new DashboardAggregator($store);

        $controller = new BenchmarkController($aggregator);
        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('<title>Benchmarks - Pulsar Studio</title>', (string) $response->getBody());
    }

    #[Test]
    public function handleIncludesStylesheetAndScript(): void
    {
        $store = SqliteEventStore::inMemory();
        $aggregator = new DashboardAggregator($store);

        $controller = new BenchmarkController($aggregator);
        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('href="/studio/assets/studio.css"', (string) $response->getBody());
        self::assertStringContainsString('src="/studio/assets/main.js"', (string) $response->getBody());
    }
}
