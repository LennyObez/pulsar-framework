<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Server;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Extension\Studio\Config\StudioCollectorConfig;
use Pulsar\Extension\Studio\Config\StudioConfig;
use Pulsar\Extension\Studio\Config\StudioRetentionConfig;
use Pulsar\Extension\Studio\Console\Aggregation\DashboardAggregator;
use Pulsar\Extension\Studio\Console\Aggregation\TimelineBuilder;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\Console\Storage\SqliteEventStore;
use Pulsar\Extension\Studio\Security\ProductionSafetyMode;
use Pulsar\Extension\Studio\Server\Controller\ApiController;
use Pulsar\Extension\Studio\Server\Controller\BenchmarkApiController;
use Pulsar\Extension\Studio\Server\Controller\BenchmarkController;
use Pulsar\Extension\Studio\Server\Controller\ConsoleOverviewController;
use Pulsar\Extension\Studio\Server\Controller\DatabaseExplorerController;
use Pulsar\Extension\Studio\Server\Controller\ExceptionExplorerController;
use Pulsar\Extension\Studio\Server\Controller\LandingController;
use Pulsar\Extension\Studio\Server\Controller\LogExplorerController;
use Pulsar\Extension\Studio\Server\Controller\RequestExplorerController;
use Pulsar\Extension\Studio\Server\Controller\TimelineController;
use Pulsar\Extension\Studio\Server\StudioRouter;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;

#[CoversClass(StudioRouter::class)]
final class StudioRouterTest extends TestCase
{
    private EventStoreInterface $store;
    private ProductionSafetyMode $localSafetyMode;
    private ProductionSafetyMode $productionSafetyMode;
    private ProductionSafetyMode $stagingSafetyMode;

    protected function setUp(): void
    {
        $this->store = SqliteEventStore::inMemory();
        $this->localSafetyMode = new ProductionSafetyMode(EnvironmentMode::Local);
        $this->productionSafetyMode = new ProductionSafetyMode(EnvironmentMode::Production);
        $this->stagingSafetyMode = new ProductionSafetyMode(EnvironmentMode::Staging);
    }

    #[Test]
    public function dispatchRoutesToLandingController(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertStringContainsString('Pulsar Studio', (string) $response->getBody());
    }

    #[Test]
    public function dispatchRoutesToConsoleOverviewController(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio/console');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertStringContainsString('console-overview', (string) $response->getBody());
    }

    #[Test]
    public function dispatchRoutesToRequestExplorerController(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio/console/requests');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertStringContainsString('request-explorer', (string) $response->getBody());
    }

