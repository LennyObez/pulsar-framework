<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OpenTelemetry\Internal\Protobuf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\AttributeEncoder;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\MetricsRequestBuilder;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpFieldNumbers;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpMetric;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\ProtobufWriter;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\ResourceInfo;
use Pulsar\Observability\Metrics\MetricType;

use function strlen;

#[CoversClass(MetricsRequestBuilder::class)]
#[CoversClass(AttributeEncoder::class)]
#[CoversClass(OtlpFieldNumbers::class)]
#[CoversClass(ProtobufWriter::class)]
#[CoversClass(OtlpMetric::class)]
#[CoversClass(ResourceInfo::class)]
final class MetricsRequestBuilderTest extends TestCase
{
    #[Test]
    public function buildReturnsEmptyStringForNoMetrics(): void
    {
        $builder = new MetricsRequestBuilder();

        self::assertSame('', $builder->build([], new ResourceInfo()));
    }

    #[Test]
    public function buildProducesOutputForCounter(): void
    {
        $builder = new MetricsRequestBuilder();

        $metric = new OtlpMetric(
            name: 'http_requests_total',
            description: 'Total HTTP requests',
            unit: '1',
            type: MetricType::Counter,
            dataPoints: [
                [
                    'time_unix_nano' => 1_700_000_000_000_000_000,
                    'value' => 42.0,
                    'attributes' => ['method' => 'GET'],
                ],
            ],
        );

        $binary = $builder->build([$metric], new ResourceInfo());

        self::assertNotSame('', $binary);
        self::assertStringContainsString('http_requests_total', $binary);
        self::assertStringContainsString('Total HTTP requests', $binary);
        self::assertStringContainsString('method', $binary);
    }

    #[Test]
    public function buildProducesOutputForGauge(): void
    {
        $builder = new MetricsRequestBuilder();

        $metric = new OtlpMetric(
            name: 'memory_usage_bytes',
            description: 'Current memory usage',
            unit: 'bytes',
            type: MetricType::Gauge,
            dataPoints: [
                [
                    'time_unix_nano' => 1_700_000_000_000_000_000,
                    'value' => 1048576.0,
                    'attributes' => [],
                ],
            ],
        );

        $binary = $builder->build([$metric], new ResourceInfo());

        self::assertNotSame('', $binary);
        self::assertStringContainsString('memory_usage_bytes', $binary);
        self::assertStringContainsString('Current memory usage', $binary);
    }

    #[Test]
    public function buildProducesOutputForHistogram(): void
    {
        $builder = new MetricsRequestBuilder();

        $metric = new OtlpMetric(
            name: 'http_request_duration',
            description: 'Request duration histogram',
            unit: 'ms',
            type: MetricType::Histogram,
            dataPoints: [
                [
                    'time_unix_nano' => 1_700_000_000_000_000_000,
                    'count' => 100,
                    'sum' => 5432.1,
                    'bucket_counts' => [10, 30, 40, 15, 5],
                    'explicit_bounds' => [10.0, 25.0, 50.0, 100.0],
                    'attributes' => ['route' => '/api/users'],
                ],
            ],
        );

        $binary = $builder->build([$metric], new ResourceInfo());

        self::assertNotSame('', $binary);
        self::assertStringContainsString('http_request_duration', $binary);
        self::assertStringContainsString('route', $binary);
    }

    #[Test]
    public function buildIncludesResourceAttributes(): void
    {
        $builder = new MetricsRequestBuilder();

        $metric = new OtlpMetric(
            name: 'test_metric',
            description: '',
            unit: '',
            type: MetricType::Counter,
            dataPoints: [
                ['time_unix_nano' => 1_700_000_000_000_000_000, 'value' => 1.0, 'attributes' => []],
            ],
        );

        $resource = new ResourceInfo(['service.name' => 'test-service']);
        $binary = $builder->build([$metric], $resource);

        self::assertStringContainsString('service.name', $binary);
        self::assertStringContainsString('test-service', $binary);
    }

    #[Test]
    public function buildIncludesScopeInformation(): void
    {
        $builder = new MetricsRequestBuilder(scopeName: 'my-metrics', scopeVersion: '3.0.0');

        $metric = new OtlpMetric(
            name: 'test_metric',
            description: '',
            unit: '',
            type: MetricType::Gauge,
            dataPoints: [
                ['time_unix_nano' => 1_700_000_000_000_000_000, 'value' => 1.0, 'attributes' => []],
            ],
        );

        $binary = $builder->build([$metric], new ResourceInfo());

        self::assertStringContainsString('my-metrics', $binary);
        self::assertStringContainsString('3.0.0', $binary);
    }

    #[Test]
    public function buildHandlesMultipleMetrics(): void
    {
        $builder = new MetricsRequestBuilder();

        $counter = new OtlpMetric(
            name: 'counter_one',
            description: 'First counter',
            unit: '1',
            type: MetricType::Counter,
            dataPoints: [
                ['time_unix_nano' => 1_700_000_000_000_000_000, 'value' => 10.0, 'attributes' => []],
            ],
        );

        $gauge = new OtlpMetric(
            name: 'gauge_one',
            description: 'First gauge',
            unit: 'bytes',
            type: MetricType::Gauge,
            dataPoints: [
                ['time_unix_nano' => 1_700_000_000_000_000_000, 'value' => 999.0, 'attributes' => []],
            ],
        );

        $binary = $builder->build([$counter, $gauge], new ResourceInfo());

        self::assertStringContainsString('counter_one', $binary);
        self::assertStringContainsString('gauge_one', $binary);
    }

    #[Test]
    public function buildHandlesIntegerDataPointValues(): void
    {
        $builder = new MetricsRequestBuilder();

        $metric = new OtlpMetric(
            name: 'integer_counter',
            description: '',
            unit: '1',
            type: MetricType::Counter,
            dataPoints: [
                ['time_unix_nano' => 1_700_000_000_000_000_000, 'value' => 42, 'attributes' => []],
            ],
        );

        $binary = $builder->build([$metric], new ResourceInfo());

        self::assertNotSame('', $binary);
        self::assertGreaterThan(0, strlen($binary));
    }

    #[Test]
    public function buildHandlesMultipleDataPoints(): void
    {
        $builder = new MetricsRequestBuilder();

        $metric = new OtlpMetric(
            name: 'multi_dp',
            description: '',
            unit: '1',
            type: MetricType::Counter,
            dataPoints: [
                ['time_unix_nano' => 1_700_000_000_000_000_000, 'value' => 10.0, 'attributes' => ['region' => 'us']],
                ['time_unix_nano' => 1_700_000_000_000_000_000, 'value' => 20.0, 'attributes' => ['region' => 'eu']],
            ],
        );

        $binary = $builder->build([$metric], new ResourceInfo());

        self::assertStringContainsString('region', $binary);
        // Both region values should appear
        self::assertStringContainsString('us', $binary);
        self::assertStringContainsString('eu', $binary);
    }
}
