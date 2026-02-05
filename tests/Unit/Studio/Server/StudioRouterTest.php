<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Server;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Config\StudioCollectorConfig;
use Pulsar\Config\StudioConfig;
use Pulsar\Config\StudioRetentionConfig;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\ResponseStatus;
use Pulsar\Studio\Console\Aggregation\DashboardAggregator;
use Pulsar\Studio\Console\Aggregation\TimelineBuilder;
use Pulsar\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Studio\Console\Storage\SqliteEventStore;
use Pulsar\Studio\Security\ProductionSafetyMode;
use Pulsar\Studio\Server\Controller\ApiController;
use Pulsar\Studio\Server\Controller\ConsoleOverviewController;
use Pulsar\Studio\Server\Controller\DatabaseExplorerController;
use Pulsar\Studio\Server\Controller\ExceptionExplorerController;
use Pulsar\Studio\Server\Controller\LandingController;
use Pulsar\Studio\Server\Controller\LogExplorerController;
use Pulsar\Studio\Server\Controller\RequestExplorerController;
use Pulsar\Studio\Server\Controller\TimelineController;
use Pulsar\Studio\Server\StudioRouter;

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

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertStringContainsString('Pulsar Studio', $response->body);
    }

    #[Test]
    public function dispatchRoutesToConsoleOverviewController(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio/console');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertStringContainsString('console-overview', $response->body);
    }

    #[Test]
    public function dispatchRoutesToRequestExplorerController(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio/console/requests');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertStringContainsString('request-explorer', $response->body);
    }

    #[Test]
    public function dispatchRoutesToDatabaseExplorerController(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio/console/database');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::OK, $response->status);
    }

    #[Test]
    public function dispatchRoutesToLogExplorerController(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio/console/logs');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertStringContainsString('log-explorer', $response->body);
    }

    #[Test]
    public function dispatchRoutesToExceptionExplorerController(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio/console/exceptions');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertStringContainsString('exception-explorer', $response->body);
    }

    #[Test]
    public function dispatchRoutesToTimelineControllerWithHexId(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio/console/timeline/abc123def456');

        $response = $router->dispatch($request);

        // Should return 404 since no events exist for this correlation ID
        self::assertSame(ResponseStatus::NotFound, $response->status);
    }

    #[Test]
    public function dispatchRoutesToApiEventsEndpoint(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio/api/events');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->contentType());
    }

    #[Test]
    public function dispatchReturnsNotFoundForUnknownStudioPath(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio/unknown/path');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::NotFound, $response->status);
        self::assertSame('Not Found', $response->body);
    }

    #[Test]
    public function dispatchReturnsNotFoundForNonStudioPath(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/other/path');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::NotFound, $response->status);
    }

    #[Test]
    public function dispatchReturnsMethodNotAllowedForPostRequest(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio', Method::POST);

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::MethodNotAllowed, $response->status);
        self::assertSame('Method Not Allowed', $response->body);
        self::assertSame('GET, HEAD', $response->headers->first('Allow'));
    }

    #[Test]
    public function dispatchReturnsMethodNotAllowedForPutRequest(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio', Method::PUT);

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::MethodNotAllowed, $response->status);
    }

    #[Test]
    public function dispatchReturnsMethodNotAllowedForDeleteRequest(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio', Method::DELETE);

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::MethodNotAllowed, $response->status);
    }

    #[Test]
    public function dispatchAllowsHeadRequest(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio', Method::HEAD);

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::OK, $response->status);
    }

    #[Test]
    public function dispatchNormalizesPathWithTrailingSlash(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio/');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertStringContainsString('Pulsar Studio', $response->body);
    }

    #[Test]
    public function dispatchBlocksDrillDownInProductionMode(): void
    {
        $router = $this->createRouter($this->productionSafetyMode);
        $request = $this->createRequest('/studio/console/requests');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::Forbidden, $response->status);
    }

    #[Test]
    public function dispatchBlocksDatabaseExplorerInProductionMode(): void
    {
        $router = $this->createRouter($this->productionSafetyMode);
        $request = $this->createRequest('/studio/console/database');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::Forbidden, $response->status);
    }

    #[Test]
    public function dispatchBlocksLogExplorerInProductionMode(): void
    {
        $router = $this->createRouter($this->productionSafetyMode);
        $request = $this->createRequest('/studio/console/logs');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::Forbidden, $response->status);
    }

    #[Test]
    public function dispatchBlocksTimelineInProductionMode(): void
    {
        $router = $this->createRouter($this->productionSafetyMode);
        $request = $this->createRequest('/studio/console/timeline/abc123');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::Forbidden, $response->status);
    }

    #[Test]
    public function dispatchBlocksApiEventsInProductionMode(): void
    {
        $router = $this->createRouter($this->productionSafetyMode);
        $request = $this->createRequest('/studio/api/events');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::Forbidden, $response->status);
    }

    #[Test]
    public function dispatchBlocksSseInProductionMode(): void
    {
        $router = $this->createRouter($this->productionSafetyMode);
        $request = $this->createRequest('/studio/api/live');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::Forbidden, $response->status);
    }

    #[Test]
    public function dispatchAllowsLandingInProductionMode(): void
    {
        $router = $this->createRouter($this->productionSafetyMode);
        $request = $this->createRequest('/studio');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::OK, $response->status);
    }

    #[Test]
    public function dispatchAllowsConsoleOverviewInProductionMode(): void
    {
        $router = $this->createRouter($this->productionSafetyMode);
        $request = $this->createRequest('/studio/console');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::OK, $response->status);
    }

    #[Test]
    public function dispatchAllowsExceptionExplorerInProductionMode(): void
    {
        $router = $this->createRouter($this->productionSafetyMode);
        $request = $this->createRequest('/studio/console/exceptions');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::OK, $response->status);
    }

    #[Test]
    public function dispatchAllowsDrillDownInStagingMode(): void
    {
        $router = $this->createRouter($this->stagingSafetyMode);
        $request = $this->createRequest('/studio/console/requests');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::OK, $response->status);
    }

    #[Test]
    public function timelineRouteDoesNotMatchInvalidId(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio/console/timeline/invalid-id-with-dashes');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::NotFound, $response->status);
    }

    #[Test]
    public function timelineRouteDoesNotMatchUppercaseHex(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio/console/timeline/ABC123DEF456');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::NotFound, $response->status);
    }

    #[Test]
    public function timelineRouteDoesNotMatchEmptyId(): void
    {
        $router = $this->createRouter($this->localSafetyMode);
        $request = $this->createRequest('/studio/console/timeline/');

        $response = $router->dispatch($request);

        self::assertSame(ResponseStatus::NotFound, $response->status);
    }

    #[Test]
    public function dispatchBlocksDrillDownErrorMessageInProduction(): void
    {
        $router = $this->createRouter($this->productionSafetyMode);
        $request = $this->createRequest('/studio/console/requests');

        $response = $router->dispatch($request);

        $data = json_decode($response->body, true);
        self::assertIsArray($data);
        self::assertSame('Drill-down views are disabled in production mode', $data['error']);
    }

    #[Test]
    public function dispatchBlocksApiErrorMessageInProduction(): void
    {
        $router = $this->createRouter($this->productionSafetyMode);
        $request = $this->createRequest('/studio/api/events');

        $response = $router->dispatch($request);

        $data = json_decode($response->body, true);
        self::assertIsArray($data);
        self::assertSame('API access is disabled in production mode', $data['error']);
    }

    #[Test]
    public function dispatchBlocksSseErrorMessageInProduction(): void
    {
        $router = $this->createRouter($this->productionSafetyMode);
        $request = $this->createRequest('/studio/api/live');

        $response = $router->dispatch($request);

        $data = json_decode($response->body, true);
        self::assertIsArray($data);
        self::assertSame('SSE live stream is disabled in production mode', $data['error']);
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

        return new StudioRouter(
            landing: new LandingController($studioConfig, $this->store),
            consoleOverview: new ConsoleOverviewController(new DashboardAggregator($this->store)),
            requestExplorer: new RequestExplorerController($this->store),
            databaseExplorer: new DatabaseExplorerController($this->store),
            logExplorer: new LogExplorerController($this->store),
            exceptionExplorer: new ExceptionExplorerController($this->store, $safetyMode),
            timeline: new TimelineController(new TimelineBuilder($this->store)),
            api: new ApiController($this->store),
            safetyMode: $safetyMode,
        );
    }

    private function createRequest(string $path, Method $method = Method::GET): Request
    {
        return new Request(
            method: $method,
            uri: $path,
            path: $path,
            queryString: '',
            headers: new HeaderBag([]),
            body: '',
        );
    }
}
