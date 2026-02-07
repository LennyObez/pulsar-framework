<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Metrics\Counter;
use Pulsar\Observability\Metrics\Gauge;
use Pulsar\Observability\Metrics\Histogram;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricSnapshot;
use Pulsar\Observability\Metrics\MetricType;
use ReflectionClass;

#[CoversClass(MetricSnapshot::class)]
final class MetricSnapshotTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $values = ['method=GET' => 5.0];
        $snapshot = new MetricSnapshot(
            name: 'http_requests_total',
            type: MetricType::Counter,
            help: 'Total HTTP requests',
            values: $values,
        );

        self::assertSame('http_requests_total', $snapshot->name);
        self::assertSame(MetricType::Counter, $snapshot->type);
        self::assertSame('Total HTTP requests', $snapshot->help);
        self::assertSame($values, $snapshot->values);
        self::assertNull($snapshot->series);
    }

    #[Test]
    public function constructorDefaultsToEmptyValuesAndNullSeries(): void
    {
        $snapshot = new MetricSnapshot(
            name: 'test_metric',
            type: MetricType::Gauge,
            help: 'A test metric',
        );

        self::assertSame([], $snapshot->values);
        self::assertNull($snapshot->series);
    }

    #[Test]
    public function constructorAcceptsSeriesData(): void
    {
        $series = [
            '' => [
                'buckets' => ['0.005' => 0, '0.01' => 1],
                'sum' => 0.008,
                'count' => 1,
            ],
        ];

        $snapshot = new MetricSnapshot(
            name: 'request_duration',
            type: MetricType::Histogram,
            help: 'Request duration',
            series: $series,
        );

        self::assertSame($series, $snapshot->series);
    }

    #[Test]
    public function fromCounterCreatesSnapshotWithCounterType(): void
    {
        $counter = new Counter('requests_total', 'Total requests');
        $counter->increment();
        $counter->increment(value: 4.0);

        $snapshot = MetricSnapshot::fromCounter($counter);

        self::assertSame('requests_total', $snapshot->name);
        self::assertSame(MetricType::Counter, $snapshot->type);
        self::assertSame('Total requests', $snapshot->help);
        self::assertSame($counter->values(), $snapshot->values);
        self::assertNull($snapshot->series);
    }

    #[Test]
    public function fromCounterCapturesLabeledValues(): void
    {
        $counter = new Counter('http_requests', 'HTTP request counter');
        $get = new LabelSet(['method' => 'GET']);
        $post = new LabelSet(['method' => 'POST']);

        $counter->increment($get);
        $counter->increment($get);
        $counter->increment($post);

        $snapshot = MetricSnapshot::fromCounter($counter);

        self::assertSame(2.0, $snapshot->values['method=GET']);
        self::assertSame(1.0, $snapshot->values['method=POST']);
    }

    #[Test]
    public function fromCounterHandlesEmptyCounter(): void
    {
        $counter = new Counter('empty_counter', 'No increments');

        $snapshot = MetricSnapshot::fromCounter($counter);

        self::assertSame([], $snapshot->values);
    }

    #[Test]
    public function fromGaugeCreatesSnapshotWithGaugeType(): void
    {
        $gauge = new Gauge('active_connections', 'Active connections');
        $gauge->set(42.0);

        $snapshot = MetricSnapshot::fromGauge($gauge);

        self::assertSame('active_connections', $snapshot->name);
        self::assertSame(MetricType::Gauge, $snapshot->type);
        self::assertSame('Active connections', $snapshot->help);
        self::assertSame($gauge->values(), $snapshot->values);
        self::assertNull($snapshot->series);
    }

    #[Test]
    public function fromGaugeCapturesCurrentValue(): void
    {
        $gauge = new Gauge('temperature', 'Current temp');
        $gauge->set(23.5);
        $gauge->increment();
        $gauge->decrement(value: 0.5);

        $snapshot = MetricSnapshot::fromGauge($gauge);

        self::assertSame(24.0, $snapshot->values['']);
    }

    #[Test]
    public function fromGaugeHandlesEmptyGauge(): void
    {
        $gauge = new Gauge('empty_gauge', 'No values set');

        $snapshot = MetricSnapshot::fromGauge($gauge);

        self::assertSame([], $snapshot->values);
    }

    #[Test]
    public function fromHistogramCreatesSnapshotWithHistogramType(): void
    {
        $histogram = new Histogram('request_duration', 'Request duration in seconds', [0.1, 0.5, 1.0]);
        $histogram->observe(0.25);

        $snapshot = MetricSnapshot::fromHistogram($histogram);

        self::assertSame('request_duration', $snapshot->name);
        self::assertSame(MetricType::Histogram, $snapshot->type);
        self::assertSame('Request duration in seconds', $snapshot->help);
        self::assertSame([], $snapshot->values);
        self::assertIsArray($snapshot->series);
    }

    #[Test]
    public function fromHistogramCapturesBucketsSumAndCount(): void
    {
        $histogram = new Histogram('latency', 'Latency', [0.1, 0.5, 1.0]);
        $histogram->observe(0.05);
        $histogram->observe(0.3);

        $snapshot = MetricSnapshot::fromHistogram($histogram);

        self::assertIsArray($snapshot->series);
        self::assertArrayHasKey('', $snapshot->series);

        $seriesData = $snapshot->series[''];
        self::assertArrayHasKey('buckets', $seriesData);
        self::assertArrayHasKey('sum', $seriesData);
        self::assertArrayHasKey('count', $seriesData);
        self::assertSame(2, $seriesData['count']);
        self::assertEqualsWithDelta(0.35, $seriesData['sum'], 0.0001);
    }

    #[Test]
    public function fromHistogramHandlesEmptyHistogram(): void
    {
        $histogram = new Histogram('empty_histogram', 'No observations', [0.1, 0.5]);

        $snapshot = MetricSnapshot::fromHistogram($histogram);

        self::assertIsArray($snapshot->series);
        self::assertSame([], $snapshot->series);
    }

    #[Test]
    public function snapshotIsReadonly(): void
    {
        $snapshot = new MetricSnapshot(
            name: 'test',
            type: MetricType::Counter,
            help: 'test help',
        );

        $reflection = new ReflectionClass($snapshot);
        self::assertTrue($reflection->isReadOnly());
    }
}
