<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Bridge;

use Pulsar\Api\Api;
use Pulsar\Extension\OpenTelemetry\Cardinality\CardinalityLimiter;
use Pulsar\Extension\OpenTelemetry\Internal\Export\MetricsBatchExporter;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpMetric;
use Pulsar\Observability\Metrics\Counter;
use Pulsar\Observability\Metrics\Gauge;
use Pulsar\Observability\Metrics\Histogram;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\Metrics\MetricSnapshot;
use Pulsar\Observability\Metrics\MetricType;

use function explode;
use function microtime;
use function str_contains;

/**
 * Collects metrics from the MetricRegistry, applies cardinality protection,
 * converts to OTLP format, and enqueues for batch export.
 */
#[Api(since: '1.0.0')]
final readonly class OtlpMeterBridge
{
    public function __construct(
        private MetricRegistry $registry,
        private MetricsBatchExporter $exporter,
        private ?CardinalityLimiter $limiter = null,
    ) {}

    /**
     * Collect all registered metrics and enqueue them for export.
     */
    public function collect(): void
    {
        $nowNano = (int) (microtime(true) * 1_000_000_000.0);

        foreach ($this->registry->all() as $metric) {
            $snapshot = match (true) {
                $metric instanceof Counter => MetricSnapshot::fromCounter($metric),
                $metric instanceof Gauge => MetricSnapshot::fromGauge($metric),
                $metric instanceof Histogram => MetricSnapshot::fromHistogram($metric),
            };

            $otlpMetric = $this->convertSnapshot($snapshot, $nowNano);
            $this->exporter->enqueue($otlpMetric);
        }
    }

    private function convertSnapshot(MetricSnapshot $snapshot, int $nowNano): OtlpMetric
    {
        $dataPoints = [];

        if ($snapshot->type === MetricType::Histogram && $snapshot->series !== null) {
            $boundaries = $snapshot->boundaries;

            foreach ($snapshot->series as $labelKey => $series) {
                $labels = $this->parseLabelKey($labelKey);
                $guardedLabels = $this->guardLabels($snapshot->name, $labels);

                // Convert cumulative bucket counts to per-bucket (delta) counts for OTLP
                $bucketCounts = self::cumulativeToDelta($series['buckets'], $boundaries, $series['count']);

                $dataPoints[] = [
                    'attributes' => $guardedLabels->toArray(),
                    'bucket_counts' => $bucketCounts,
                    'explicit_bounds' => $boundaries,
                    'sum' => $series['sum'],
                    'count' => $series['count'],
                    'time_unix_nano' => $nowNano,
                ];
            }
        } else {
            foreach ($snapshot->values as $labelKey => $value) {
                $labels = $this->parseLabelKey($labelKey);
                $guardedLabels = $this->guardLabels($snapshot->name, $labels);

                $dataPoints[] = [
                    'attributes' => $guardedLabels->toArray(),
                    'value' => $value,
                    'time_unix_nano' => $nowNano,
                ];
            }
        }

        return new OtlpMetric(
            name: $snapshot->name,
            description: $snapshot->help,
            unit: '',
            type: $snapshot->type,
            dataPoints: $dataPoints,
        );
    }

    /**
     * Parse a label key string back into a LabelSet.
     *
     * Label key format: "key1=value1,key2=value2" (sorted by key).
     */
    private function parseLabelKey(string $labelKey): LabelSet
    {
        if ($labelKey === '') {
            return new LabelSet();
        }

        $labels = [];
        $pairs = explode(',', $labelKey);

        foreach ($pairs as $pair) {
            if (str_contains($pair, '=')) {
                $parts = explode('=', $pair, 2);
                $labels[$parts[0]] = $parts[1] ?? '';
            }
        }

        return new LabelSet($labels);
    }

    /**
     * Convert cumulative bucket counts to per-bucket (delta) counts.
     *
     * OTLP HistogramDataPoint.bucket_counts expects N+1 entries:
     * bucket[i] = count of values in (bounds[i-1], bounds[i]]
     * bucket[N] = count of values in (bounds[N-1], +inf)
     *
     * @param array<int|string, int> $cumulativeBuckets Cumulative bucket counts keyed by boundary string
     * @param list<float> $boundaries Sorted bucket boundaries
     * @param int $totalCount Total observation count
     * @return list<int>
     */
    private static function cumulativeToDelta(array $cumulativeBuckets, array $boundaries, int $totalCount): array
    {
        $delta = [];
        $prevCumulative = 0;

        foreach ($boundaries as $bound) {
            $key = (string) $bound;
            $cumulative = $cumulativeBuckets[$key] ?? $cumulativeBuckets[(int) $key] ?? 0;
            $delta[] = $cumulative - $prevCumulative;
            $prevCumulative = $cumulative;
        }

        // Overflow bucket: values above the last boundary
        $delta[] = $totalCount - $prevCumulative;

        return $delta;
    }

    private function guardLabels(string $metricName, LabelSet $labels): LabelSet
    {
        if ($this->limiter === null) {
            return $labels;
        }

        return $this->limiter->guard($metricName, $labels);
    }
}
