<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Aggregation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Aggregation\DashboardAggregator;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Storage\SqliteEventStore;

use function json_encode;
use function microtime;

use const JSON_THROW_ON_ERROR;

#[CoversClass(DashboardAggregator::class)]
final class DashboardAggregatorTest extends TestCase
{
    private SqliteEventStore $store;
    private DashboardAggregator $aggregator;

    protected function setUp(): void
    {
        $this->store = SqliteEventStore::inMemory();
        $this->aggregator = new DashboardAggregator($this->store);
    }

    private function nowUs(): int
    {
        return (int) (microtime(true) * 1_000_000.0);
    }

    /** @param array<string, mixed> $payload */
    private function insertEvent(string $eventType, array $payload, ?int $timestampUs = null): void
    {
        $timestampUs ??= $this->nowUs();
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
        $eventId = bin2hex(random_bytes(16));

        $envelope = new EventEnvelope(
            eventId: $eventId,
            eventType: EventType::from($eventType),
            schemaVersion: EventVersion::V1,
            timestampUs: $timestampUs,
            requestId: null,
            traceId: null,
            spanId: null,
            jobId: null,
            appEnv: 'test',
            hostname: 'localhost',
            payload: $payload,
            payloadHash: hash('sha256', $payloadJson),
        );

        $this->store->store($envelope, $payloadJson);
    }

    #[Test]
    public function availableSectionsReturnsEmptyForEmptyStore(): void
    {
        self::assertSame([], $this->aggregator->availableSections());
    }

    #[Test]
    public function availableSectionsReturnsSectionsForExistingEventTypes(): void
    {
        $this->insertEvent('http.response', ['status_code' => 200, 'duration_ms' => 10.0]);
        $this->insertEvent('exception', ['exception_class' => 'RuntimeException']);
        $this->insertEvent('db.query', ['sql' => 'SELECT 1', 'duration_ms' => 1.0]);

        $sections = $this->aggregator->availableSections();

        self::assertContains('throughput', $sections);
        self::assertContains('latency', $sections);
        self::assertContains('error_rate', $sections);
        self::assertContains('slow_routes', $sections);
        self::assertContains('slow_queries', $sections);
        self::assertNotContains('scheduler', $sections);
    }

    #[Test]
    public function throughputReturnsCorrectMetrics(): void
    {
        $this->insertEvent('http.response', ['status_code' => 200, 'duration_ms' => 10.0]);
        $this->insertEvent('http.response', ['status_code' => 200, 'duration_ms' => 20.0]);
        $this->insertEvent('http.response', ['status_code' => 404, 'duration_ms' => 5.0]);

        $windowUs = 5 * 60 * 1_000_000;
        $result = $this->aggregator->throughput($windowUs);

        self::assertSame(3, $result['total']);
        self::assertGreaterThan(0.0, $result['per_minute']);
        self::assertArrayHasKey('by_status', $result);
        self::assertArrayHasKey('2xx', $result['by_status']);
        self::assertSame(2, $result['by_status']['2xx']);
        self::assertArrayHasKey('4xx', $result['by_status']);
        self::assertSame(1, $result['by_status']['4xx']);
    }

    #[Test]
    public function latencyPercentilesReturnsCorrectValues(): void
    {
        for ($i = 1; $i <= 100; $i++) {
            $this->insertEvent('http.response', ['status_code' => 200, 'duration_ms' => (float) $i]);
        }

        $windowUs = 5 * 60 * 1_000_000;
        $result = $this->aggregator->latencyPercentiles($windowUs);

        self::assertArrayHasKey('p50', $result);
        self::assertArrayHasKey('p95', $result);
        self::assertArrayHasKey('p99', $result);
        self::assertGreaterThan(0.0, $result['p50']);
        self::assertGreaterThan($result['p50'], $result['p95']);
        self::assertGreaterThanOrEqual($result['p95'], $result['p99']);
    }

    #[Test]
    public function latencyPercentilesReturnsZerosWhenNoEvents(): void
    {
        $windowUs = 5 * 60 * 1_000_000;
        $result = $this->aggregator->latencyPercentiles($windowUs);

        self::assertSame(['p50' => 0.0, 'p95' => 0.0, 'p99' => 0.0], $result);
    }

