<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use Pulsar\Api\Internal;

use function array_keys;
use function count;
use function sprintf;

/**
 * Result of a leak sentinel run with memory snapshots and pass/fail verdict.
 */
#[Internal]
final readonly class LeakSentinelReport
{
    /**
     * @param array<int, int> $snapshots Map of request number to memory usage in bytes
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public bool $passed,
        public array $snapshots,
        public int $growthBytes,
        public float $growthPercent,
        public string $summary,
    ) {}

    /**
     * Build a report from raw snapshots and configured thresholds.
     *
     * @param array<int, int> $snapshots
     */
    public static function fromSnapshots(
        array $snapshots,
        float $growthPercentThreshold,
        int $growthBytesThreshold,
    ): self {
        /** @var list<int> $points */
        $points = array_keys($snapshots);
        $pointCount = count($points);
        $firstSnapshot = $pointCount > 0 ? ($snapshots[$points[0]] ?? 0) : 0;
        $lastSnapshot = $pointCount > 0 ? ($snapshots[$points[$pointCount - 1]] ?? 0) : 0;

        // Use second snapshot (after warmup) for comparison if available
        $baselineSnapshot = $pointCount > 1
            ? ($snapshots[$points[1]] ?? $firstSnapshot)
            : $firstSnapshot;

        $growthBytes = $lastSnapshot - $baselineSnapshot;
        $growthPercent = $baselineSnapshot > 0
            ? ((float) $growthBytes / (float) $baselineSnapshot) * 100.0
            : 0.0;

        // Pass if growth is below BOTH thresholds (growth must exceed both to fail)
        $passed = $growthPercent < $growthPercentThreshold
            || $growthBytes < $growthBytesThreshold;

        $summary = sprintf(
            'Memory: %d → %d bytes (growth: %d bytes, %.2f%%). Threshold: %.1f%% or %d bytes. Result: %s',
            $baselineSnapshot,
            $lastSnapshot,
            $growthBytes,
            $growthPercent,
            $growthPercentThreshold,
            $growthBytesThreshold,
            $passed ? 'PASS' : 'FAIL',
        );

        return new self(
            passed: $passed,
            snapshots: $snapshots,
            growthBytes: $growthBytes,
            growthPercent: $growthPercent,
            summary: $summary,
        );
    }
}
