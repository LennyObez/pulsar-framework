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
 * Detects N+1 query patterns by tracking SQL fingerprints per request.
 *
 * A query is flagged as N+1 when the same fingerprint appears more than
 * the configured threshold times within a single request lifecycle.
 * The detector resets between requests, making it safe for persistent runtimes.
 */
#[Internal]
final class NplusOneDetector
{
    /** @var array<string, NplusOneGroup> */
    private array $groups = [];

    private float $requestStartedAt = 0.0;

    /**
     * @param int $threshold Minimum repeat count to flag as N+1
     * @param int $maxGroups Maximum number of groups to track per request
     */
    public function __construct(
        private readonly int $threshold = 5,
        private readonly int $maxGroups = 100,
    ) {}

    /**
     * Record a query execution. Call this for every query in a request.
     */
    public function record(string $sql, float $durationMs): void
    {
        if ($this->requestStartedAt === 0.0) {
            $this->requestStartedAt = microtime(true);
        }

        $fingerprint = SqlNormalizer::fingerprint($sql);
        $normalized = SqlNormalizer::normalize($sql);

        if (!isset($this->groups[$fingerprint])) {
            if (count($this->groups) >= $this->maxGroups) {
                return;
            }

            $this->groups[$fingerprint] = new NplusOneGroup(
                fingerprint: $fingerprint,
                normalizedSql: $normalized,
                count: 0,
                totalDurationMs: 0.0,
            );
        }

        $group = $this->groups[$fingerprint];
        $this->groups[$fingerprint] = new NplusOneGroup(
            fingerprint: $group->fingerprint,
            normalizedSql: $group->normalizedSql,
            count: $group->count + 1,
            totalDurationMs: $group->totalDurationMs + $durationMs,
        );
    }

    /**
     * Get all detected N+1 violations (groups exceeding the threshold).
     *
     * @return list<NplusOneGroup>
     */
    public function violations(): array
    {
        $violations = [];

        foreach ($this->groups as $group) {
            if ($group->count >= $this->threshold) {
                $violations[] = $group;
            }
        }

        usort($violations, static fn(NplusOneGroup $a, NplusOneGroup $b): int => $b->count <=> $a->count);

        return $violations;
    }

    /**
     * Check whether any N+1 patterns were detected.
     */
    public function hasViolations(): bool
    {
        foreach ($this->groups as $group) {
            if ($group->count >= $this->threshold) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the total number of distinct query fingerprints tracked.
     */
    public function distinctQueryCount(): int
    {
        return count($this->groups);
    }

    /**
     * Get the configured threshold.
     */
    public function threshold(): int
    {
        return $this->threshold;
    }

    /**
     * Get top N groups by count, regardless of threshold.
     *
     * @return list<NplusOneGroup>
     */
    public function topGroups(int $limit = 10): array
    {
        $groups = [];

        foreach ($this->groups as $group) {
            $groups[] = $group;
        }

        usort($groups, static fn(NplusOneGroup $a, NplusOneGroup $b): int => $b->count <=> $a->count);

        return array_slice($groups, 0, $limit);
    }

    /**
     * Export all violations as serializable arrays.
     *
     * @return list<array{fingerprint: string, sql: string, count: int, total_duration_ms: float}>
     */
    public function toArray(): array
    {
        $result = [];

        foreach ($this->violations() as $group) {
            $result[] = [
                'fingerprint' => $group->fingerprint,
                'sql' => $group->normalizedSql,
                'count' => $group->count,
                'total_duration_ms' => $group->totalDurationMs,
            ];
        }

        return $result;
    }

    /**
     * Reset all state for a new request cycle.
     */
    public function reset(): void
    {
        $this->groups = [];
        $this->requestStartedAt = 0.0;
    }
}
