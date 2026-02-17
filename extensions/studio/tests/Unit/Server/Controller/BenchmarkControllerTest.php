<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Extension\Studio\Console\Aggregation\DashboardAggregator;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\Console\Storage\SqliteEventStore;
use Pulsar\Extension\Studio\Server\Controller\BenchmarkApiController;

#[CoversClass(BenchmarkApiController::class)]
final class BenchmarkControllerTest extends TestCase
{
    private function createAggregator(): DashboardAggregator
    {
        $store = new SqliteEventStore(':memory:');

        return new DashboardAggregator($store);
    }

    #[Test]
    public function deleteRunsRejectsMissingRunIds(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $aggregator = $this->createAggregator();

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([]);

        $controller = new BenchmarkApiController($store, $aggregator, '/tmp');
        $response = $controller->deleteRuns($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function deleteRunsRejectsInvalidRunIdFormat(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $aggregator = $this->createAggregator();

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'run_ids' => ['INVALID-ID!', '///bad///'],
        ]);

        $controller = new BenchmarkApiController($store, $aggregator, '/tmp');
        $response = $controller->deleteRuns($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function deleteRunsDeletesValidIds(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('deleteByPayloadKey')->willReturn(2);
        $aggregator = $this->createAggregator();

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'run_ids' => ['abc123', 'def456'],
        ]);

        $controller = new BenchmarkApiController($store, $aggregator, '/tmp');
        $response = $controller->deleteRuns($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(8, $data['deleted']);
    }

    #[Test]
    public function clearHistoryDeletesBenchmarkEventTypes(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('deleteByEventTypes')->willReturn(15);
        $aggregator = $this->createAggregator();

        $request = $this->createStub(ServerRequestInterface::class);

        $controller = new BenchmarkApiController($store, $aggregator, '/tmp');
        $response = $controller->clearHistory($request);

        $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(15, $data['deleted']);
    }

    #[Test]
    public function profilesRejectsEmptyRunId(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $aggregator = $this->createAggregator();

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['run_id' => '']);

        $controller = new BenchmarkApiController($store, $aggregator, '/tmp');
        $response = $controller->profiles($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function profilesRejectsInvalidRunIdFormat(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $aggregator = $this->createAggregator();

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['run_id' => 'XYZ-INVALID!']);

        $controller = new BenchmarkApiController($store, $aggregator, '/tmp');
        $response = $controller->profiles($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function profilesReturnsProfilesForValidRunId(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $aggregator = $this->createAggregator();

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['run_id' => 'abc123def']);

        $controller = new BenchmarkApiController($store, $aggregator, '/tmp');
        $response = $controller->profiles($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('profiles', $data);
    }
}
