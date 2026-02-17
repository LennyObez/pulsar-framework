<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Internal\Diagnostics;

use Pulsar\Api\Internal;

/**
 * Result of memory leak detection analysis.
 */
#[Internal]
final readonly class LeakReport
{
    public function __construct(
        public bool $suspected,
        public int $growthPerRequestBytes,
        public int $totalGrowthBytes,
        public int $sampleCount,
        public int $firstUsageBytes,
        public int $lastUsageBytes,
        public int $peakBytes,
    ) {}

    /**
     * Export as a serializable array.
     *
     * @return array{suspected: bool, growth_per_request_bytes: int, total_growth_bytes: int, sample_count: int, first_usage_bytes: int, last_usage_bytes: int, peak_bytes: int}
     */
    public function toArray(): array
    {
        return [
            'suspected' => $this->suspected,
            'growth_per_request_bytes' => $this->growthPerRequestBytes,
            'total_growth_bytes' => $this->totalGrowthBytes,
            'sample_count' => $this->sampleCount,
            'first_usage_bytes' => $this->firstUsageBytes,
            'last_usage_bytes' => $this->lastUsageBytes,
            'peak_bytes' => $this->peakBytes,
        ];
    }
}
