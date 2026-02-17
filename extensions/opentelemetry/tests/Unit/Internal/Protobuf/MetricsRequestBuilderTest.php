<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Internal\Protobuf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\MetricsRequestBuilder;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpMetric;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\ResourceInfo;
use Pulsar\Observability\Metrics\MetricType;

use function strlen;

#[CoversClass(MetricsRequestBuilder::class)]
final class MetricsRequestBuilderTest extends TestCase
{
    #[Test]
    public function buildReturnsEmptyStringForEmptyMetrics(): void
    {
        $builder = new MetricsRequestBuilder();
        $result = $builder->build([], new ResourceInfo());

        self::assertSame('', $result);
    }

    #[Test]
    public function buildEncodesCounterAsMonotonicSum(): void
    {
        $builder = new MetricsRequestBuilder();
        $metric = new OtlpMetric(
            name: 'http_requests_total',
            description: 'Total HTTP requests',
            unit: '',
            type: MetricType::Counter,
            dataPoints: [
                ['value' => 42.0, 'time_unix_nano' => 1000000000, 'attributes' => []],
            ],
        );

        $result = $builder->build([$metric], new ResourceInfo(['service.name' => 'test']));

        self::assertNotSame('', $result);
        self::assertStringContainsString('http_requests_total', $result);
    }

    #[Test]
    public function buildEncodesGauge(): void
    {
        $builder = new MetricsRequestBuilder();
        $metric = new OtlpMetric(
            name: 'memory_usage_bytes',
            description: 'Current memory usage',
            unit: 'bytes',
            type: MetricType::Gauge,
            dataPoints: [
                ['value' => 1048576.0, 'time_unix_nano' => 1000000000, 'attributes' => []],
            ],
        );

        $result = $builder->build([$metric], new ResourceInfo());

        self::assertNotSame('', $result);
        self::assertStringContainsString('memory_usage_bytes', $result);
    }

    #[Test]
    public function buildEncodesHistogram(): void
    {
        $builder = new MetricsRequestBuilder();
        $metric = new OtlpMetric(
            name: 'request_duration_ms',
            description: 'Request latency',
            unit: 'ms',
            type: MetricType::Histogram,
            dataPoints: [
                [
                    'time_unix_nano' => 1000000000,
                    'count' => 10,
                    'sum' => 150.0,
                    'bucket_counts' => [2, 5, 2, 1],
                    'explicit_bounds' => [10.0, 50.0, 100.0],
                    'attributes' => [],
                ],
            ],
        );

        $result = $builder->build([$metric], new ResourceInfo());

        self::assertNotSame('', $result);
        self::assertStringContainsString('request_duration_ms', $result);
    }

    #[Test]
    public function buildWithMultipleMetricsProducesLargerPayload(): void
    {
        $builder = new MetricsRequestBuilder();
        $metric = new OtlpMetric(
            name: 'counter',
            description: '',
            unit: '',
            type: MetricType::Counter,
            dataPoints: [['value' => 1.0, 'time_unix_nano' => 1000000000, 'attributes' => []]],
        );

        $single = $builder->build([$metric], new ResourceInfo());
        $double = $builder->build([$metric, $metric], new ResourceInfo());

        self::assertGreaterThan(strlen($single), strlen($double));
    }

    #[Test]
    public function buildIncludesResourceAttributes(): void
    {
        $builder = new MetricsRequestBuilder();
        $metric = new OtlpMetric(
            name: 'test',
            description: '',
            unit: '',
            type: MetricType::Counter,
            dataPoints: [['value' => 1.0, 'time_unix_nano' => 1000000000, 'attributes' => []]],
        );

        $withResource = $builder->build([$metric], new ResourceInfo(['service.name' => 'my-svc']));
        $withoutResource = $builder->build([$metric], new ResourceInfo());

        self::assertGreaterThan(strlen($withoutResource), strlen($withResource));
    }

    #[Test]
    public function buildSkipsDescriptionWhenEmpty(): void
    {
        $builder = new MetricsRequestBuilder();
        $withDesc = new OtlpMetric(
            name: 'metric',
            description: 'A useful description',
            unit: '',
            type: MetricType::Counter,
            dataPoints: [['value' => 1.0, 'time_unix_nano' => 1000000000, 'attributes' => []]],
        );
        $withoutDesc = new OtlpMetric(
            name: 'metric',
            description: '',
            unit: '',
            type: MetricType::Counter,
            dataPoints: [['value' => 1.0, 'time_unix_nano' => 1000000000, 'attributes' => []]],
        );

        $resultWithDesc = $builder->build([$withDesc], new ResourceInfo());
        $resultWithoutDesc = $builder->build([$withoutDesc], new ResourceInfo());

        self::assertGreaterThan(strlen($resultWithoutDesc), strlen($resultWithDesc));
    }

