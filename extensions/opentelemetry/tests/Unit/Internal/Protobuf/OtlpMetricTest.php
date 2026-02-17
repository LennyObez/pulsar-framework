<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Internal\Protobuf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpMetric;
use Pulsar\Observability\Metrics\MetricType;

#[CoversClass(OtlpMetric::class)]
final class OtlpMetricTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $dataPoints = [
            ['attributes' => ['env' => 'prod'], 'value' => 42.0, 'time_unix_nano' => 1000],
        ];

        $metric = new OtlpMetric(
            name: 'http_requests_total',
            description: 'Total HTTP requests',
            unit: '1',
            type: MetricType::Counter,
            dataPoints: $dataPoints,
        );

        self::assertSame('http_requests_total', $metric->name);
        self::assertSame('Total HTTP requests', $metric->description);
        self::assertSame('1', $metric->unit);
        self::assertSame(MetricType::Counter, $metric->type);
        self::assertCount(1, $metric->dataPoints);
    }

    #[Test]
    public function gaugeType(): void
    {
        $metric = new OtlpMetric(
            name: 'memory_usage',
            description: 'Memory usage bytes',
            unit: 'bytes',
            type: MetricType::Gauge,
            dataPoints: [],
        );

        self::assertSame(MetricType::Gauge, $metric->type);
    }

    #[Test]
    public function histogramType(): void
    {
        $metric = new OtlpMetric(
            name: 'request_duration',
            description: 'Request duration',
            unit: 'ms',
            type: MetricType::Histogram,
            dataPoints: [],
        );

        self::assertSame(MetricType::Histogram, $metric->type);
    }
}