    #[Test]
    public function dispatchRoutesToDatabaseExplorerController(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio/console/database');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function dispatchRoutesToLogExplorerController(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio/console/logs');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertStringContainsString('log-explorer', (string) $response->getBody());
    }

    #[Test]
    public function dispatchRoutesToExceptionExplorerController(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio/console/exceptions');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertStringContainsString('exception-explorer', (string) $response->getBody());
    }

    #[Test]
    public function dispatchRoutesToTimelineControllerWithHexId(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio/console/timeline/abc123def456');

        $response = $router->dispatch($request);

        // Should return 404 since no events exist for this correlation ID
        self::assertSame(ResponseStatus::NotFound->value, $response->getStatusCode());
    }

    #[Test]
    public function dispatchRoutesToApiEventsEndpoint(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio/api/events');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function dispatchReturnsNotFoundForUnknownStudioPath(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio/unknown/path');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::NotFound->value, $response->getStatusCode());
        self::assertSame('Not Found', (string) $response->getBody());
    }

    #[Test]
    public function dispatchReturnsNotFoundForNonStudioPath(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/other/path');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::NotFound->value, $response->getStatusCode());
    }

    #[Test]
    public function dispatchReturnsMethodNotAllowedForPostRequest(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio', 'POST');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::MethodNotAllowed->value, $response->getStatusCode());
        self::assertSame('Method Not Allowed', (string) $response->getBody());
        self::assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
    }

    #[Test]
    public function dispatchReturnsMethodNotAllowedForPutRequest(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio', 'PUT');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::MethodNotAllowed->value, $response->getStatusCode());
    }

    #[Test]
    public function dispatchReturnsMethodNotAllowedForDeleteRequest(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio', 'DELETE');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::MethodNotAllowed->value, $response->getStatusCode());
    }

    #[Test]
    public function dispatchAllowsHeadRequest(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio', 'HEAD');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function dispatchNormalizesPathWithTrailingSlash(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio/');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertStringContainsString('Pulsar Studio', (string) $response->getBody());
    }

    #[Test]
    public function dispatchBlocksDrillDownInProductionMode(): void
    {
        $router = $this->createRouter($this->productionSafetyMode);
        $request = $this->createRequest('/studio/console/requests');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function dispatchBlocksDatabaseExplorerInProductionMode(): void
    {
        $router = $this->createRouter($this->productionSafetyMode);
        $request = $this->createRequest('/studio/console/database');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function dispatchBlocksLogExplorerInProductionMode(): void
    {
        $router = $this->createRouter($this->productionSafetyMode);
        $request = $this->createRequest('/studio/console/logs');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function dispatchBlocksTimelineInProductionMode(): void
    {
        $router = $this->createRouter($this->productionSafetyMode);
        $request = $this->createRequest('/studio/console/timeline/abc123');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function dispatchBlocksApiEventsInProductionMode(): void
    {
        $router = $this->createRouter($this->productionSafetyMode);
        $request = $this->createRequest('/studio/api/events');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function dispatchBlocksSseInProductionMode(): void
    {
        $router = $this->createRouter($this->productionSafetyMode);
        $request = $this->createRequest('/studio/api/live');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function dispatchAllowsLandingInProductionMode(): void
    {
        $router = $this->createRouter($this->productionSafetyMode);
        $request = $this->createRequest('/studio');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function dispatchAllowsConsoleOverviewInProductionMode(): void
    {
        $router = $this->createRouter($this->productionSafetyMode);
        $request = $this->createRequest('/studio/console');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function dispatchAllowsExceptionExplorerInProductionMode(): void
    {
        $router = $this->createRouter($this->productionSafetyMode);
        $request = $this->createRequest('/studio/console/exceptions');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function dispatchAllowsDrillDownInStagingMode(): void
    {
        $router = $this->createRouter($this->stagingSafetyMode);
        $request = $this->createRequest('/studio/console/requests');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function timelineRouteDoesNotMatchInvalidId(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio/console/timeline/invalid-id-with-dashes');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::NotFound->value, $response->getStatusCode());
    }

    #[Test]
    public function timelineRouteDoesNotMatchUppercaseHex(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio/console/timeline/ABC123DEF456');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::NotFound->value, $response->getStatusCode());
    }

    #[Test]
    public function timelineRouteDoesNotMatchEmptyId(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio/console/timeline/');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::NotFound->value, $response->getStatusCode());
    }

    #[Test]
    public function dispatchBlocksDrillDownErrorMessageInProduction(): void
    {
        $router = $this->createRouter($this->productionSafetyMode);
        $request = $this->createRequest('/studio/console/requests');

        $response = $router->dispatch($request);

        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertSame('Drill-down views are disabled in production mode', $data['error']);
    }

    #[Test]
    public function dispatchBlocksApiErrorMessageInProduction(): void
    {
        $router = $this->createRouter($this->productionSafetyMode);
        $request = $this->createRequest('/studio/api/events');

        $response = $router->dispatch($request);

        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertSame('API access is disabled in production mode', $data['error']);
    }

    #[Test]
    public function dispatchBlocksSseErrorMessageInProduction(): void
    {
        $router = $this->createRouter($this->productionSafetyMode);
        $request = $this->createRequest('/studio/api/live');

        $response = $router->dispatch($request);

        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertSame('SSE live stream is disabled in production mode', $data['error']);
    }

    #[Test]
    public function dispatchRoutesToBenchmarkDashboard(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio/console/benchmarks');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertStringContainsString('benchmark-dashboard', (string) $response->getBody());
    }

    #[Test]
    public function dispatchBlocksBenchmarkDashboardInProductionMode(): void
    {
        $router = $this->createRouter($this->productionSafetyMode);
        $request = $this->createRequest('/studio/console/benchmarks');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function dispatchPostBenchmarkClearInLocalMode(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createPostJsonRequest('/studio/api/benchmark/clear', '{}');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('deleted', $data);
    }

    #[Test]
    public function dispatchPostBlockedInProductionMode(): void
    {
        $router = $this->createRouter($this->productionSafetyMode);
        $request = $this->createPostJsonRequest('/studio/api/benchmark/clear', '{}');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertSame('Mutable API actions are restricted to local development mode', $data['error']);
    }

    #[Test]
    public function dispatchPostBlockedInStagingMode(): void
    {
        $router = $this->createRouter($this->stagingSafetyMode);
        $request = $this->createPostJsonRequest('/studio/api/benchmark/run', '{}');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function dispatchPostToUnknownPathReturns405(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createPostJsonRequest('/studio/console', '{}');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::MethodNotAllowed->value, $response->getStatusCode());
    }

    private function createRouter(ProductionSafetyMode $safetyMode): StudioRouter
    {
        $studioConfig = new StudioConfig(
            enabled: true,
            storagePath: ':memory:',
            retention: new StudioRetentionConfig(),
            collectors: new StudioCollectorConfig(),
            samplingRate: 1.0,
        );

        $aggregator = new DashboardAggregator($this->store);

        return new StudioRouter(
            landing: new LandingController($studioConfig, $this->store),
            consoleOverview: new ConsoleOverviewController($aggregator),
            requestExplorer: new RequestExplorerController($this->store),
            databaseExplorer: new DatabaseExplorerController($this->store),
            logExplorer: new LogExplorerController($this->store),
            exceptionExplorer: new ExceptionExplorerController($this->store, $safetyMode),
            timeline: new TimelineController(new TimelineBuilder($this->store)),
            api: new ApiController($this->store),
            benchmark: new BenchmarkController($aggregator),
            benchmarkApi: new BenchmarkApiController($this->store, __DIR__),
            safetyMode: $safetyMode,
        );
    }

    private function createRequest(string $path, string $method = 'GET'): ServerRequest
    {
        return new ServerRequest(
            method: $method,
            uri: $path,
        );
    }

    private function createPostJsonRequest(string $path, string $body): ServerRequest
    {
        return new ServerRequest(
            method: 'POST',
            uri: $path,
            headers: ['Content-Type' => 'application/json'],
            body: $body,
        );
    }
}