    #[Test]
    public function buildSkipsUnitWhenEmpty(): void
    {
        $builder = new MetricsRequestBuilder();
        $withUnit = new OtlpMetric(
            name: 'metric',
            description: '',
            unit: 'ms',
            type: MetricType::Counter,
            dataPoints: [['value' => 1.0, 'time_unix_nano' => 1000000000, 'attributes' => []]],
        );
        $withoutUnit = new OtlpMetric(
            name: 'metric',
            description: '',
            unit: '',
            type: MetricType::Counter,
            dataPoints: [['value' => 1.0, 'time_unix_nano' => 1000000000, 'attributes' => []]],
        );

        $resultWithUnit = $builder->build([$withUnit], new ResourceInfo());
        $resultWithoutUnit = $builder->build([$withoutUnit], new ResourceInfo());

        self::assertGreaterThan(strlen($resultWithoutUnit), strlen($resultWithUnit));
    }

    #[Test]
    public function buildEncodesIntegerValueAsFixedInt(): void
    {
        $builder = new MetricsRequestBuilder();
        $metric = new OtlpMetric(
            name: 'int_counter',
            description: '',
            unit: '',
            type: MetricType::Counter,
            dataPoints: [['value' => 42, 'time_unix_nano' => 1000000000, 'attributes' => []]],
        );

        $result = $builder->build([$metric], new ResourceInfo());

        self::assertNotSame('', $result);
    }

    #[Test]
    public function buildEncodesNumericStringValueAsDouble(): void
    {
        $builder = new MetricsRequestBuilder();
        $metric = new OtlpMetric(
            name: 'string_counter',
            description: '',
            unit: '',
            type: MetricType::Counter,
            dataPoints: [['value' => '3.14', 'time_unix_nano' => 1000000000, 'attributes' => []]],
        );

        $result = $builder->build([$metric], new ResourceInfo());

        self::assertNotSame('', $result);
    }

    #[Test]
    public function buildEncodesDataPointAttributes(): void
    {
        $builder = new MetricsRequestBuilder();
        $withAttrs = new OtlpMetric(
            name: 'metric',
            description: '',
            unit: '',
            type: MetricType::Counter,
            dataPoints: [['value' => 1.0, 'time_unix_nano' => 1000000000, 'attributes' => ['env' => 'prod']]],
        );
        $withoutAttrs = new OtlpMetric(
            name: 'metric',
            description: '',
            unit: '',
            type: MetricType::Counter,
            dataPoints: [['value' => 1.0, 'time_unix_nano' => 1000000000, 'attributes' => []]],
        );

        $resultWith = $builder->build([$withAttrs], new ResourceInfo());
        $resultWithout = $builder->build([$withoutAttrs], new ResourceInfo());

        self::assertGreaterThan(strlen($resultWithout), strlen($resultWith));
    }

    #[Test]
    public function buildUsesCustomScopeNameAndVersion(): void
    {
        $builderDefault = new MetricsRequestBuilder();
        $builderCustom = new MetricsRequestBuilder(scopeName: 'custom', scopeVersion: '2.0.0');

        $metric = new OtlpMetric(
            name: 'test',
            description: '',
            unit: '',
            type: MetricType::Counter,
            dataPoints: [['value' => 1.0, 'time_unix_nano' => 1000000000, 'attributes' => []]],
        );

        self::assertNotSame(
            $builderDefault->build([$metric], new ResourceInfo()),
            $builderCustom->build([$metric], new ResourceInfo()),
        );
    }

    #[Test]
    public function buildHandlesMissingDataPointFields(): void
    {
        $builder = new MetricsRequestBuilder();
        $metric = new OtlpMetric(
            name: 'sparse',
            description: '',
            unit: '',
            type: MetricType::Gauge,
            dataPoints: [
                ['attributes' => []],
            ],
        );

        $result = $builder->build([$metric], new ResourceInfo());

        // Should not throw even with missing value/time fields
        self::assertNotSame('', $result);
    }

    #[Test]
    public function buildHandlesHistogramWithIntegerSum(): void
    {
        $builder = new MetricsRequestBuilder();
        $metric = new OtlpMetric(
            name: 'hist',
            description: '',
            unit: '',
            type: MetricType::Histogram,
            dataPoints: [
                [
                    'time_unix_nano' => 1000000000,
                    'count' => 5,
                    'sum' => 100,
                    'bucket_counts' => [2, 3],
                    'explicit_bounds' => [50.0],
                    'attributes' => [],
                ],
            ],
        );

        $result = $builder->build([$metric], new ResourceInfo());

        self::assertNotSame('', $result);
    }
}
