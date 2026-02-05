<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Aggregation;

use function array_filter;
use function array_map;
use function array_slice;
use function array_sum;
use function array_values;
use function ceil;
use function count;
use function in_array;
use function max;
use function microtime;
use function min;

use PDO;
use Pulsar\Api\Internal;
use Pulsar\Studio\Console\Storage\EncryptedEventStore;
use Pulsar\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Studio\Console\Storage\SqliteEventStore;

use function round;
use function sort;
use function usort;

/**
 * Aggregates dashboard metrics from the Studio event store using SQL queries.
 *
 * Runs aggregate queries (COUNT, AVG, percentiles) against the SQLite store.
 * Supports conditional section rendering: only returns sections for event
 * types that have recorded events.
 */
#[Internal]
final class DashboardAggregator
{
    private readonly PDO $pdo;

    public function __construct(
        EventStoreInterface $store,
    ) {
        $this->pdo = $this->resolvePdo($store);
    }

    /**
     * Get available dashboard sections based on which event types have data.
     *
     * @return list<string>
     */
    public function availableSections(): array
    {
        $stmt = $this->pdo->query('SELECT DISTINCT event_type FROM studio_events');
        /** @var list<array{event_type: string}> $rows */
        $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $types = array_map(static fn(array $row): string => $row['event_type'], $rows);

        $sectionMap = [
            'throughput' => ['http.response'],
            'latency' => ['http.response'],
            'error_rate' => ['exception'],
            'slow_routes' => ['http.response'],
            'slow_queries' => ['db.query'],
            'scheduler' => ['scheduler.run'],
            'feature_flags' => ['feature_flag.eval'],
            'logs' => ['log.entry'],
            'cache' => ['cache.hit', 'cache.miss', 'cache.write', 'cache.delete'],
            'queue' => ['job.queued', 'job.processing', 'job.completed', 'job.failed'],
        ];

        $available = [];
        foreach ($sectionMap as $section => $requiredTypes) {
            foreach ($requiredTypes as $required) {
                if (in_array($required, $types, true)) {
                    $available[] = $section;
                    break;
                }
            }
        }

        return $available;
    }

