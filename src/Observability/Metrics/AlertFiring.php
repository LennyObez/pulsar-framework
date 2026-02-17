<?php

declare(strict_types=1);

namespace Pulsar\Observability\Metrics;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Record of an alert that has fired.
 */
#[Api(since: '1.0.0')]
readonly class AlertFiring
{
    public function __construct(
        public AlertThreshold $threshold,
        public float $actualValue,
        public float $firedAt,
    ) {}

    #[NoDiscard]
    public static function from(AlertThreshold $threshold, float $actualValue): self
    {
        return new self(
            threshold: $threshold,
            actualValue: $actualValue,
            firedAt: microtime(true),
        );
    }
}
