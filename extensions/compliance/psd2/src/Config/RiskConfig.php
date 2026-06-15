<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

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
            lowThreshold: Coerce::strictFloat($data['low_threshold'] ?? null, 0.3),
            highThreshold: Coerce::strictFloat($data['high_threshold'] ?? null, 0.7),
            velocityWindowSeconds: Coerce::strictInt($data['velocity_window_seconds'] ?? null, 3600),
            velocityMaxCount: Coerce::strictInt($data['velocity_max_count'] ?? null, 10),
            velocityMaxAmountMinorUnits: Coerce::strictInt($data['velocity_max_amount_minor_units'] ?? null, 50000),
            lowValueThresholdMinorUnits: Coerce::strictInt($data['low_value_threshold_minor_units'] ?? null, 3000),
            velocityTracker: Coerce::string($data['velocity_tracker'] ?? null, 'memory'),
        );
    }
}
