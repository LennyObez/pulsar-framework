<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Transaction risk analysis configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RiskConfig
{
    public function __construct(
        public float $lowThreshold = 0.3,
        public float $highThreshold = 0.7,
        public int $velocityWindowSeconds = 3600,
        public int $velocityMaxCount = 10,
        public int $velocityMaxAmountMinorUnits = 50000,
        public int $lowValueThresholdMinorUnits = 3000,
        public string $velocityTracker = 'memory',
    ) {}

    /**
     * @param array{
     *     low_threshold?: float,
     *     high_threshold?: float,
     *     velocity_window_seconds?: int,
     *     velocity_max_count?: int,
     *     velocity_max_amount_minor_units?: int,
     *     low_value_threshold_minor_units?: int,
     *     velocity_tracker?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            lowThreshold: $data['low_threshold'] ?? 0.3,
            highThreshold: $data['high_threshold'] ?? 0.7,
            velocityWindowSeconds: $data['velocity_window_seconds'] ?? 3600,
            velocityMaxCount: $data['velocity_max_count'] ?? 10,
            velocityMaxAmountMinorUnits: $data['velocity_max_amount_minor_units'] ?? 50000,
            lowValueThresholdMinorUnits: $data['low_value_threshold_minor_units'] ?? 3000,
            velocityTracker: $data['velocity_tracker'] ?? 'memory',
        );
    }
}
