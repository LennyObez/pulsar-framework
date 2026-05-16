<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Internal\Protobuf;

use Pulsar\Api\Internal;
use Pulsar\Observability\Metrics\MetricType;

use function is_array;
use function is_float;
use function is_int;
use function is_numeric;

/**
 * Builds an ExportMetricsServiceRequest protobuf binary from OtlpMetric DTOs.
 *
 * Wraps metrics in the ResourceMetrics → ScopeMetrics envelope required by OTLP.
 * Supports Counter (Sum, monotonic), Gauge, and Histogram data types.
 */
#[Internal(reason: 'Protobuf serializer for OTLP metrics export')]
final readonly class MetricsRequestBuilder
{
    public function __construct(
        private string $scopeName = 'pulsar',
        private string $scopeVersion = '1.0.0',
    ) {}

    /**
     * Serialize metrics into an ExportMetricsServiceRequest binary.
     *
     * @param list<OtlpMetric> $metrics  Metrics to export
     * @param ResourceInfo     $resource Resource metadata
     */
    public function build(array $metrics, ResourceInfo $resource): string
    {
        if ($metrics === []) {
            return '';
        }

        $root = new ProtobufWriter();

        // ExportMetricsServiceRequest.resource_metrics (field 1)
        $rmWriter = new ProtobufWriter();

        // ResourceMetrics.resource (field 1)
        $this->writeResource($rmWriter, $resource);

        // ResourceMetrics.scope_metrics (field 2)
        $smWriter = new ProtobufWriter();

        // ScopeMetrics.scope (field 1)
        $this->writeScope($smWriter);

        // ScopeMetrics.metrics (field 2, repeated)
        foreach ($metrics as $metric) {
            $metricWriter = $this->encodeMetric($metric);
            $smWriter->writeEmbeddedMessage(OtlpFieldNumbers::SM_METRICS, $metricWriter);
        }

        $rmWriter->writeEmbeddedMessage(OtlpFieldNumbers::RM_SCOPE_METRICS, $smWriter);
        $root->writeEmbeddedMessage(OtlpFieldNumbers::RESOURCE_METRICS, $rmWriter);

        return $root->toBytes();
    }

    private function encodeMetric(OtlpMetric $metric): ProtobufWriter
    {
        $w = new ProtobufWriter();

        // Metric.name (field 1)
        $w->writeStringField(OtlpFieldNumbers::METRIC_NAME, $metric->name);

        // Metric.description (field 2)
        if ($metric->description !== '') {
            $w->writeStringField(OtlpFieldNumbers::METRIC_DESCRIPTION, $metric->description);
        }

        // Metric.unit (field 3)
        if ($metric->unit !== '') {
            $w->writeStringField(OtlpFieldNumbers::METRIC_UNIT, $metric->unit);
        }

        match ($metric->type) {
            MetricType::Counter => $this->encodeSum($w, $metric),
            MetricType::Gauge => $this->encodeGauge($w, $metric),
            MetricType::Histogram => $this->encodeHistogram($w, $metric),
        };

        return $w;
    }

    /**
     * Encode counter as Sum (monotonic, cumulative).
     */
    private function encodeSum(ProtobufWriter $parent, OtlpMetric $metric): void
    {
        $sumWriter = new ProtobufWriter();

        // Sum.data_points (field 1, repeated)
        foreach ($metric->dataPoints as $dp) {
            $ndpWriter = $this->encodeNumberDataPoint($dp);
            $sumWriter->writeEmbeddedMessage(OtlpFieldNumbers::SUM_DATA_POINTS, $ndpWriter);
        }

        // Sum.aggregation_temporality (field 2) = CUMULATIVE (2)
        $sumWriter->writeVarintField(
            OtlpFieldNumbers::SUM_AGGREGATION_TEMPORALITY,
            OtlpFieldNumbers::AGGREGATION_TEMPORALITY_CUMULATIVE,
        );

        // Sum.is_monotonic (field 3) = true
        $sumWriter->writeBoolField(OtlpFieldNumbers::SUM_IS_MONOTONIC, true);

        $parent->writeEmbeddedMessage(OtlpFieldNumbers::METRIC_SUM, $sumWriter);
    }

    /**
     * Encode gauge data points.
     */
    private function encodeGauge(ProtobufWriter $parent, OtlpMetric $metric): void
    {
        $gaugeWriter = new ProtobufWriter();

        foreach ($metric->dataPoints as $dp) {
            $ndpWriter = $this->encodeNumberDataPoint($dp);
            $gaugeWriter->writeEmbeddedMessage(OtlpFieldNumbers::GAUGE_DATA_POINTS, $ndpWriter);
        }

        $parent->writeEmbeddedMessage(OtlpFieldNumbers::METRIC_GAUGE, $gaugeWriter);
    }

    /**
     * Encode histogram data points.
     */
    private function encodeHistogram(ProtobufWriter $parent, OtlpMetric $metric): void
    {
        $histWriter = new ProtobufWriter();

        foreach ($metric->dataPoints as $dp) {
            $hdpWriter = $this->encodeHistogramDataPoint($dp);
            $histWriter->writeEmbeddedMessage(OtlpFieldNumbers::HISTOGRAM_DATA_POINTS, $hdpWriter);
        }

        $parent->writeEmbeddedMessage(OtlpFieldNumbers::METRIC_HISTOGRAM, $histWriter);
    }

    /**
     * Encode a NumberDataPoint for Counter/Gauge.
     *
     * @param array<string, mixed> $dp Data point with keys: time_unix_nano, value, attributes
     */
    private function encodeNumberDataPoint(array $dp): ProtobufWriter
    {
        $w = new ProtobufWriter();

        // NumberDataPoint.time_unix_nano (field 3, fixed64)
        /** @var mixed $timeNano */
        $timeNano = $dp['time_unix_nano'] ?? null;

        if (is_int($timeNano)) {
            $w->writeFixed64Field(OtlpFieldNumbers::NDP_TIME_UNIX_NANO, $timeNano);
        }

        // NumberDataPoint.as_double (field 4) or as_int (field 6)
        if (isset($dp['value'])) {
            /** @var mixed $value */
            $value = $dp['value'];

            if (is_float($value)) {
                $w->writeDoubleField(OtlpFieldNumbers::NDP_AS_DOUBLE, $value);
            } elseif (is_int($value)) {
                $w->writeFixed64Field(OtlpFieldNumbers::NDP_AS_INT, $value);
            } elseif (is_numeric($value)) {
                $w->writeDoubleField(OtlpFieldNumbers::NDP_AS_DOUBLE, (float) $value);
            }
        }

        // NumberDataPoint.attributes (field 7, repeated KeyValue)
        if (isset($dp['attributes']) && is_array($dp['attributes'])) {
            /** @var array<string, scalar> $attrs */
            $attrs = $dp['attributes'];
            AttributeEncoder::encode($w, OtlpFieldNumbers::NDP_ATTRIBUTES, $attrs);
        }

        return $w;
    }

    /**
     * Encode a HistogramDataPoint.
     *
     * @param array<string, mixed> $dp Data point with keys: time_unix_nano, count, sum, bucket_counts, explicit_bounds, attributes
     */
    private function encodeHistogramDataPoint(array $dp): ProtobufWriter
    {
        $w = new ProtobufWriter();

        // HistogramDataPoint.time_unix_nano (field 3, fixed64)
        /** @var mixed $hdpTimeNano */
        $hdpTimeNano = $dp['time_unix_nano'] ?? null;

        if (is_int($hdpTimeNano)) {
            $w->writeFixed64Field(OtlpFieldNumbers::HDP_TIME_UNIX_NANO, $hdpTimeNano);
        }

        // HistogramDataPoint.count (field 4, fixed64)
        /** @var mixed $hdpCount */
        $hdpCount = $dp['count'] ?? null;

        if (is_int($hdpCount)) {
            $w->writeFixed64Field(OtlpFieldNumbers::HDP_COUNT, $hdpCount);
        }

        // HistogramDataPoint.sum (field 5, double/fixed64 wire type 1)
        /** @var mixed $hdpSum */
        $hdpSum = $dp['sum'] ?? null;

        if (is_float($hdpSum) || is_int($hdpSum)) {
            $w->writeDoubleField(OtlpFieldNumbers::HDP_SUM, (float) $hdpSum);
        }

        // HistogramDataPoint.bucket_counts (field 6, repeated fixed64)
        if (isset($dp['bucket_counts']) && is_array($dp['bucket_counts'])) {
            /** @var mixed $count */
            foreach ($dp['bucket_counts'] as $count) {
                if (is_int($count)) {
                    $w->writeFixed64Field(OtlpFieldNumbers::HDP_BUCKET_COUNTS, $count);
                }
            }
        }

        // HistogramDataPoint.explicit_bounds (field 7, repeated double)
        if (isset($dp['explicit_bounds']) && is_array($dp['explicit_bounds'])) {
            /** @var mixed $bound */
            foreach ($dp['explicit_bounds'] as $bound) {
                if (is_float($bound) || is_int($bound)) {
                    $w->writeDoubleField(OtlpFieldNumbers::HDP_EXPLICIT_BOUNDS, (float) $bound);
                }
            }
        }

        // HistogramDataPoint.attributes (field 9, repeated KeyValue)
        if (isset($dp['attributes']) && is_array($dp['attributes'])) {
            /** @var array<string, scalar> $attrs */
            $attrs = $dp['attributes'];
            AttributeEncoder::encode($w, OtlpFieldNumbers::HDP_ATTRIBUTES, $attrs);
        }

        return $w;
    }

    private function writeResource(ProtobufWriter $writer, ResourceInfo $resource): void
    {
        if ($resource->attributes === []) {
            return;
        }

        $resourceWriter = new ProtobufWriter();
        AttributeEncoder::encode($resourceWriter, OtlpFieldNumbers::RESOURCE_ATTRIBUTES, $resource->attributes);
        $writer->writeEmbeddedMessage(OtlpFieldNumbers::RM_RESOURCE, $resourceWriter);
    }

    private function writeScope(ProtobufWriter $writer): void
    {
        $scopeWriter = new ProtobufWriter();
        $scopeWriter->writeStringField(OtlpFieldNumbers::SCOPE_NAME, $this->scopeName);
        $scopeWriter->writeStringField(OtlpFieldNumbers::SCOPE_VERSION, $this->scopeVersion);
        $writer->writeEmbeddedMessage(OtlpFieldNumbers::SM_SCOPE, $scopeWriter);
    }
}
