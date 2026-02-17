<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_float;
use function is_int;
use function is_string;

/**
 * Transaction risk analysis configuration.
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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            lowThreshold: is_float($data['low_threshold'] ?? null) ? $data['low_threshold'] : 0.3,
            highThreshold: is_float($data['high_threshold'] ?? null) ? $data['high_threshold'] : 0.7,
            velocityWindowSeconds: is_int($data['velocity_window_seconds'] ?? null) ? $data['velocity_window_seconds'] : 3600,
            velocityMaxCount: is_int($data['velocity_max_count'] ?? null) ? $data['velocity_max_count'] : 10,
            velocityMaxAmountMinorUnits: is_int($data['velocity_max_amount_minor_units'] ?? null) ? $data['velocity_max_amount_minor_units'] : 50000,
            lowValueThresholdMinorUnits: is_int($data['low_value_threshold_minor_units'] ?? null) ? $data['low_value_threshold_minor_units'] : 3000,
            velocityTracker: is_string($data['velocity_tracker'] ?? null) ? $data['velocity_tracker'] : 'memory',
        );
    }
}
