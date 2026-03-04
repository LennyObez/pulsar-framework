<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Bridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Bridge\OtlpMeterBridge;
use Pulsar\Extension\OpenTelemetry\Cardinality\CardinalityLimiter;
use Pulsar\Extension\OpenTelemetry\Internal\Export\MetricsBatchExporter;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\ResourceInfo;
use Pulsar\Extension\OpenTelemetry\Tests\Unit\Internal\Export\StubTransport;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;

#[CoversClass(OtlpMeterBridge::class)]
final class OtlpMeterBridgeTest extends TestCase
{
    private StubTransport $transport;
    private MetricsBatchExporter $exporter;

    protected function setUp(): void
    {
        $this->transport = new StubTransport();
        $this->exporter = new MetricsBatchExporter(
            transport: $this->transport,
            resource: new ResourceInfo(),
            maxBatchSize: 100,
            maxQueueSize: 200,
        );
    }

    #[Test]
    public function collectExportsCounterMetric(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('http_requests_total', 'Total HTTP requests');
        $counter->increment(new LabelSet());

        $bridge = new OtlpMeterBridge(
            registry: $registry,
            exporter: $this->exporter,
        );

        $bridge->collect();

        self::assertSame(1, $this->exporter->queueSize());
    }

    #[Test]
    public function collectExportsGaugeMetric(): void
    {
        $registry = new MetricRegistry();
        $gauge = $registry->gauge('memory_usage_bytes', 'Memory usage');
        $gauge->set(1024.0, new LabelSet());

        $bridge = new OtlpMeterBridge(
            registry: $registry,
            exporter: $this->exporter,
        );

        $bridge->collect();

        self::assertSame(1, $this->exporter->queueSize());
    }

    #[Test]
    public function collectExportsHistogramMetric(): void
    {
        $registry = new MetricRegistry();
        $histogram = $registry->histogram('request_duration_ms', 'Request duration', [10.0, 50.0, 100.0]);
        $histogram->observe(25.0, new LabelSet());

        $bridge = new OtlpMeterBridge(
            registry: $registry,
            exporter: $this->exporter,
        );

        $bridge->collect();

        self::assertSame(1, $this->exporter->queueSize());
    }

    #[Test]
    public function collectWithEmptyRegistryEnqueuesNothing(): void
    {
        $registry = new MetricRegistry();

        $bridge = new OtlpMeterBridge(
            registry: $registry,
            exporter: $this->exporter,
        );

        $bridge->collect();

        self::assertSame(0, $this->exporter->queueSize());
    }

    #[Test]
    public function collectExportsMultipleMetrics(): void
    {
        $registry = new MetricRegistry();
        $registry->counter('counter_a', 'A')->increment(new LabelSet());
        $registry->gauge('gauge_b', 'B')->set(5.0, new LabelSet());

        $bridge = new OtlpMeterBridge(
            registry: $registry,
            exporter: $this->exporter,
        );

        $bridge->collect();

        self::assertSame(2, $this->exporter->queueSize());
    }

    #[Test]
    public function collectWithCardinalityLimiterAppliesGuarding(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('guarded_metric', 'Guarded');
        $counter->increment(new LabelSet(['method' => 'GET']));

        $limiter = new CardinalityLimiter(maxMetricSeries: 100);

        $bridge = new OtlpMeterBridge(
            registry: $registry,
            exporter: $this->exporter,
            limiter: $limiter,
        );

        $bridge->collect();

        self::assertSame(1, $this->exporter->queueSize());
    }

    #[Test]
    public function collectWithoutCardinalityLimiterPassesLabelsThrough(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('unguarded_metric', 'Unguarded');
        $counter->increment(new LabelSet(['env' => 'test']));

        $bridge = new OtlpMeterBridge(
            registry: $registry,
            exporter: $this->exporter,
            limiter: null,
        );

        $bridge->collect();

        self::assertSame(1, $this->exporter->queueSize());
    }

    #[Test]
    public function collectParsesLabelKeyWithMultiplePairs(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('labeled_counter', 'With labels');
        $counter->increment(new LabelSet(['method' => 'POST', 'status' => '200']));

        $bridge = new OtlpMeterBridge(
            registry: $registry,
            exporter: $this->exporter,
        );

        $bridge->collect();

        self::assertSame(1, $this->exporter->queueSize());
    }

    #[Test]
    public function collectParsesEmptyLabelKey(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('no_labels', 'No labels');
        $counter->increment(new LabelSet());

        $bridge = new OtlpMeterBridge(
            registry: $registry,
            exporter: $this->exporter,
        );

        $bridge->collect();

        self::assertSame(1, $this->exporter->queueSize());
    }

    #[Test]
    public function collectFlushesExportedMetrics(): void
    {
        $registry = new MetricRegistry();
        $registry->counter('flushed', 'Test flush')->increment(new LabelSet());

        $bridge = new OtlpMeterBridge(
            registry: $registry,
            exporter: $this->exporter,
        );

        $bridge->collect();
        $this->exporter->flush();

        self::assertSame(0, $this->exporter->queueSize());
        self::assertCount(1, $this->transport->sentPayloads);
    }
}
