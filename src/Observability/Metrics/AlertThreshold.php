<?php

declare(strict_types=1);

namespace Pulsar\Observability\Metrics;

use Pulsar\Api\Api;

/**
 * Defines an alert threshold for a metric.
 *
 * When the metric value exceeds (or falls below) the threshold for the
 * configured duration, the alert fires.
 */
#[Api(since: '1.0.0')]
readonly class AlertThreshold
{
    /**
     * @param string $metricName  Name of the metric to watch
     * @param float  $value       Threshold value
     * @param string $comparator  One of: "gt", "gte", "lt", "lte", "eq"
     * @param int    $forSeconds  Duration the condition must hold before firing
     * @param string $description Human-readable alert description
     */
    public function __construct(
        public string $metricName,
        public float $value,
        public string $comparator,
        public int $forSeconds,
        public string $description = '',
    ) {}

    /**
     * Evaluate whether a metric value satisfies this threshold condition.
     */
    public function isSatisfiedBy(float $metricValue): bool
    {
        return match ($this->comparator) {
            'gt' => $metricValue > $this->value,
            'gte' => $metricValue >= $this->value,
            'lt' => $metricValue < $this->value,
            'lte' => $metricValue <= $this->value,
            'eq' => $metricValue === $this->value,
            default => false,
        };
    }
}