    #[Test]
    public function errorRateReturnsCorrectMetrics(): void
    {
        $this->insertEvent('exception', ['exception_class' => 'RuntimeException', 'message' => 'error 1']);
        $this->insertEvent('exception', ['exception_class' => 'RuntimeException', 'message' => 'error 2']);
        $this->insertEvent('exception', ['exception_class' => 'LogicException', 'message' => 'error 3']);

        $windowUs = 5 * 60 * 1_000_000;
        $result = $this->aggregator->errorRate($windowUs);

        self::assertSame(3, $result['total']);
        self::assertGreaterThan(0.0, $result['per_minute']);
        self::assertNotEmpty($result['top_exceptions']);
        self::assertSame('RuntimeException', $result['top_exceptions'][0]['class']);
        self::assertSame(2, $result['top_exceptions'][0]['count']);
    }

    #[Test]
    public function slowRoutesGroupsByRouteAndSortsByP95(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->insertEvent('http.response', [
                'status_code' => 200,
                'duration_ms' => 10.0 + (float) $i,
                'route_name' => 'api.fast',
            ]);
        }

        for ($i = 0; $i < 5; $i++) {
            $this->insertEvent('http.response', [
                'status_code' => 200,
                'duration_ms' => 100.0 + (float) $i,
                'route_name' => 'api.slow',
            ]);
        }

        $windowUs = 5 * 60 * 1_000_000;
        $result = $this->aggregator->slowRoutes($windowUs);

