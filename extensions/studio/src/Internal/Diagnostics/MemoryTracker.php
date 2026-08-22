<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Internal\Diagnostics;

use Pulsar\Api\Internal;

use function abs;
use function array_shift;
use function array_slice;
use function assert;
use function count;
use function memory_get_peak_usage;
use function memory_get_usage;
use function microtime;

/**
 * Tracks memory usage across requests in persistent runtimes.
 *
 * Records a snapshot at the end of each request lifecycle. Over time,
 * a sustained upward trend indicates a potential memory leak. Designed
 * for use with RoadRunner, FrankenPHP, and other long-lived workers.
 *
 * The tracker uses a fixed-size ring buffer to bound memory overhead.
 * Leak detection uses linear regression over the snapshot window.
 */
#[Internal]
final class MemoryTracker
{
    /** @var list<MemorySnapshot> */
    private array $snapshots = [];

    /**
     * @param int $maxSnapshots Maximum snapshots to retain (ring buffer size)
     * @param int $leakThresholdBytes Minimum growth to flag as suspicious leak
     * @param int $minSamplesForDetection Minimum snapshots before leak detection activates
     */
    public function __construct(
        private readonly int $maxSnapshots = 200,
        private readonly int $leakThresholdBytes = 2 * 1024 * 1024,
        private readonly int $minSamplesForDetection = 10,
    ) {}

    /**
     * Record a memory snapshot at the end of a request.
     *
     * @param int|null $requestNumber Sequential request number in the worker lifecycle
     */
    public function snapshot(?int $requestNumber = null): MemorySnapshot
    {
        $snapshot = new MemorySnapshot(
            usageBytes: memory_get_usage(true),
            peakBytes: memory_get_peak_usage(true),
            requestNumber: $requestNumber ?? count($this->snapshots) + 1,
            timestamp: microtime(true),
        );

        $this->snapshots[] = $snapshot;

        if (count($this->snapshots) > $this->maxSnapshots) {
            array_shift($this->snapshots);
        }

        return $snapshot;
    }

    /**
     * Record a snapshot with explicit memory values (for testing).
     */
    public function recordExplicit(int $usageBytes, int $peakBytes, ?int $requestNumber = null): MemorySnapshot
    {
        $snapshot = new MemorySnapshot(
            usageBytes: $usageBytes,
            peakBytes: $peakBytes,
            requestNumber: $requestNumber ?? count($this->snapshots) + 1,
            timestamp: microtime(true),
        );

        $this->snapshots[] = $snapshot;

        if (count($this->snapshots) > $this->maxSnapshots) {
            array_shift($this->snapshots);
        }

        return $snapshot;
    }

    /**
     * Detect whether memory usage shows a leak pattern.
     *
     * Uses linear regression slope over the snapshot window.
     * Returns null if insufficient data, or a LeakReport if a leak is suspected.
     */
    public function detectLeak(): ?LeakReport
    {
        if (count($this->snapshots) < $this->minSamplesForDetection) {
            return null;
        }

        $n = count($this->snapshots);
        $sumX = 0.0;
        $sumY = 0.0;
        $sumXy = 0.0;
        $sumXx = 0.0;

        foreach ($this->snapshots as $i => $snapshot) {
            $x = (float) $i;
            $y = (float) $snapshot->usageBytes;
            $sumX += $x;
            $sumY += $y;
            $sumXy += $x * $y;
            $sumXx += $x * $x;
        }

        $denominator = ($n * $sumXx) - ($sumX * $sumX);

        if (abs($denominator) < 0.001) {
            return null;
        }

        $slope = (($n * $sumXy) - ($sumX * $sumY)) / $denominator;

        $first = $this->snapshots[0];
        $lastIndex = $n - 1;
        assert($lastIndex >= 0);
        $last = $this->snapshots[$lastIndex];
        $totalGrowth = $last->usageBytes - $first->usageBytes;

        // Positive slope + total growth above threshold = potential leak
        if ($slope > 0.0 && $totalGrowth >= $this->leakThresholdBytes) {
            $growthPerRequest = (int) $slope;

            return new LeakReport(
                suspected: true,
                growthPerRequestBytes: $growthPerRequest,
                totalGrowthBytes: $totalGrowth,
                sampleCount: $n,
                firstUsageBytes: $first->usageBytes,
                lastUsageBytes: $last->usageBytes,
                peakBytes: $last->peakBytes,
            );
        }

        return null;
    }

    /**
     * Get the current memory usage in bytes.
     */
    public function currentUsageBytes(): int
    {
        if ($this->snapshots === []) {
            return memory_get_usage(true);
        }

        return $this->snapshots[count($this->snapshots) - 1]->usageBytes;
    }

    /**
     * Get the peak memory usage seen across all snapshots.
     */
    public function peakUsageBytes(): int
    {
        $peak = 0;

        foreach ($this->snapshots as $snapshot) {
            if ($snapshot->peakBytes > $peak) {
                $peak = $snapshot->peakBytes;
            }
        }

        return $peak;
    }

    /**
     * Get all recorded snapshots.
     *
     * @return list<MemorySnapshot>
     */
    public function snapshots(): array
    {
        return $this->snapshots;
    }

    /**
     * Get the most recent N snapshots.
     *
     * @return list<MemorySnapshot>
     */
    public function recentSnapshots(int $count = 50): array
    {
        if (count($this->snapshots) <= $count) {
            return $this->snapshots;
        }

        return array_slice($this->snapshots, -$count);
    }

    /**
     * Get the number of snapshots recorded.
     */
    public function snapshotCount(): int
    {
        return count($this->snapshots);
    }

    /**
     * Export snapshot data for frontend visualization.
     *
     * @return list<array{usage_bytes: int, peak_bytes: int, request_number: int, timestamp: float}>
     */
    public function toArray(): array
    {
        $result = [];

        foreach ($this->snapshots as $snapshot) {
            $result[] = [
                'usage_bytes' => $snapshot->usageBytes,
                'peak_bytes' => $snapshot->peakBytes,
                'request_number' => $snapshot->requestNumber,
                'timestamp' => $snapshot->timestamp,
            ];
        }

        return $result;
    }

    /**
     * Reset all tracking state.
     */
    public function reset(): void
    {
        $this->snapshots = [];
    }
}
