<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Internal\Diagnostics;

use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Collector\SqlNormalizer;

use function array_slice;
use function count;
use function microtime;
use function usort;

/**
 * Flags database queries that exceed a configurable duration threshold.
 *
 * Designed for integration with the Studio database explorer: slow queries
 * are collected per-request and surfaced as alerts in the UI. The detector
 * resets between requests for persistent runtime safety.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final class SlowQueryAlert
{
    /** @var list<SlowQueryRecord> */
    private array $alerts = [];

    /**
     * @param float $thresholdMs Queries slower than this are flagged (default 100ms)
     * @param int $maxAlerts Maximum alerts to retain per request
     */
    public function __construct(
        private readonly float $thresholdMs = 100.0,
        private readonly int $maxAlerts = 200,
    ) {}

    /**
     * Evaluate a query and record it if it exceeds the threshold.
     */
    public function evaluate(string $sql, float $durationMs, ?string $connectionName = null): void
    {
        if ($durationMs < $this->thresholdMs) {
            return;
        }

        if (count($this->alerts) >= $this->maxAlerts) {
            return;
        }

        $normalized = SqlNormalizer::normalize($sql);
        $fingerprint = SqlNormalizer::fingerprint($sql);
        $queryType = SqlNormalizer::detectQueryType($sql);

        $this->alerts[] = new SlowQueryRecord(
            sql: $normalized,
            fingerprint: $fingerprint,
            durationMs: $durationMs,
            thresholdMs: $this->thresholdMs,
            queryType: $queryType,
            connectionName: $connectionName,
            recordedAt: microtime(true),
        );
    }

    /**
     * Get all slow query alerts for the current request.
     *
     * @return list<SlowQueryRecord>
     */
    public function alerts(): array
    {
        return $this->alerts;
    }

    /**
     * Get the top N slowest queries, ordered by duration descending.
     *
     * @return list<SlowQueryRecord>
     */
    public function slowest(int $limit = 10): array
    {
        $sorted = $this->alerts;

        usort($sorted, static fn(SlowQueryRecord $a, SlowQueryRecord $b): int => $b->durationMs <=> $a->durationMs);

        return array_slice($sorted, 0, $limit);
    }

    /**
     * Check whether any queries exceeded the threshold.
     */
    public function hasAlerts(): bool
    {
        return $this->alerts !== [];
    }

    /**
     * Get the count of slow queries.
     */
    public function count(): int
    {
        return count($this->alerts);
    }

    /**
     * Get the configured threshold in milliseconds.
     */
    public function thresholdMs(): float
    {
        return $this->thresholdMs;
    }

    /**
     * Export all alerts as serializable arrays.
     *
     * @return list<array{sql: string, fingerprint: string, duration_ms: float, threshold_ms: float, query_type: string, connection_name: ?string}>
     */
    public function toArray(): array
    {
        $result = [];

        foreach ($this->alerts as $record) {
            $result[] = [
                'sql' => $record->sql,
                'fingerprint' => $record->fingerprint,
                'duration_ms' => $record->durationMs,
                'threshold_ms' => $record->thresholdMs,
                'query_type' => $record->queryType,
                'connection_name' => $record->connectionName,
            ];
        }

        return $result;
    }

    /**
     * Reset all alerts for a new request cycle.
     */
    public function reset(): void
    {
        $this->alerts = [];
    }
}
