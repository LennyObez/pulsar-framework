<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Aggregation;

use function array_fill;
use function array_sum;
use function bin2hex;
use function hash;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Studio\Console\Aggregation\DashboardAggregator;
use Pulsar\Studio\Console\Event\EventEnvelope;
use Pulsar\Studio\Console\Event\EventType;
use Pulsar\Studio\Console\Event\EventVersion;
use Pulsar\Studio\Console\Storage\SqliteEventStore;

use function random_bytes;
use function usleep;

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

    // -------------------------------------------------------------------------
    // availableSections() tests
    // -------------------------------------------------------------------------

    #[Test]
    public function availableSectionsReturnsEmptyWhenNoEvents(): void
    {
        self::assertSame([], $this->aggregator->availableSections());
    }

    #[Test]
    public function availableSectionsReturnsThroughputAndLatencyAndSlowRoutesForHttpResponse(): void
    {
        $this->storeHttpResponseEvent(durationMs: 100, statusCode: 200);

        $sections = $this->aggregator->availableSections();

        self::assertContains('throughput', $sections);
        self::assertContains('latency', $sections);
        self::assertContains('slow_routes', $sections);
    }

    #[Test]
    public function availableSectionsReturnsErrorRateForExceptionEvents(): void
    {
        $this->storeExceptionEvent('RuntimeException');

        $sections = $this->aggregator->availableSections();

        self::assertContains('error_rate', $sections);
    }

    #[Test]
    public function availableSectionsReturnsSlowQueriesForDbQueryEvents(): void
    {
        $this->storeDbQueryEvent('SELECT * FROM users WHERE id = ?', durationMs: 50);

        $sections = $this->aggregator->availableSections();

        self::assertContains('slow_queries', $sections);
    }

    #[Test]
    public function availableSectionsReturnsMultipleSectionsForMixedEvents(): void
    {
        $this->storeHttpResponseEvent(durationMs: 100, statusCode: 200);
        $this->storeExceptionEvent('LogicException');
        $this->storeDbQueryEvent('SELECT 1', durationMs: 10);

        $sections = $this->aggregator->availableSections();

        self::assertContains('throughput', $sections);
        self::assertContains('latency', $sections);
        self::assertContains('error_rate', $sections);
        self::assertContains('slow_routes', $sections);
        self::assertContains('slow_queries', $sections);
    }

    // -------------------------------------------------------------------------
    // throughput() tests
    // -------------------------------------------------------------------------

    #[Test]
    public function throughputReturnsZeroesWhenNoEvents(): void
    {
        $result = $this->aggregator->throughput(windowUs: 60_000_000);

        self::assertSame(0.0, $result['per_minute']);
        self::assertSame(0, $result['total']);
        self::assertSame([], $result['by_status']);
    }

    #[Test]
    public function throughputCountsHttpResponseEventsOnly(): void
    {
        $this->storeHttpResponseEvent(durationMs: 100, statusCode: 200);
        $this->storeHttpResponseEvent(durationMs: 150, statusCode: 200);
        $this->storeExceptionEvent('RuntimeException'); // Should not be counted

        $result = $this->aggregator->throughput(windowUs: 60_000_000);

        self::assertSame(2, $result['total']);
    }

    #[Test]
    public function throughputCalculatesPerMinuteRate(): void
    {
        // Store 30 events in a 60-second window = 30 RPM
        for ($i = 0; $i < 30; $i++) {
            $this->storeHttpResponseEvent(durationMs: 50, statusCode: 200);
        }

        $result = $this->aggregator->throughput(windowUs: 60_000_000);

        self::assertSame(30.0, $result['per_minute']);
    }

    #[Test]
    public function throughputGroupsByStatusCodeClass(): void
    {
        $this->storeHttpResponseEvent(durationMs: 100, statusCode: 200);
        $this->storeHttpResponseEvent(durationMs: 100, statusCode: 201);
        $this->storeHttpResponseEvent(durationMs: 100, statusCode: 301);
        $this->storeHttpResponseEvent(durationMs: 100, statusCode: 404);
        $this->storeHttpResponseEvent(durationMs: 100, statusCode: 500);
        $this->storeHttpResponseEvent(durationMs: 100, statusCode: 503);

        $result = $this->aggregator->throughput(windowUs: 60_000_000);

        self::assertSame(2, $result['by_status']['2xx']);
        self::assertSame(1, $result['by_status']['3xx']);
        self::assertSame(1, $result['by_status']['4xx']);
        self::assertSame(2, $result['by_status']['5xx']);
    }

    #[Test]
    public function throughputRespectsTimeWindow(): void
    {
        // Store an old event (way in the past)
        $this->storeHttpResponseEventWithTimestamp(
            durationMs: 100,
            statusCode: 200,
            timestampUs: 1000,
        );

        // Store a recent event
        $this->storeHttpResponseEvent(durationMs: 100, statusCode: 200);

        // Only events in the last minute
        $result = $this->aggregator->throughput(windowUs: 60_000_000);

        self::assertSame(1, $result['total']);
    }

    // -------------------------------------------------------------------------
    // latencyPercentiles() tests
    // -------------------------------------------------------------------------

    #[Test]
    public function latencyPercentilesReturnsZeroesWhenNoEvents(): void
    {
        $result = $this->aggregator->latencyPercentiles(windowUs: 60_000_000);

        self::assertSame(0.0, $result['p50']);
        self::assertSame(0.0, $result['p95']);
        self::assertSame(0.0, $result['p99']);
    }

    #[Test]
    public function latencyPercentilesCalculatesCorrectlyForSingleEvent(): void
    {
        $this->storeHttpResponseEvent(durationMs: 100, statusCode: 200);

        $result = $this->aggregator->latencyPercentiles(windowUs: 60_000_000);

        self::assertSame(100.0, $result['p50']);
        self::assertSame(100.0, $result['p95']);
        self::assertSame(100.0, $result['p99']);
    }

    #[Test]
    public function latencyPercentilesCalculatesCorrectlyForMultipleEvents(): void
    {
        // Store 100 events with latencies from 1ms to 100ms
        for ($i = 1; $i <= 100; $i++) {
            $this->storeHttpResponseEvent(durationMs: $i, statusCode: 200);
        }

        $result = $this->aggregator->latencyPercentiles(windowUs: 60_000_000);

        self::assertSame(50.0, $result['p50']);
        self::assertSame(95.0, $result['p95']);
        self::assertSame(99.0, $result['p99']);
    }

    #[Test]
    public function latencyPercentilesRespectsTimeWindow(): void
    {
        // Store an old event
        $this->storeHttpResponseEventWithTimestamp(
            durationMs: 1000,
            statusCode: 200,
            timestampUs: 1000,
        );

        // Store a recent event
        $this->storeHttpResponseEvent(durationMs: 50, statusCode: 200);

        $result = $this->aggregator->latencyPercentiles(windowUs: 60_000_000);

        // Only the recent event should be counted
        self::assertSame(50.0, $result['p50']);
    }

    // -------------------------------------------------------------------------
    // errorRate() tests
    // -------------------------------------------------------------------------

    #[Test]
    public function errorRateReturnsZeroesWhenNoEvents(): void
    {
        $result = $this->aggregator->errorRate(windowUs: 60_000_000);

        self::assertSame(0.0, $result['per_minute']);
        self::assertSame(0, $result['total']);
        self::assertSame([], $result['top_exceptions']);
    }

    #[Test]
    public function errorRateCountsExceptionEventsOnly(): void
    {
        $this->storeExceptionEvent('RuntimeException');
        $this->storeExceptionEvent('LogicException');
        $this->storeHttpResponseEvent(durationMs: 100, statusCode: 500); // Not counted

        $result = $this->aggregator->errorRate(windowUs: 60_000_000);

        self::assertSame(2, $result['total']);
    }

    #[Test]
    public function errorRateCalculatesPerMinuteRate(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->storeExceptionEvent('RuntimeException');
        }

        $result = $this->aggregator->errorRate(windowUs: 60_000_000);

        self::assertSame(15.0, $result['per_minute']);
    }

    #[Test]
    public function errorRateGroupsTopExceptions(): void
    {
        $this->storeExceptionEvent('RuntimeException');
        $this->storeExceptionEvent('RuntimeException');
        $this->storeExceptionEvent('RuntimeException');
        $this->storeExceptionEvent('LogicException');
        $this->storeExceptionEvent('LogicException');
        $this->storeExceptionEvent('InvalidArgumentException');

        $result = $this->aggregator->errorRate(windowUs: 60_000_000);

        self::assertCount(3, $result['top_exceptions']);
        // RuntimeException should be first (3 occurrences)
        self::assertSame('RuntimeException', $result['top_exceptions'][0]['class']);
        self::assertSame(3, $result['top_exceptions'][0]['count']);
        // LogicException should be second (2 occurrences)
        self::assertSame('LogicException', $result['top_exceptions'][1]['class']);
        self::assertSame(2, $result['top_exceptions'][1]['count']);
    }

    #[Test]
    public function errorRateRespectsTimeWindow(): void
    {
        // Store an old exception
        $this->storeExceptionEventWithTimestamp('OldException', timestampUs: 1000);

        // Store a recent exception
        $this->storeExceptionEvent('RecentException');

        $result = $this->aggregator->errorRate(windowUs: 60_000_000);

        self::assertSame(1, $result['total']);
        self::assertSame('RecentException', $result['top_exceptions'][0]['class']);
    }

    // -------------------------------------------------------------------------
    // slowRoutes() tests
    // -------------------------------------------------------------------------

    #[Test]
    public function slowRoutesReturnsEmptyWhenNoEvents(): void
    {
        $result = $this->aggregator->slowRoutes(windowUs: 60_000_000);

        self::assertSame([], $result);
    }

    #[Test]
    public function slowRoutesGroupsByRouteName(): void
    {
        $this->storeHttpResponseEventWithRoute(durationMs: 100, routeName: 'users.index');
        $this->storeHttpResponseEventWithRoute(durationMs: 150, routeName: 'users.index');
        $this->storeHttpResponseEventWithRoute(durationMs: 200, routeName: 'users.show');

        $result = $this->aggregator->slowRoutes(windowUs: 60_000_000);

        self::assertCount(2, $result);
    }

    #[Test]
    public function slowRoutesSortsByP95Descending(): void
    {
        // Slow route
        for ($i = 0; $i < 20; $i++) {
            $this->storeHttpResponseEventWithRoute(durationMs: 500, routeName: 'slow.route');
        }
        // Fast route
        for ($i = 0; $i < 20; $i++) {
            $this->storeHttpResponseEventWithRoute(durationMs: 50, routeName: 'fast.route');
        }

        $result = $this->aggregator->slowRoutes(windowUs: 60_000_000);

        self::assertSame('slow.route', $result[0]['route']);
        self::assertSame('fast.route', $result[1]['route']);
    }

    #[Test]
    public function slowRoutesCalculatesP95AndAverage(): void
    {
        // Store 100 requests with latencies from 1ms to 100ms
        for ($i = 1; $i <= 100; $i++) {
            $this->storeHttpResponseEventWithRoute(durationMs: $i, routeName: 'test.route');
        }

        $result = $this->aggregator->slowRoutes(windowUs: 60_000_000);

        self::assertSame('test.route', $result[0]['route']);
        self::assertSame(100, $result[0]['count']);
        // P95 for 100 items: ceil(0.95 * 100) - 1 = 95 - 1 = 94 (0-indexed), value = 95ms
        self::assertSame(95.0, $result[0]['p95_ms']);
        // Average: sum(1..100) / 100 = 5050 / 100 = 50.5
        self::assertSame(50.5, $result[0]['avg_ms']);
    }

    #[Test]
    public function slowRoutesRespectsLimit(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->storeHttpResponseEventWithRoute(durationMs: $i * 10, routeName: "route.{$i}");
        }

        $result = $this->aggregator->slowRoutes(windowUs: 60_000_000, limit: 5);

        self::assertCount(5, $result);
    }

    // -------------------------------------------------------------------------
    // slowQueries() tests
    // -------------------------------------------------------------------------

    #[Test]
    public function slowQueriesReturnsEmptyWhenNoEvents(): void
    {
        $result = $this->aggregator->slowQueries(windowUs: 60_000_000);

        self::assertSame([], $result);
    }

    #[Test]
    public function slowQueriesGroupsByFingerprint(): void
    {
        $this->storeDbQueryEvent('SELECT * FROM users WHERE id = ?', durationMs: 50, fingerprint: 'fp1');
        $this->storeDbQueryEvent('SELECT * FROM users WHERE id = ?', durationMs: 60, fingerprint: 'fp1');
        $this->storeDbQueryEvent('SELECT * FROM posts WHERE id = ?', durationMs: 100, fingerprint: 'fp2');

        $result = $this->aggregator->slowQueries(windowUs: 60_000_000);

        self::assertCount(2, $result);
    }

    #[Test]
    public function slowQueriesSortsByP95Descending(): void
    {
        // Slow query
        for ($i = 0; $i < 20; $i++) {
            $this->storeDbQueryEvent('SELECT * FROM slow_table', durationMs: 500, fingerprint: 'slow');
        }
        // Fast query
        for ($i = 0; $i < 20; $i++) {
            $this->storeDbQueryEvent('SELECT 1', durationMs: 5, fingerprint: 'fast');
        }

        $result = $this->aggregator->slowQueries(windowUs: 60_000_000);

        self::assertSame('slow', $result[0]['sql_fingerprint']);
        self::assertSame('fast', $result[1]['sql_fingerprint']);
    }

    #[Test]
    public function slowQueriesCalculatesP95AndAverage(): void
    {
        // Store 100 queries with durations from 1ms to 100ms
        for ($i = 1; $i <= 100; $i++) {
            $this->storeDbQueryEvent('SELECT * FROM users', durationMs: $i, fingerprint: 'test_fp');
        }

        $result = $this->aggregator->slowQueries(windowUs: 60_000_000);

        self::assertSame('test_fp', $result[0]['sql_fingerprint']);
        self::assertSame(100, $result[0]['count']);
        // P95 for 100 items: ceil(0.95 * 100) - 1 = 95 - 1 = 94 (0-indexed), value = 95ms
        self::assertSame(95.0, $result[0]['p95_ms']);
        // Average: sum(1..100) / 100 = 5050 / 100 = 50.5
        self::assertSame(50.5, $result[0]['avg_ms']);
    }

    #[Test]
    public function slowQueriesRespectsLimit(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->storeDbQueryEvent("SELECT {$i}", durationMs: $i * 10, fingerprint: "fp_{$i}");
        }

        $result = $this->aggregator->slowQueries(windowUs: 60_000_000, limit: 5);

        self::assertCount(5, $result);
    }

    // -------------------------------------------------------------------------
    // eventCountsByType() tests
    // -------------------------------------------------------------------------

    #[Test]
    public function eventCountsByTypeReturnsEmptyWhenNoEvents(): void
    {
        $result = $this->aggregator->eventCountsByType(windowUs: 60_000_000);

        self::assertSame([], $result);
    }

    #[Test]
    public function eventCountsByTypeCountsAllEventTypes(): void
    {
        $this->storeHttpResponseEvent(durationMs: 100, statusCode: 200);
        $this->storeHttpResponseEvent(durationMs: 100, statusCode: 200);
        $this->storeExceptionEvent('RuntimeException');
        $this->storeDbQueryEvent('SELECT 1', durationMs: 10);
        $this->storeDbQueryEvent('SELECT 2', durationMs: 20);
        $this->storeDbQueryEvent('SELECT 3', durationMs: 30);

        $result = $this->aggregator->eventCountsByType(windowUs: 60_000_000);

        self::assertSame(2, $result['http.response']);
        self::assertSame(1, $result['exception']);
        self::assertSame(3, $result['db.query']);
    }

    #[Test]
    public function eventCountsByTypeRespectsTimeWindow(): void
    {
        // Store an old event
        $this->storeHttpResponseEventWithTimestamp(
            durationMs: 100,
            statusCode: 200,
            timestampUs: 1000,
        );

        // Store recent events
        $this->storeHttpResponseEvent(durationMs: 100, statusCode: 200);
        $this->storeExceptionEvent('RuntimeException');

        $result = $this->aggregator->eventCountsByType(windowUs: 60_000_000);

        self::assertSame(1, $result['http.response']);
        self::assertSame(1, $result['exception']);
    }

    // -------------------------------------------------------------------------
    // throughputTimeSeries() tests
    // -------------------------------------------------------------------------

    #[Test]
    public function throughputTimeSeriesReturnsZeroesWhenNoEvents(): void
    {
        $result = $this->aggregator->throughputTimeSeries(windowUs: 60_000_000, buckets: 12);

        self::assertCount(12, $result);
        self::assertSame(array_fill(0, 12, 0), $result);
    }

    #[Test]
    public function throughputTimeSeriesCountsHttpResponseEventsOnly(): void
    {
        $this->storeHttpResponseEvent(durationMs: 100, statusCode: 200);
        $this->storeHttpResponseEvent(durationMs: 100, statusCode: 200);
        $this->storeExceptionEvent('RuntimeException');

        $result = $this->aggregator->throughputTimeSeries(windowUs: 60_000_000, buckets: 12);

        // Only HTTP response events should be counted
        self::assertSame(2, array_sum($result));
    }

    #[Test]
    public function throughputTimeSeriesReturnsCorrectBucketCount(): void
    {
        $result = $this->aggregator->throughputTimeSeries(windowUs: 60_000_000, buckets: 6);

        self::assertCount(6, $result);
    }

    #[Test]
    public function throughputTimeSeriesHandlesZeroBucketSize(): void
    {
        // Very small window that results in bucket size <= 0
        $result = $this->aggregator->throughputTimeSeries(windowUs: 5, buckets: 12);

        self::assertCount(12, $result);
        self::assertSame(array_fill(0, 12, 0), $result);
    }

    // -------------------------------------------------------------------------
    // errorTimeSeries() tests
    // -------------------------------------------------------------------------

    #[Test]
    public function errorTimeSeriesReturnsZeroesWhenNoEvents(): void
    {
        $result = $this->aggregator->errorTimeSeries(windowUs: 60_000_000, buckets: 12);

        self::assertCount(12, $result);
        self::assertSame(array_fill(0, 12, 0), $result);
    }

    #[Test]
    public function errorTimeSeriesCountsExceptionEventsOnly(): void
    {
        $this->storeExceptionEvent('RuntimeException');
        $this->storeExceptionEvent('LogicException');
        $this->storeHttpResponseEvent(durationMs: 100, statusCode: 500);

        $result = $this->aggregator->errorTimeSeries(windowUs: 60_000_000, buckets: 12);

        // Only exception events should be counted
        self::assertSame(2, array_sum($result));
    }

    #[Test]
    public function errorTimeSeriesReturnsCorrectBucketCount(): void
    {
        $result = $this->aggregator->errorTimeSeries(windowUs: 60_000_000, buckets: 6);

        self::assertCount(6, $result);
    }

    #[Test]
    public function errorTimeSeriesHandlesZeroBucketSize(): void
    {
        $result = $this->aggregator->errorTimeSeries(windowUs: 5, buckets: 12);

        self::assertCount(12, $result);
        self::assertSame(array_fill(0, 12, 0), $result);
    }

    // -------------------------------------------------------------------------
    // benchmarkRuns() tests
    // -------------------------------------------------------------------------

    #[Test]
    public function benchmarkRunsReturnsEmptyWhenNoBenchmarks(): void
    {
        $result = $this->aggregator->benchmarkRuns();

        self::assertSame([], $result);
    }

    #[Test]
    public function benchmarkRunsReturnsRecentRuns(): void
    {
        $this->storeBenchmarkRunEvent(runId: 'run-1', profileCount: 3, successCount: 3);
        $this->storeBenchmarkRunEvent(runId: 'run-2', profileCount: 6, successCount: 5);

        $result = $this->aggregator->benchmarkRuns();

        self::assertCount(2, $result);
        // Most recent first
        self::assertSame('run-2', $result[0]['run_id']);
        self::assertSame('run-1', $result[1]['run_id']);
    }

    #[Test]
    public function benchmarkRunsIncludesSkippedCount(): void
    {
        $this->storeBenchmarkRunEvent(runId: 'run-skip', profileCount: 6, successCount: 3, skippedCount: 3);

        $result = $this->aggregator->benchmarkRuns();

        self::assertCount(1, $result);
        self::assertSame(3, $result[0]['skipped_count']);
        self::assertSame(0, $result[0]['failure_count']);
    }

    #[Test]
    public function benchmarkRunsDefaultsSkippedCountToZeroForOldEvents(): void
    {
        // Simulate an old event without skipped_count
        $this->storeEvent(
            eventType: EventType::BenchmarkRun,
            payload: [
                'run_id' => 'old-run',
                'php_version' => '8.4.0',
                'php_sapi' => 'cli',
                'os_platform' => 'Linux',
                'os_arch' => 'x86_64',
                'profile_count' => 3,
                'success_count' => 3,
                'failure_count' => 0,
                'total_duration_ms' => 1000.0,
                'profile_names' => ['baseline'],
            ],
        );

        $result = $this->aggregator->benchmarkRuns();

        self::assertCount(1, $result);
        self::assertSame(0, $result[0]['skipped_count']);
    }

    #[Test]
    public function benchmarkRunsRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->storeBenchmarkRunEvent(runId: "run-{$i}", profileCount: 3, successCount: 3);
        }

        $result = $this->aggregator->benchmarkRuns(limit: 2);

        self::assertCount(2, $result);
    }

    // -------------------------------------------------------------------------
    // benchmarkProfiles() tests
    // -------------------------------------------------------------------------

    #[Test]
    public function benchmarkProfilesReturnsEmptyWhenNoProfiles(): void
    {
        $result = $this->aggregator->benchmarkProfiles('nonexistent-run');

        self::assertSame([], $result);
    }

    #[Test]
    public function benchmarkProfilesReturnsProfilesForRun(): void
    {
        $this->storeBenchmarkProfileEvent(runId: 'run-a', profileName: 'baseline', bootUs: 1000);
        $this->storeBenchmarkProfileEvent(runId: 'run-a', profileName: 'jit-tracing', bootUs: 800);
        $this->storeBenchmarkProfileEvent(runId: 'run-b', profileName: 'baseline', bootUs: 1200);

        $result = $this->aggregator->benchmarkProfiles('run-a');

        self::assertCount(2, $result);
        self::assertSame('baseline', $result[0]['profile_name']);
        self::assertSame(1000, $result[0]['boot_us']);
        self::assertSame('jit-tracing', $result[1]['profile_name']);
        self::assertSame(800, $result[1]['boot_us']);
    }

    #[Test]
    public function benchmarkProfilesDoesNotReturnOtherRunProfiles(): void
    {
        $this->storeBenchmarkProfileEvent(runId: 'run-a', profileName: 'baseline', bootUs: 1000);
        $this->storeBenchmarkProfileEvent(runId: 'run-b', profileName: 'jit-tracing', bootUs: 800);

        $result = $this->aggregator->benchmarkProfiles('run-a');

        self::assertCount(1, $result);
        self::assertSame('baseline', $result[0]['profile_name']);
    }

    #[Test]
    public function benchmarkProfilesExtractsOptimizeEnabled(): void
    {
        $this->storeBenchmarkProfileEvent(runId: 'run-opt', profileName: 'baseline', bootUs: 1000);
        $this->storeBenchmarkProfileEvent(runId: 'run-opt', profileName: 'baseline-optimized', bootUs: 700, optimizeEnabled: true);

        $result = $this->aggregator->benchmarkProfiles('run-opt');

        self::assertCount(2, $result);
        self::assertFalse($result[0]['optimize_enabled']);
        self::assertTrue($result[1]['optimize_enabled']);
    }

    #[Test]
    public function benchmarkProfilesDefaultsOptimizeEnabledToFalseForOldEvents(): void
    {
        // Simulate an old profile event without optimize_enabled
        $this->storeEvent(
            eventType: EventType::BenchmarkProfile,
            payload: [
                'run_id' => 'old-run',
                'profile_name' => 'baseline',
                'profile_description' => 'Test profile',
                'boot_us' => 1000,
                'warm_boot_us' => 600,
                'p50_us' => 25,
                'p95_us' => 120,
                'rps' => 40000,
                'peak_rss_kb' => 32768,
                'memory_usage_kb' => 16384,
                'opcache_memory_kb' => 8192,
                'iterations' => 1000,
                'jit_enabled' => false,
                'jit_mode' => 'off',
                'preload_enabled' => false,
            ],
        );

        $result = $this->aggregator->benchmarkProfiles('old-run');

        self::assertCount(1, $result);
        self::assertFalse($result[0]['optimize_enabled']);
    }

    // -------------------------------------------------------------------------
    // availableSections() benchmark tests
    // -------------------------------------------------------------------------

    #[Test]
    public function availableSectionsIncludesBenchmarkWhenEventsExist(): void
    {
        $this->storeBenchmarkRunEvent(runId: 'run-1', profileCount: 3, successCount: 3);

        $sections = $this->aggregator->availableSections();

        self::assertContains('benchmark', $sections);
    }

    #[Test]
    public function availableSectionsExcludesBenchmarkWhenNoEvents(): void
    {
        $this->storeHttpResponseEvent(durationMs: 100, statusCode: 200);

        $sections = $this->aggregator->availableSections();

        self::assertNotContains('benchmark', $sections);
    }

    // -------------------------------------------------------------------------
    // Edge case tests
    // -------------------------------------------------------------------------

    #[Test]
    public function handlesLargeNumberOfEvents(): void
    {
        for ($i = 0; $i < 1000; $i++) {
            $this->storeHttpResponseEvent(durationMs: $i % 100 + 1, statusCode: 200);
        }

        $throughput = $this->aggregator->throughput(windowUs: 60_000_000);
        $latency = $this->aggregator->latencyPercentiles(windowUs: 60_000_000);
        $counts = $this->aggregator->eventCountsByType(windowUs: 60_000_000);

        self::assertSame(1000, $throughput['total']);
        self::assertGreaterThan(0.0, $latency['p50']);
        self::assertSame(1000, $counts['http.response']);
    }

    // -------------------------------------------------------------------------
    // Helper methods for populating test data
    // -------------------------------------------------------------------------

    private function storeHttpResponseEvent(int $durationMs, int $statusCode): void
    {
        $this->storeEvent(
            eventType: EventType::HttpResponse,
            payload: [
                'duration_ms' => $durationMs,
                'status_code' => $statusCode,
            ],
        );
    }

    private function storeHttpResponseEventWithRoute(int $durationMs, string $routeName): void
    {
        $this->storeEvent(
            eventType: EventType::HttpResponse,
            payload: [
                'duration_ms' => $durationMs,
                'status_code' => 200,
                'route_name' => $routeName,
            ],
        );
    }

    private function storeHttpResponseEventWithTimestamp(
        int $durationMs,
        int $statusCode,
        int $timestampUs,
    ): void {
        $this->storeEvent(
            eventType: EventType::HttpResponse,
            payload: [
                'duration_ms' => $durationMs,
                'status_code' => $statusCode,
            ],
            timestampUs: $timestampUs,
        );
    }

    private function storeExceptionEvent(string $exceptionClass): void
    {
        $this->storeEvent(
            eventType: EventType::Exception,
            payload: [
                'exception_class' => $exceptionClass,
            ],
        );
    }

    private function storeExceptionEventWithTimestamp(string $exceptionClass, int $timestampUs): void
    {
        $this->storeEvent(
            eventType: EventType::Exception,
            payload: [
                'exception_class' => $exceptionClass,
            ],
            timestampUs: $timestampUs,
        );
    }

    private function storeBenchmarkRunEvent(
        string $runId,
        int $profileCount,
        int $successCount,
        int $skippedCount = 0,
    ): void {
        $this->storeEvent(
            eventType: EventType::BenchmarkRun,
            payload: [
                'run_id' => $runId,
                'php_version' => '8.5.0',
                'php_sapi' => 'cli',
                'os_platform' => 'Linux',
                'os_arch' => 'x86_64',
                'profile_count' => $profileCount,
                'success_count' => $successCount,
                'failure_count' => $profileCount - $successCount - $skippedCount,
                'skipped_count' => $skippedCount,
                'total_duration_ms' => 5000.0,
                'profile_names' => ['baseline', 'jit-tracing'],
            ],
        );
    }

    private function storeBenchmarkProfileEvent(
        string $runId,
        string $profileName,
        int $bootUs,
        bool $optimizeEnabled = false,
    ): void {
        $this->storeEvent(
            eventType: EventType::BenchmarkProfile,
            payload: [
                'run_id' => $runId,
                'profile_name' => $profileName,
                'profile_description' => 'Test profile',
                'boot_us' => $bootUs,
                'warm_boot_us' => (int) ($bootUs * 0.6),
                'p50_us' => 25,
                'p95_us' => 120,
                'rps' => 40000,
                'peak_rss_kb' => 32768,
                'memory_usage_kb' => 16384,
                'opcache_memory_kb' => 8192,
                'iterations' => 1000,
                'jit_enabled' => false,
                'jit_mode' => 'off',
                'preload_enabled' => false,
                'optimize_enabled' => $optimizeEnabled,
            ],
        );
    }

    private function storeDbQueryEvent(string $sql, int $durationMs, ?string $fingerprint = null): void
    {
        $this->storeEvent(
            eventType: EventType::DatabaseQuery,
            payload: [
                'sql' => $sql,
                'sql_fingerprint' => $fingerprint ?? hash('md5', $sql),
                'duration_ms' => $durationMs,
            ],
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function storeEvent(
        EventType $eventType,
        array $payload,
        ?int $timestampUs = null,
    ): void {
        $payloadJson = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $payloadHash = hash('sha256', $payloadJson);

        $envelope = new EventEnvelope(
            eventId: bin2hex(random_bytes(16)),
            eventType: $eventType,
            schemaVersion: EventVersion::V1,
            timestampUs: $timestampUs ?? (int) (microtime(true) * 1_000_000.0),
            requestId: bin2hex(random_bytes(8)),
            traceId: bin2hex(random_bytes(16)),
            spanId: bin2hex(random_bytes(8)),
            jobId: null,
            appEnv: 'testing',
            hostname: 'test-host',
            payload: $payload,
            payloadHash: $payloadHash,
        );

        $this->store->store($envelope, $payloadJson);

        // Small delay to ensure timestamp ordering for sequential events
        usleep(10);
    }
}
