<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Export\Otlp\Protobuf;

use Pulsar\Api\Internal;
use Pulsar\Observability\Metrics\MetricType;

use function is_array;
use function is_float;
use function is_int;
use function is_numeric;

/**
 * Builds an ExportMetricsServiceRequest protobuf binary from OtlpMetric DTOs.
 *
 * Wraps metrics in the ResourceMetrics -> ScopeMetrics envelope required by OTLP.
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
        $rmWriter = new ProtobufWriter();
        $this->writeResource($rmWriter, $resource);

        $smWriter = new ProtobufWriter();
        $this->writeScope($smWriter);

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
        $w->writeStringField(OtlpFieldNumbers::METRIC_NAME, $metric->name);

        if ($metric->description !== '') {
            $w->writeStringField(OtlpFieldNumbers::METRIC_DESCRIPTION, $metric->description);
        }

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

    private function encodeSum(ProtobufWriter $parent, OtlpMetric $metric): void
    {
        $sumWriter = new ProtobufWriter();

        foreach ($metric->dataPoints as $dp) {
            $ndpWriter = $this->encodeNumberDataPoint($dp);
            $sumWriter->writeEmbeddedMessage(OtlpFieldNumbers::SUM_DATA_POINTS, $ndpWriter);
        }

        $sumWriter->writeVarintField(
            OtlpFieldNumbers::SUM_AGGREGATION_TEMPORALITY,
            OtlpFieldNumbers::AGGREGATION_TEMPORALITY_CUMULATIVE,
        );
        $sumWriter->writeBoolField(OtlpFieldNumbers::SUM_IS_MONOTONIC, true);

        $parent->writeEmbeddedMessage(OtlpFieldNumbers::METRIC_SUM, $sumWriter);
    }

    private function encodeGauge(ProtobufWriter $parent, OtlpMetric $metric): void
    {
        $gaugeWriter = new ProtobufWriter();

        foreach ($metric->dataPoints as $dp) {
            $ndpWriter = $this->encodeNumberDataPoint($dp);
            $gaugeWriter->writeEmbeddedMessage(OtlpFieldNumbers::GAUGE_DATA_POINTS, $ndpWriter);
        }

        $parent->writeEmbeddedMessage(OtlpFieldNumbers::METRIC_GAUGE, $gaugeWriter);
    }

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
     * @param array<string, mixed> $dp
     */
    private function encodeNumberDataPoint(array $dp): ProtobufWriter
    {
        $w = new ProtobufWriter();
        $timeNano = $dp['time_unix_nano'] ?? null;

        if (is_int($timeNano)) {
            $w->writeFixed64Field(OtlpFieldNumbers::NDP_TIME_UNIX_NANO, $timeNano);
        }

        if (isset($dp['value'])) {
            $value = $dp['value'];

            if (is_float($value)) {
                $w->writeDoubleField(OtlpFieldNumbers::NDP_AS_DOUBLE, $value);
            } elseif (is_int($value)) {
                $w->writeFixed64Field(OtlpFieldNumbers::NDP_AS_INT, $value);
            } elseif (is_numeric($value)) {
                $w->writeDoubleField(OtlpFieldNumbers::NDP_AS_DOUBLE, (float) $value);
            }
        }

        if (isset($dp['attributes']) && is_array($dp['attributes'])) {
            /** @var array<string, scalar> $attrs */
            $attrs = $dp['attributes'];
            AttributeEncoder::encode($w, OtlpFieldNumbers::NDP_ATTRIBUTES, $attrs);
        }

        return $w;
    }

    /**
     * @param array<string, mixed> $dp
     */
    private function encodeHistogramDataPoint(array $dp): ProtobufWriter
    {
        $w = new ProtobufWriter();
        $hdpTimeNano = $dp['time_unix_nano'] ?? null;

        if (is_int($hdpTimeNano)) {
            $w->writeFixed64Field(OtlpFieldNumbers::HDP_TIME_UNIX_NANO, $hdpTimeNano);
        }

        $hdpCount = $dp['count'] ?? null;

        if (is_int($hdpCount)) {
            $w->writeFixed64Field(OtlpFieldNumbers::HDP_COUNT, $hdpCount);
        }

        $hdpSum = $dp['sum'] ?? null;

        if (is_float($hdpSum) || is_int($hdpSum)) {
            $w->writeDoubleField(OtlpFieldNumbers::HDP_SUM, (float) $hdpSum);
        }

        if (isset($dp['bucket_counts']) && is_array($dp['bucket_counts'])) {
            foreach ($dp['bucket_counts'] as $count) {
                if (is_int($count)) {
                    $w->writeFixed64Field(OtlpFieldNumbers::HDP_BUCKET_COUNTS, $count);
                }
            }
        }

        if (isset($dp['explicit_bounds']) && is_array($dp['explicit_bounds'])) {
            foreach ($dp['explicit_bounds'] as $bound) {
                if (is_float($bound) || is_int($bound)) {
                    $w->writeDoubleField(OtlpFieldNumbers::HDP_EXPLICIT_BOUNDS, (float) $bound);
                }
            }
        }

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
