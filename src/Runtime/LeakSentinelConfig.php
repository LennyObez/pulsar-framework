<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use Pulsar\Api\Internal;
use Pulsar\Support\Coerce;

/**
 * Configuration for the leak sentinel CI gate.
 */
#[Internal]
final readonly class LeakSentinelConfig
{
    /**
     * @param int $totalRequests Total requests to simulate
     * @param list<int> $snapshotPoints Request numbers at which to take memory snapshots
     * @param float $growthPercentThreshold Maximum allowed memory growth percentage
     * @param int $growthBytesThreshold Maximum allowed memory growth in bytes
     */
    public function __construct(
        public int $totalRequests = 10_000,
        public array $snapshotPoints = [100, 1_000, 5_000, 10_000],
        public float $growthPercentThreshold = 5.0,
        public int $growthBytesThreshold = 2_097_152,
    ) {}

    /**
     * @param array{
     *     total_requests?: int,
     *     snapshot_points?: list<int>,
     *     growth_percent_threshold?: float,
     *     growth_bytes_threshold?: int,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $snapshotPoints = Coerce::listOfInt($data['snapshot_points'] ?? null);

        return new self(
            totalRequests: Coerce::int($data['total_requests'] ?? null, 10_000),
            snapshotPoints: $snapshotPoints !== [] ? $snapshotPoints : [100, 1_000, 5_000, 10_000],
            growthPercentThreshold: Coerce::float($data['growth_percent_threshold'] ?? null, 5.0),
            growthBytesThreshold: Coerce::int($data['growth_bytes_threshold'] ?? null, 2_097_152),
        );
    }
}
