<?php

declare(strict_types=1);

namespace Pulsar\Observability\Metrics;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Readonly value object capturing point-in-time metric state.
 */
#[Api(since: '1.0.0')]
final readonly class MetricSnapshot
{
    /**
     * @param array<string, float>                                                       $values     Label-key => value (counters/gauges)
     * @param array<string, array{buckets: array<int|string, int>, sum: float, count: int}>|null $series     Histogram series data
     * @param list<float>                                                                $boundaries Histogram bucket boundaries
     */
    public function __construct(
        public string $name,
        public MetricType $type,
        public string $help,
        public array $values = [],
        public ?array $series = null,
        public array $boundaries = [],
    ) {}

    /**
     * Create a snapshot from a counter.
     */
    #[NoDiscard]
    public static function fromCounter(Counter $counter): self
    {
        return new self(
            name: $counter->name,
            type: MetricType::Counter,
            help: $counter->help,
            values: $counter->values(),
        );
    }

    /**
     * Create a snapshot from a gauge.
     */
    #[NoDiscard]
    public static function fromGauge(Gauge $gauge): self
    {
        return new self(
            name: $gauge->name,
            type: MetricType::Gauge,
            help: $gauge->help,
            values: $gauge->values(),
        );
    }

    /**
     * Create a snapshot from a histogram.
     */
    #[NoDiscard]
    public static function fromHistogram(Histogram $histogram): self
    {
        $series = [];

        foreach ($histogram->seriesKeys() as $key) {
            $labelSet = new LabelSet();

            $series[$key] = [
                'buckets' => $histogram->buckets($labelSet),
                'sum' => $histogram->sum($labelSet),
                'count' => $histogram->count($labelSet),
            ];
        }

        return new self(
            name: $histogram->name,
            type: MetricType::Histogram,
            help: $histogram->help,
            series: $series,
            boundaries: $histogram->boundaries(),
        );
    }
}