    /**
     * Get throughput metrics (requests per minute) for a time window.
     *
     * @return array{current_rpm: float, total: int}
     */
    public function throughput(int $windowUs): array
    {
        $since = $this->nowUs() - $windowUs;

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) as total FROM studio_events WHERE event_type = :type AND timestamp_us > :since',
        );
        $stmt->execute(['type' => 'http.response', 'since' => $since]);
        /** @var array{total: int} $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $windowMinutes = (float) $windowUs / 60_000_000.0;
        $total = $row['total'];
        $rpm = $windowMinutes > 0 ? (float) $total / $windowMinutes : 0.0;

        return [
            'current_rpm' => round($rpm, 2),
            'total' => $total,
        ];
    }

    /**
     * Get response time percentiles for a time window.
     *
     * @return array{p50: float, p95: float, p99: float}
     */
    public function latencyPercentiles(int $windowUs): array
    {
        $since = $this->nowUs() - $windowUs;

        $stmt = $this->pdo->prepare(
            "SELECT json_extract(payload_json, '$.duration_ms') as duration_ms
             FROM studio_events
             WHERE event_type = :type AND timestamp_us > :since
             ORDER BY json_extract(payload_json, '$.duration_ms') ASC",
        );
        $stmt->execute(['type' => 'http.response', 'since' => $since]);
        /** @var list<array{duration_ms: string|null}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $durations = array_filter(
            array_map(static fn(array $r): float => (float) ($r['duration_ms'] ?? 0), $rows),
            static fn(float $d): bool => $d > 0,
        );

        if ($durations === []) {
            return ['p50' => 0.0, 'p95' => 0.0, 'p99' => 0.0];
        }

        $durations = array_values($durations);

        return [
            'p50' => $this->percentile($durations, 50),
            'p95' => $this->percentile($durations, 95),
            'p99' => $this->percentile($durations, 99),
        ];
    }

    /**
     * Get error rate for a time window.
     *
     * @return array{errors_per_minute: float, total_errors: int}
     */
    public function errorRate(int $windowUs): array
    {
        $since = $this->nowUs() - $windowUs;

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) as total FROM studio_events WHERE event_type = :type AND timestamp_us > :since',
        );
        $stmt->execute(['type' => 'exception', 'since' => $since]);
        /** @var array{total: int} $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $windowMinutes = (float) $windowUs / 60_000_000.0;
        $totalErrors = $row['total'];
        $epm = $windowMinutes > 0 ? (float) $totalErrors / $windowMinutes : 0.0;

        return [
            'errors_per_minute' => round($epm, 2),
            'total_errors' => $totalErrors,
        ];
    }

    /**
     * Get slow routes by P95 latency.
     *
     * @return list<array{route_name: string, p95_ms: float, count: int, avg_ms: float}>
     */
    public function slowRoutes(int $windowUs, int $limit = 10): array
    {
        $since = $this->nowUs() - $windowUs;

        $stmt = $this->pdo->prepare(
            "SELECT json_extract(payload_json, '$.route_name') as route_name,
                    json_extract(payload_json, '$.duration_ms') as duration_ms
             FROM studio_events
             WHERE event_type = :type AND timestamp_us > :since
               AND json_extract(payload_json, '$.route_name') IS NOT NULL",
        );
        $stmt->execute(['type' => 'http.response', 'since' => $since]);
        /** @var list<array{route_name: string, duration_ms: string}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        /** @var array<string, list<float>> $grouped */
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['route_name']][] = (float) $row['duration_ms'];
        }

        $result = [];
        foreach ($grouped as $route => $durations) {
            sort($durations);
            $result[] = [
                'route_name' => $route,
                'p95_ms' => $this->percentile($durations, 95),
                'count' => count($durations),
                'avg_ms' => round(array_sum($durations) / (float) count($durations), 2),
            ];
        }

        usort($result, static fn(array $a, array $b): int => $b['p95_ms'] <=> $a['p95_ms']);

        return array_slice($result, 0, $limit);
    }

    /**
     * Get slow database queries by P95 duration, grouped by fingerprint.
     *
     * @return list<array{sql_fingerprint: string, sql: string, p95_ms: float, count: int, avg_ms: float}>
     */
    public function slowQueries(int $windowUs, int $limit = 10): array
    {
        $since = $this->nowUs() - $windowUs;

        $stmt = $this->pdo->prepare(
            "SELECT json_extract(payload_json, '$.sql_fingerprint') as sql_fingerprint,
                    json_extract(payload_json, '$.sql') as sql,
                    json_extract(payload_json, '$.duration_ms') as duration_ms
             FROM studio_events
             WHERE event_type = :type AND timestamp_us > :since",
        );
        $stmt->execute(['type' => 'db.query', 'since' => $since]);
        /** @var list<array{sql_fingerprint: string, sql: string, duration_ms: string}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        /** @var array<string, array{sql: string, durations: list<float>}> $grouped */
        $grouped = [];
        foreach ($rows as $row) {
            $fp = $row['sql_fingerprint'];
            if (!isset($grouped[$fp])) {
                $grouped[$fp] = ['sql' => $row['sql'], 'durations' => []];
            }
            $grouped[$fp]['durations'][] = (float) $row['duration_ms'];
        }

        $result = [];
        foreach ($grouped as $fp => $data) {
            $durations = $data['durations'];
            sort($durations);
            $result[] = [
                'sql_fingerprint' => $fp,
                'sql' => $data['sql'],
                'p95_ms' => $this->percentile($durations, 95),
                'count' => count($durations),
                'avg_ms' => round(array_sum($durations) / (float) count($durations), 2),
            ];
        }

        usort($result, static fn(array $a, array $b): int => $b['p95_ms'] <=> $a['p95_ms']);

        return array_slice($result, 0, $limit);
    }

    /**
     * Get event count by type for a time window.
     *
     * @return array<string, int>
     */
    public function eventCountsByType(int $windowUs): array
    {
        $since = $this->nowUs() - $windowUs;

        $stmt = $this->pdo->prepare(
            'SELECT event_type, COUNT(*) as cnt FROM studio_events WHERE timestamp_us > :since GROUP BY event_type',
        );
        $stmt->execute(['since' => $since]);
        /** @var list<array{event_type: string, cnt: int}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['event_type']] = $row['cnt'];
        }

        return $counts;
    }

    /**
     * Calculate percentile from a sorted list of values.
     *
     * @param list<float> $sorted Already sorted ascending
     */
    private function percentile(array $sorted, int $p): float
    {
        $count = count($sorted);
        if ($count === 0) {
            return 0.0;
        }

        $index = (int) ceil(((float) $p / 100.0) * (float) $count) - 1;
        $index = max(0, min($count - 1, $index));

        return round($sorted[$index], 2);
    }

    private function nowUs(): int
    {
        return (int) (microtime(true) * 1_000_000.0);
    }

    private function resolvePdo(EventStoreInterface $store): PDO
    {
        if ($store instanceof EncryptedEventStore) {
            return $store->inner()->pdo();
        }

        if ($store instanceof SqliteEventStore) {
            return $store->pdo();
        }

        // Fallback: create empty in-memory SQLite for type safety
        return new PDO('sqlite::memory:');
    }
}