        self::assertCount(2, $result);
        self::assertSame('api.slow', $result[0]['route']);
        self::assertSame('api.fast', $result[1]['route']);
        self::assertSame(5, $result[0]['count']);
        self::assertGreaterThan($result[1]['p95_ms'], $result[0]['p95_ms']);
    }

    #[Test]
    public function slowQueriesGroupsByFingerprintAndSortsByP95(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->insertEvent('db.query', [
                'sql' => 'SELECT * FROM users WHERE id = ?',
                'sql_fingerprint' => 'fp_users',
                'duration_ms' => 5.0 + (float) $i,
            ]);
        }

        for ($i = 0; $i < 3; $i++) {
            $this->insertEvent('db.query', [
                'sql' => 'SELECT * FROM orders WHERE user_id = ?',
                'sql_fingerprint' => 'fp_orders',
                'duration_ms' => 50.0 + (float) $i,
            ]);
        }

        $windowUs = 5 * 60 * 1_000_000;
        $result = $this->aggregator->slowQueries($windowUs);

        self::assertCount(2, $result);
        self::assertSame('fp_orders', $result[0]['sql_fingerprint']);
        self::assertSame(3, $result[0]['count']);
    }

    #[Test]
    public function eventCountsByTypeReturnsCorrectCounts(): void
    {
        $this->insertEvent('http.response', ['status_code' => 200, 'duration_ms' => 10.0]);
        $this->insertEvent('http.response', ['status_code' => 200, 'duration_ms' => 20.0]);
        $this->insertEvent('exception', ['exception_class' => 'RuntimeException']);
        $this->insertEvent('db.query', ['sql' => 'SELECT 1', 'duration_ms' => 1.0]);

        $windowUs = 5 * 60 * 1_000_000;
        $result = $this->aggregator->eventCountsByType($windowUs);

        self::assertSame(2, $result['http.response']);
        self::assertSame(1, $result['exception']);
        self::assertSame(1, $result['db.query']);
    }

    #[Test]
    public function throughputTimeSeriesReturnsBucketedCounts(): void
    {
        $this->insertEvent('http.response', ['status_code' => 200, 'duration_ms' => 10.0]);

        $windowUs = 5 * 60 * 1_000_000;
        $series = $this->aggregator->throughputTimeSeries($windowUs, 6);

        self::assertCount(6, $series);
        self::assertSame(1, array_sum($series));
    }

    #[Test]
    public function errorTimeSeriesReturnsBucketedCounts(): void
    {
        $this->insertEvent('exception', ['exception_class' => 'RuntimeException']);

        $windowUs = 5 * 60 * 1_000_000;
        $series = $this->aggregator->errorTimeSeries($windowUs, 6);

        self::assertCount(6, $series);
        self::assertSame(1, array_sum($series));
    }

    #[Test]
    public function benchmarkRunsReturnsDecodedPayloads(): void
    {
        $this->insertEvent('benchmark.run', [
            'run_id' => 'run-1',
            'profile_count' => 3,
            'success_count' => 2,
            'failure_count' => 1,
            'skipped_count' => 0,
            'total_duration_ms' => 1234.0,
            'php_version' => '8.5.0',
        ]);

        $runs = $this->aggregator->benchmarkRuns(10);

        self::assertCount(1, $runs);
        self::assertSame('run-1', $runs[0]['run_id']);
        self::assertSame(3, $runs[0]['profile_count']);
        self::assertSame(2, $runs[0]['success_count']);
        self::assertSame(1, $runs[0]['failure_count']);
        self::assertSame(0, $runs[0]['skipped_count']);
        self::assertEqualsWithDelta(1234.0, $runs[0]['total_duration_ms'], 0.01);
        self::assertSame('8.5.0', $runs[0]['php_version']);
    }

    #[Test]
    public function benchmarkProfilesFiltersToRunId(): void
    {
        $this->insertEvent('benchmark.profile', [
            'run_id' => 'run-1',
            'profile_name' => 'profile-a',
            'boot_us' => 100,
            'warm_boot_us' => 50,
            'p50_us' => 200,
            'p95_us' => 500,
            'rps' => 1000,
            'peak_rss_kb' => 2048,
            'memory_usage_kb' => 1024,
            'opcache_memory_kb' => 512,
            'optimize_enabled' => true,
        ]);

        $this->insertEvent('benchmark.profile', [
            'run_id' => 'run-2',
            'profile_name' => 'profile-b',
            'boot_us' => 100,
            'warm_boot_us' => 50,
            'p50_us' => 200,
            'p95_us' => 500,
            'rps' => 1000,
            'peak_rss_kb' => 2048,
            'memory_usage_kb' => 1024,
            'opcache_memory_kb' => null,
        ]);

        $profiles = $this->aggregator->benchmarkProfiles('run-1');

        self::assertCount(1, $profiles);
        self::assertSame('profile-a', $profiles[0]['profile_name']);
        self::assertTrue($profiles[0]['optimize_enabled']);
    }

    #[Test]
    public function aggregateReturnsCombinedMetrics(): void
    {
        $this->insertEvent('http.response', ['status_code' => 200, 'duration_ms' => 10.0, 'route_name' => 'api.test']);
        $this->insertEvent('exception', ['exception_class' => 'RuntimeException']);
        $this->insertEvent('db.query', ['sql' => 'SELECT 1', 'sql_fingerprint' => 'fp1', 'duration_ms' => 1.0]);

        $result = $this->aggregator->aggregate();

        self::assertArrayHasKey('total_events', $result);
        self::assertArrayHasKey('total_requests', $result);
        self::assertArrayHasKey('avg_response_ms', $result);
        self::assertArrayHasKey('total_exceptions', $result);
        self::assertArrayHasKey('total_queries', $result);
        self::assertArrayHasKey('routes', $result);
        self::assertArrayHasKey('exceptions', $result);
        self::assertArrayHasKey('slow_queries', $result);

        self::assertSame(1, $result['total_requests']);
        self::assertSame(1, $result['total_exceptions']);
        self::assertSame(1, $result['total_queries']);
    }

    #[Test]
    public function aggregateIncludesBenchmarkWhenAvailable(): void
    {
        $this->insertEvent('benchmark.run', [
            'run_id' => 'run-1',
            'profile_count' => 3,
            'success_count' => 3,
            'failure_count' => 0,
            'total_duration_ms' => 500.0,
            'php_version' => '8.5.0',
        ]);

        $result = $this->aggregator->aggregate();

        self::assertArrayHasKey('benchmark', $result);
        self::assertIsArray($result['benchmark']);
        self::assertSame('run-1', $result['benchmark']['latest_run_id']);
    }

    #[Test]
    public function throughputTimeSeriesHandlesZeroBucketSize(): void
    {
        $series = $this->aggregator->throughputTimeSeries(0, 6);

        self::assertCount(6, $series);
        self::assertSame([0, 0, 0, 0, 0, 0], $series);
    }
}
