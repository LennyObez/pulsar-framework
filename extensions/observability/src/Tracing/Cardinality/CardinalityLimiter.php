<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tracing\Cardinality;

use Pulsar\Api\Api;
use Pulsar\Observability\Metrics\LabelSet;

use function array_key_exists;
use function count;

/**
 * Limits the number of unique metric series per metric name.
 *
 * When a metric exceeds its series limit, new label combinations are
 * collapsed into a single overflow bucket to prevent unbounded memory growth.
 */
#[Api(since: '1.0.0')]
final class CardinalityLimiter
{
    /** @var array<string, array<string, true>> Tracks unique label keys per metric */
    private array $seriesKeys = [];

    public function __construct(
        private readonly int $maxMetricSeries = 2000,
    ) {}

    /**
     * Guard a metric series against cardinality explosion.
     *
     * Returns the original LabelSet if within limits, or an overflow
     * LabelSet if the metric has exceeded its series cap.
     */
    public function guard(string $metricName, LabelSet $labels): LabelSet
    {
        if (!array_key_exists($metricName, $this->seriesKeys)) {
            $this->seriesKeys[$metricName] = [];
        }

        $labelKey = $labels->key();

        if (array_key_exists($labelKey, $this->seriesKeys[$metricName])) {
            return $labels;
        }

        if ($this->seriesCount($metricName) >= $this->maxMetricSeries) {
            return new LabelSet(['__overflow' => 'true']);
        }

        $this->seriesKeys[$metricName][$labelKey] = true;

        return $labels;
    }

    /**
     * Reset all tracked series, allowing long-running processes to reclaim memory.
     */
    public function reset(): void
    {
        $this->seriesKeys = [];
    }

    private function seriesCount(string $metricName): int
    {
        return isset($this->seriesKeys[$metricName])
            ? count($this->seriesKeys[$metricName])
            : 0;
    }
}
