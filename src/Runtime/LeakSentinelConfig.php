<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use Pulsar\Api\Internal;

use function is_array;
use function is_float;
use function is_int;

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
     * @param array<string, mixed> $data
     * @return list<int>
     */
    private static function parseSnapshotPoints(array $data): array
    {
        if (!isset($data['snapshot_points']) || !is_array($data['snapshot_points'])) {
            return [100, 1_000, 5_000, 10_000];
        }

        $result = [];

        foreach ($data['snapshot_points'] as $point) {
            if (is_int($point)) {
                $result[] = $point;
            }
        }

        return $result !== [] ? $result : [100, 1_000, 5_000, 10_000];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            totalRequests: isset($data['total_requests']) && is_int($data['total_requests'])
                ? $data['total_requests'] : 10_000,
            snapshotPoints: self::parseSnapshotPoints($data),
            growthPercentThreshold: isset($data['growth_percent_threshold']) && is_float($data['growth_percent_threshold'])
                ? $data['growth_percent_threshold'] : 5.0,
            growthBytesThreshold: isset($data['growth_bytes_threshold']) && is_int($data['growth_bytes_threshold'])
                ? $data['growth_bytes_threshold'] : 2_097_152,
        );
    }
}
