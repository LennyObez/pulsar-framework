<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Aggregation;

use JsonException;
use Override;
use PDO;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Storage\EncryptedEventStore;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\Console\Storage\SqliteEventStore;

use function array_fill;
use function array_filter;
use function array_map;
use function array_reverse;
use function array_slice;
use function array_sum;
use function array_values;
use function ceil;
use function count;
use function date;
use function in_array;
use function intdiv;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function max;
use function microtime;
use function min;
use function round;
use function sort;
use function usort;

use const JSON_THROW_ON_ERROR;

/**
 * Aggregates dashboard metrics from the Studio event store using SQL queries.
 *
 * Runs aggregate queries (COUNT, AVG, percentiles) against the SQLite store.
 * Supports conditional section rendering: only returns sections for event
 * types that have recorded events.
 */
#[Internal]
final readonly class DashboardAggregator implements DashboardAggregatorInterface
{
    private const int TOP_EXCEPTIONS_LIMIT = 10;
    private const int AGGREGATION_QUERY_LIMIT = 10_000;

    private PDO $pdo;

    private EventStoreInterface $store;

    private bool $encrypted;

    public function __construct(
        EventStoreInterface $store,
    ) {
        $this->store = $store;
        $this->pdo = $this->resolvePdo($store);
        $this->encrypted = $store instanceof EncryptedEventStore;
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
            'benchmark' => ['benchmark.run'],
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
     * @return array{per_minute: float, total: int, by_status: array<string, int>}
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
            'per_minute' => round($rpm, 2),
            'total' => $total,
            'by_status' => $this->statusBreakdown($windowUs),
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

        if ($this->encrypted) {
            return $this->latencyPercentilesFromStore($since);
        }

        $stmt = $this->pdo->prepare(
            "SELECT json_extract(payload_json, '$.duration_ms') as duration_ms
             FROM studio_events
             WHERE event_type = :type AND timestamp_us > :since AND json_valid(payload_json)
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
     * @return array{per_minute: float, total: int, top_exceptions: list<array{class: string, count: int, last_seen_us: int}>}
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
            'per_minute' => round($epm, 2),
            'total' => $totalErrors,
            'top_exceptions' => $this->topExceptions($windowUs),
        ];
    }

    /**
     * Get slow routes by P95 latency.
     *
     * @return list<array{route: string, p95_ms: float, count: int, avg_ms: float}>
     */
    public function slowRoutes(int $windowUs, int $limit = 10): array
    {
        $since = $this->nowUs() - $windowUs;

        if ($this->encrypted) {
            return $this->slowRoutesFromStore($since, $limit);
        }

        $stmt = $this->pdo->prepare(
            "SELECT json_extract(payload_json, '$.route_name') as route_name,
                    json_extract(payload_json, '$.duration_ms') as duration_ms
             FROM studio_events
             WHERE event_type = :type AND timestamp_us > :since AND json_valid(payload_json)
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
        foreach ($grouped as $routeName => $durations) {
            sort($durations);
            $result[] = [
                'route' => $routeName,
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

        if ($this->encrypted) {
            return $this->slowQueriesFromStore($since, $limit);
        }

        $stmt = $this->pdo->prepare(
            "SELECT json_extract(payload_json, '$.sql_fingerprint') as sql_fingerprint,
                    json_extract(payload_json, '$.sql') as sql,
                    json_extract(payload_json, '$.duration_ms') as duration_ms
             FROM studio_events
             WHERE event_type = :type AND timestamp_us > :since AND json_valid(payload_json)",
        );
        $stmt->execute(['type' => 'db.query', 'since' => $since]);
        /** @var list<array{sql_fingerprint: string|null, sql: string|null, duration_ms: string|null}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        /** @var array<string, array{sql: string, durations: list<float>}> $grouped */
        $grouped = [];
        foreach ($rows as $row) {
            $fp = $row['sql_fingerprint'] ?? null;
            if ($fp === null) {
                continue;
            }
            if (!isset($grouped[$fp])) {
                $grouped[$fp] = ['sql' => $row['sql'] ?? '', 'durations' => []];
            }
            $grouped[$fp]['durations'][] = (float) ($row['duration_ms'] ?? 0);
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
     * Get throughput time-series for sparkline visualization.
     *
     * Divides the window into buckets and returns counts per bucket.
     *
     * @return list<int>
     */
    public function throughputTimeSeries(int $windowUs, int $buckets = 12): array
    {
        return $this->eventTimeSeries('http.response', $windowUs, $buckets);
    }

    /**
     * Get error time-series for sparkline visualization.
     *
     * @return list<int>
     */
    public function errorTimeSeries(int $windowUs, int $buckets = 12): array
    {
        return $this->eventTimeSeries('exception', $windowUs, $buckets);
    }

    /**
     * Get time-series for a specific event type.
     *
     * @return list<int>
     */
    private function eventTimeSeries(string $eventType, int $windowUs, int $buckets): array
    {
        $since = $this->nowUs() - $windowUs;
        $bucketSize = intdiv($windowUs, $buckets);

        if ($bucketSize <= 0) {
            return array_fill(0, $buckets, 0);
        }

        $stmt = $this->pdo->prepare(
            'SELECT (timestamp_us - :since) / :bucket_size AS bucket, COUNT(*) AS cnt
             FROM studio_events
             WHERE event_type = :type AND timestamp_us > :since2
             GROUP BY bucket
             ORDER BY bucket',
        );
        $stmt->execute([
            'since' => $since,
            'bucket_size' => $bucketSize,
            'type' => $eventType,
            'since2' => $since,
        ]);
        /** @var list<array{bucket: int, cnt: int}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $series = array_fill(0, $buckets, 0);

        foreach ($rows as $row) {
            $idx = $row['bucket'];
            if ($idx >= 0 && $idx < $buckets) {
                $series[$idx] = $row['cnt'];
            }
        }

        /** @var list<int> */
        return array_values($series);
    }

    /**
     * Get status code breakdown grouped by class (2xx, 3xx, etc.).
     *
     * @return array<string, int>
     */
    private function statusBreakdown(int $windowUs): array
    {
        $since = $this->nowUs() - $windowUs;

        if ($this->encrypted) {
            return $this->statusBreakdownFromStore($since);
        }

        $stmt = $this->pdo->prepare(
            "SELECT json_extract(payload_json, '$.status_code') AS status_code, COUNT(*) AS cnt
             FROM studio_events
             WHERE event_type = :type AND timestamp_us > :since AND json_valid(payload_json)
             GROUP BY status_code",
        );
        $stmt->execute(['type' => 'http.response', 'since' => $since]);
        /** @var list<array{status_code: string|null, cnt: int}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        /** @var array<string, int> $byClass */
        $byClass = [];

        foreach ($rows as $row) {
            $code = (int) ($row['status_code'] ?? 0);
            if ($code <= 0) {
                continue;
            }
            $class = intdiv($code, 100) . 'xx';
            $byClass[$class] = ($byClass[$class] ?? 0) + $row['cnt'];
        }

        return $byClass;
    }

    /**
     * Get top exceptions grouped by class.
     *
     * @return list<array{class: string, count: int, last_seen_us: int}>
     */
    private function topExceptions(int $windowUs): array
    {
        $since = $this->nowUs() - $windowUs;

        if ($this->encrypted) {
            return $this->topExceptionsFromStore($since);
        }

        $stmt = $this->pdo->prepare(
            "SELECT json_extract(payload_json, '$.exception_class') AS exception_class,
                    COUNT(*) AS cnt,
                    MAX(timestamp_us) AS last_seen_us
             FROM studio_events
             WHERE event_type = :type AND timestamp_us > :since AND json_valid(payload_json)
             GROUP BY exception_class
             ORDER BY cnt DESC
             LIMIT :limit",
        );
        $stmt->bindValue(':type', 'exception');
        $stmt->bindValue(':since', $since, PDO::PARAM_INT);
        $stmt->bindValue(':limit', self::TOP_EXCEPTIONS_LIMIT, PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array{exception_class: string|null, cnt: int, last_seen_us: int}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'class' => $row['exception_class'] ?? 'Unknown',
                'count' => $row['cnt'],
                'last_seen_us' => $row['last_seen_us'],
            ];
        }

        return $result;
    }

    /**
     * Encrypted-store fallback: status code breakdown via PHP decoding.
     *
     * @return array<string, int>
     *
     * @throws JsonException If payload JSON cannot be decoded
     */
    private function statusBreakdownFromStore(int $sinceUs): array
    {
        /** @var list<array{payload_json: string}> $rows */
        $rows = $this->store->query(
            ['event_type' => 'http.response', 'since_us' => $sinceUs],
            limit: self::AGGREGATION_QUERY_LIMIT,
        );

        /** @var array<string, int> $byClass */
        $byClass = [];

        foreach ($rows as $row) {
            /** @var array{status_code?: int} $payload */
            $payload = json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            $code = $payload['status_code'] ?? 0;

            if ($code <= 0) {
                continue;
            }

            $class = intdiv($code, 100) . 'xx';
            $byClass[$class] = ($byClass[$class] ?? 0) + 1;
        }

        return $byClass;
    }

    /**
     * Encrypted-store fallback: latency percentiles via PHP decoding.
     *
     * @return array{p50: float, p95: float, p99: float}
     *
     * @throws JsonException If payload JSON cannot be decoded
     */
    private function latencyPercentilesFromStore(int $sinceUs): array
    {
        /** @var list<array{payload_json: string}> $rows */
        $rows = $this->store->query(
            ['event_type' => 'http.response', 'since_us' => $sinceUs],
            limit: self::AGGREGATION_QUERY_LIMIT,
        );

        $durations = [];

        foreach ($rows as $row) {
            /** @var array{duration_ms?: float} $payload */
            $payload = json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            $d = (float) ($payload['duration_ms'] ?? 0);

            if ($d > 0) {
                $durations[] = $d;
            }
        }

        if ($durations === []) {
            return ['p50' => 0.0, 'p95' => 0.0, 'p99' => 0.0];
        }

        sort($durations);

        return [
            'p50' => $this->percentile($durations, 50),
            'p95' => $this->percentile($durations, 95),
            'p99' => $this->percentile($durations, 99),
        ];
    }

    /**
     * Encrypted-store fallback: slow routes via PHP decoding.
     *
     * @return list<array{route: string, p95_ms: float, count: int, avg_ms: float}>
     *
     * @throws JsonException If payload JSON cannot be decoded
     */
    private function slowRoutesFromStore(int $sinceUs, int $limit): array
    {
        /** @var list<array{payload_json: string}> $rows */
        $rows = $this->store->query(
            ['event_type' => 'http.response', 'since_us' => $sinceUs],
            limit: self::AGGREGATION_QUERY_LIMIT,
        );

        /** @var array<string, list<float>> $grouped */
        $grouped = [];

        foreach ($rows as $row) {
            /** @var array{route_name?: string, duration_ms?: float} $payload */
            $payload = json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            $routeName = $payload['route_name'] ?? null;

            if ($routeName === null) {
                continue;
            }

            $grouped[$routeName][] = (float) ($payload['duration_ms'] ?? 0);
        }

        $result = [];

        foreach ($grouped as $routeName => $durations) {
            sort($durations);
            $result[] = [
                'route' => $routeName,
                'p95_ms' => $this->percentile($durations, 95),
                'count' => count($durations),
                'avg_ms' => round(array_sum($durations) / (float) count($durations), 2),
            ];
        }

        usort($result, static fn(array $a, array $b): int => $b['p95_ms'] <=> $a['p95_ms']);

        return array_slice($result, 0, $limit);
    }

    /**
     * Encrypted-store fallback: slow queries via PHP decoding.
     *
     * @return list<array{sql_fingerprint: string, sql: string, p95_ms: float, count: int, avg_ms: float}>
     *
     * @throws JsonException If payload JSON cannot be decoded
     */
    private function slowQueriesFromStore(int $sinceUs, int $limit): array
    {
        /** @var list<array{payload_json: string}> $rows */
        $rows = $this->store->query(
            ['event_type' => 'db.query', 'since_us' => $sinceUs],
            limit: self::AGGREGATION_QUERY_LIMIT,
        );

        /** @var array<string, array{sql: string, durations: list<float>}> $grouped */
        $grouped = [];

        foreach ($rows as $row) {
            /** @var array{sql_fingerprint?: string, sql?: string, duration_ms?: float} $payload */
            $payload = json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            $fp = $payload['sql_fingerprint'] ?? null;

            if ($fp === null) {
                continue;
            }

            if (!isset($grouped[$fp])) {
                $grouped[$fp] = ['sql' => $payload['sql'] ?? '', 'durations' => []];
            }

            $grouped[$fp]['durations'][] = (float) ($payload['duration_ms'] ?? 0);
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
     * Encrypted-store fallback: top exceptions via PHP decoding.
     *
     * @return list<array{class: string, count: int, last_seen_us: int}>
     *
     * @throws JsonException If payload JSON cannot be decoded
     */
    private function topExceptionsFromStore(int $sinceUs): array
    {
        /** @var list<array{payload_json: string, timestamp_us: int}> $rows */
        $rows = $this->store->query(
            ['event_type' => 'exception', 'since_us' => $sinceUs],
            limit: self::AGGREGATION_QUERY_LIMIT,
        );

        /** @var array<string, array{count: int, last_seen_us: int}> $grouped */
        $grouped = [];

        foreach ($rows as $row) {
            /** @var array{exception_class?: string} $payload */
            $payload = json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            $class = $payload['exception_class'] ?? 'Unknown';

            if (!isset($grouped[$class])) {
                $grouped[$class] = ['count' => 0, 'last_seen_us' => 0];
            }

            $grouped[$class]['count']++;
            $grouped[$class]['last_seen_us'] = max($grouped[$class]['last_seen_us'], $row['timestamp_us']);
        }

        $result = [];

        foreach ($grouped as $class => $data) {
            $result[] = [
                'class' => $class,
                'count' => $data['count'],
                'last_seen_us' => $data['last_seen_us'],
            ];
        }

        usort($result, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice($result, 0, self::TOP_EXCEPTIONS_LIMIT);
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

    /**
     * Get recent benchmark run summaries.
     *
     * Uses the store's query() method instead of raw SQL so that
     * EncryptedEventStore can transparently decrypt payload_json.
     *
     * @return list<array{run_id: string, profile_count: int, success_count: int, failure_count: int, skipped_count: int, total_duration_ms: float, php_version: string, timestamp_us: int}>
     *
     * @throws JsonException If payload JSON cannot be decoded
     */
    public function benchmarkRuns(int $limit = 10): array
    {
        /** @var list<array{payload_json: string, timestamp_us: int}> $rows */
        $rows = $this->store->query(['event_type' => 'benchmark.run'], $limit);

        $result = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR);

            if (!isset($payload['run_id'])) {
                continue;
            }

            $result[] = [
                'run_id' => $this->str($payload['run_id']),
                'profile_count' => $this->toInt($payload['profile_count'] ?? 0),
                'success_count' => $this->toInt($payload['success_count'] ?? 0),
                'failure_count' => $this->toInt($payload['failure_count'] ?? 0),
                'skipped_count' => $this->toInt($payload['skipped_count'] ?? 0),
                'total_duration_ms' => $this->toFloat($payload['total_duration_ms'] ?? 0.0),
                'php_version' => $this->str($payload['php_version'] ?? 'unknown'),
                'timestamp_us' => $row['timestamp_us'],
            ];
        }

        return $result;
    }

    /**
     * Get benchmark profile events for a specific run.
     *
     * Uses the store's query() method for encryption-transparent reads,
     * then filters by run_id in PHP (json_extract won't work on ciphertext).
     *
     * @return list<array{profile_name: string, boot_us: int, warm_boot_us: int, p50_us: int, p95_us: int, rps: int, peak_rss_kb: int, memory_usage_kb: int, opcache_memory_kb: ?int, optimize_enabled: bool}>
     *
     * @throws JsonException If payload JSON cannot be decoded
     */
    public function benchmarkProfiles(string $runId): array
    {
        /** @var list<array{payload_json: string, timestamp_us: int}> $rows */
        $rows = $this->store->query(['event_type' => 'benchmark.profile'], 500);

        $result = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR);

            if (!isset($payload['run_id']) || $payload['run_id'] !== $runId) {
                continue;
            }

            $result[] = [
                'profile_name' => $this->str($payload['profile_name'] ?? 'unknown'),
                'boot_us' => $this->toInt($payload['boot_us'] ?? 0),
                'warm_boot_us' => $this->toInt($payload['warm_boot_us'] ?? 0),
                'p50_us' => $this->toInt($payload['p50_us'] ?? 0),
                'p95_us' => $this->toInt($payload['p95_us'] ?? 0),
                'rps' => $this->toInt($payload['rps'] ?? 0),
                'peak_rss_kb' => $this->toInt($payload['peak_rss_kb'] ?? 0),
                'memory_usage_kb' => $this->toInt($payload['memory_usage_kb'] ?? 0),
                'opcache_memory_kb' => isset($payload['opcache_memory_kb']) ? $this->toInt($payload['opcache_memory_kb']) : null,
                'optimize_enabled' => !empty($payload['optimize_enabled']),
            ];
        }

        // Store query returns DESC; restore original execution order (ASC)
        return array_reverse($result);
    }

    /**
     * Aggregate all dashboard metrics into a single array.
     *
     * Uses a 5-minute default window for all sub-queries.
     *
     * @return array<string, mixed>
     *
     * @throws JsonException If benchmark payload JSON cannot be decoded
     */
    #[Override]
    public function aggregate(): array
    {
        $windowUs = 5 * 60 * 1_000_000;

        $throughput = $this->throughput($windowUs);
        $latency = $this->latencyPercentiles($windowUs);
        $errors = $this->errorRate($windowUs);
        $counts = $this->eventCountsByType($windowUs);
        $slowRoutes = $this->slowRoutes($windowUs);
        $slowQueries = $this->slowQueries($windowUs);

        $routes = [];
        foreach ($slowRoutes as $route) {
            $routes[] = [
                'path' => $route['route'],
                'hits' => $route['count'],
                'avg_ms' => $route['avg_ms'],
                'errors' => 0,
            ];
        }

        $exceptions = [];
        foreach ($errors['top_exceptions'] as $exc) {
            $exceptions[] = [
                'class' => $exc['class'],
                'count' => $exc['count'],
                'last_seen' => date('Y-m-d H:i:s', intdiv($exc['last_seen_us'], 1_000_000)),
            ];
        }

        $result = [
            'total_events' => array_sum(array_values($counts)),
            'total_requests' => $throughput['total'],
            'avg_response_ms' => $latency['p50'],
            'total_exceptions' => $errors['total'],
            'total_queries' => $counts['db.query'] ?? 0,
            'routes' => $routes,
            'exceptions' => $exceptions,
            'slow_queries' => $slowQueries,
        ];

        $benchmarkRuns = $this->benchmarkRuns(1);

        if ($benchmarkRuns !== []) {
            $latestRun = $benchmarkRuns[0];
            $result['benchmark'] = [
                'latest_run_id' => $latestRun['run_id'],
                'profile_count' => $latestRun['profile_count'],
                'success_count' => $latestRun['success_count'],
                'total_duration_ms' => $latestRun['total_duration_ms'],
            ];
        }

        return $result;
    }

    private function nowUs(): int
    {
        return (int) (microtime(true) * 1_000_000.0);
    }

    private function str(mixed $v): string
    {
        return is_string($v) ? $v : '';
    }

    private function toInt(mixed $v): int
    {
        return is_int($v) ? $v : 0;
    }

    private function toFloat(mixed $v): float
    {
        return is_float($v) || is_int($v) ? (float) $v : 0.0;
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
