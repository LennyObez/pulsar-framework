<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Internal\Protobuf;

use Pulsar\Api\Internal;
use Pulsar\Observability\Metrics\MetricType;

/**
 * Internal metric DTO for OTLP serialization.
 */
#[Internal(reason: 'Wire-format DTO for OTLP metric export')]
final readonly class OtlpMetric
{
    /**
     * @param string     $name        Metric instrument name
     * @param string     $description Human-readable description
     * @param string     $unit        Metric unit (e.g., "ms", "bytes", "1")
     * @param MetricType $type        Counter, Gauge, or Histogram
     * @param array<int, array<string, mixed>> $dataPoints  Array of data point arrays
     */
    public function __construct(
        public string $name,
        public string $description,
        public string $unit,
        public MetricType $type,
        public array $dataPoints,
    ) {}
}
