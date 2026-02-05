<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Storage\SqliteEventStore;
use Pulsar\Extension\Studio\Server\Controller\BenchmarkApiController;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\ResponseStatus;

use function hash;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(BenchmarkApiController::class)]
final class BenchmarkApiControllerTest extends TestCase
{
    private SqliteEventStore $store;
    private BenchmarkApiController $controller;

    protected function setUp(): void
    {
        $this->store = SqliteEventStore::inMemory();
        $this->controller = new BenchmarkApiController($this->store, __DIR__);
    }

    #[Test]
    public function deleteRunsRejectsEmptyRunIds(): void
    {
        $request = $this->createPostRequest(['run_ids' => []]);

        $response = $this->controller->deleteRuns($request);

        self::assertSame(ResponseStatus::BadRequest, $response->status);
    }

    #[Test]
    public function deleteRunsRejectsMissingRunIds(): void
    {
        $request = $this->createPostRequest([]);

        $response = $this->controller->deleteRuns($request);

        self::assertSame(ResponseStatus::BadRequest, $response->status);
    }

    #[Test]
    public function deleteRunsRejectsInvalidFormat(): void
    {
        $request = $this->createPostRequest(['run_ids' => ['INVALID-FORMAT!!']]);

        $response = $this->controller->deleteRuns($request);

        self::assertSame(ResponseStatus::BadRequest, $response->status);
    }

    #[Test]
    public function deleteRunsDeletesMatchingEvents(): void
    {
        $runId = 'abc123def456';

        // Store benchmark.run event
        $this->storeBenchmarkEvent('evt-run-1', EventType::BenchmarkRun, $runId);
        // Store benchmark.profile events
        $this->storeBenchmarkEvent('evt-prof-1', EventType::BenchmarkProfile, $runId);
        $this->storeBenchmarkEvent('evt-prof-2', EventType::BenchmarkProfile, $runId);
        // Store unrelated event
        $this->storeBenchmarkEvent('evt-other', EventType::BenchmarkRun, 'otherid123');

        self::assertSame(4, $this->store->count());

        $request = $this->createPostRequest(['run_ids' => [$runId]]);
        $response = $this->controller->deleteRuns($request);

        self::assertSame(ResponseStatus::OK, $response->status);

        $data = json_decode($response->body, true);
        self::assertIsArray($data);
        self::assertSame(3, $data['deleted']);

        // The unrelated event should remain
        self::assertSame(1, $this->store->count());
    }

    #[Test]
    public function clearHistoryDeletesOnlyBenchmarkEvents(): void
    {
        // Store benchmark events
        $this->storeBenchmarkEvent('evt-run-1', EventType::BenchmarkRun, 'abc123');
        $this->storeBenchmarkEvent('evt-prof-1', EventType::BenchmarkProfile, 'abc123');

        // Store a non-benchmark event
        $this->store->store(
            new EventEnvelope(
                eventId: 'evt-http-1',
                eventType: EventType::HttpRequest,
                schemaVersion: EventVersion::V1,
                timestampUs: (int) (microtime(true) * 1_000_000.0),
                requestId: null,
                traceId: null,
                spanId: null,
                jobId: null,
                appEnv: 'testing',
                hostname: 'localhost',
                payload: ['method' => 'GET'],
                payloadHash: hash('sha256', '{}'),
            ),
            '{"method": "GET"}',
        );

        self::assertSame(3, $this->store->count());

        $request = $this->createPostRequest([]);
        $response = $this->controller->clearHistory($request);

        self::assertSame(ResponseStatus::OK, $response->status);

        $data = json_decode($response->body, true);
        self::assertIsArray($data);
        self::assertSame(2, $data['deleted']);

        // HTTP event should remain
        self::assertSame(1, $this->store->count());
        self::assertNotNull($this->store->find('evt-http-1'));
    }

    private function storeBenchmarkEvent(string $eventId, EventType $type, string $runId): void
    {
        $payload = $type === EventType::BenchmarkRun
            ? ['run_id' => $runId, 'profile_count' => 1, 'success_count' => 1, 'failure_count' => 0, 'skipped_count' => 0, 'total_duration_ms' => 100.0, 'php_version' => '8.5.0']
            : ['run_id' => $runId, 'profile_name' => 'default', 'boot_us' => 100, 'warm_boot_us' => 50, 'p50_us' => 80, 'p95_us' => 120, 'rps' => 5000, 'peak_rss_kb' => 10240, 'memory_usage_kb' => 8192, 'opcache_memory_kb' => null, 'optimize_enabled' => false];

        $this->store->store(
            new EventEnvelope(
                eventId: $eventId,
                eventType: $type,
                schemaVersion: EventVersion::V1,
                timestampUs: (int) (microtime(true) * 1_000_000.0),
                requestId: null,
                traceId: null,
                spanId: null,
                jobId: null,
                appEnv: 'testing',
                hostname: 'localhost',
                payload: $payload,
                payloadHash: hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            ),
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    private function createPostRequest(mixed $body): Request
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR);

        return new Request(
            method: Method::POST,
            uri: '/studio/api/benchmark/delete',
            path: '/studio/api/benchmark/delete',
            queryString: '',
            headers: new HeaderBag(['Content-Type' => 'application/json']),
            body: $json,
        );
    }
}
