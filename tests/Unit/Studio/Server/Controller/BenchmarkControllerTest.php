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
use Pulsar\Studio\Console\Aggregation\DashboardAggregator;
use Pulsar\Studio\Console\Storage\SqliteEventStore;
use Pulsar\Studio\Server\Controller\BenchmarkController;

#[CoversClass(BenchmarkController::class)]
final class BenchmarkControllerTest extends TestCase
{
    private function createRequest(): Request
    {
        return new Request(
            method: Method::GET,
            uri: '/studio/console/benchmarks',
            path: '/studio/console/benchmarks',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );
    }

    #[Test]
    public function handleReturnsHtmlResponse(): void
    {
        $store = SqliteEventStore::inMemory();
        $aggregator = new DashboardAggregator($store);

        $controller = new BenchmarkController($aggregator);
        $response = $controller->handle($this->createRequest());

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertSame('text/html; charset=utf-8', $response->contentType());
        self::assertStringContainsString('<!DOCTYPE html>', $response->body);
    }

    #[Test]
    public function handleIncludesPageIdentifier(): void
    {
        $store = SqliteEventStore::inMemory();
        $aggregator = new DashboardAggregator($store);

        $controller = new BenchmarkController($aggregator);
        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('data-page="benchmark-dashboard"', $response->body);
    }

    #[Test]
    public function handleIncludesPayloadAttribute(): void
    {
        $store = SqliteEventStore::inMemory();
        $aggregator = new DashboardAggregator($store);

        $controller = new BenchmarkController($aggregator);
        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('data-payload="', $response->body);
    }

    #[Test]
    public function handleReturnsEmptyRunsWhenNoData(): void
    {
        $store = SqliteEventStore::inMemory();
        $aggregator = new DashboardAggregator($store);

        $controller = new BenchmarkController($aggregator);
        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('&quot;runs&quot;:[]', $response->body);
        self::assertStringContainsString('&quot;latest_profiles&quot;:[]', $response->body);
    }

    #[Test]
    public function handleSetsCorrectPageTitle(): void
    {
        $store = SqliteEventStore::inMemory();
        $aggregator = new DashboardAggregator($store);

        $controller = new BenchmarkController($aggregator);
        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('<title>Benchmarks - Pulsar Studio</title>', $response->body);
    }

    #[Test]
    public function handleIncludesStylesheetAndScript(): void
    {
        $store = SqliteEventStore::inMemory();
        $aggregator = new DashboardAggregator($store);

        $controller = new BenchmarkController($aggregator);
        $response = $controller->handle($this->createRequest());

        self::assertStringContainsString('href="/studio/assets/studio.css"', $response->body);
        self::assertStringContainsString('src="/studio/assets/main.js"', $response->body);
    }
}
