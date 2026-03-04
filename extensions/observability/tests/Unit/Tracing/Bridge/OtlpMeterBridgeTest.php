<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Tracing\Bridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Export\Otlp\MetricsBatchExporter;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\ResourceInfo;
use Pulsar\Extension\Observability\Export\Otlp\Transport\OtlpTransportInterface;
use Pulsar\Extension\Observability\Export\Otlp\Transport\TransportResult;
use Pulsar\Extension\Observability\Tracing\Bridge\OtlpMeterBridge;
use Pulsar\Extension\Observability\Tracing\Cardinality\CardinalityLimiter;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;

#[CoversClass(OtlpMeterBridge::class)]
final class OtlpMeterBridgeTest extends TestCase
{
    private MetricsBatchExporter $exporter;

    protected function setUp(): void
    {
        $transport = $this->createStub(OtlpTransportInterface::class);
        $transport->method('send')->willReturn(TransportResult::success(200));

        $this->exporter = new MetricsBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(),
            maxBatchSize: 10000,
        );
    }

    #[Test]
    public function collectsCounterMetrics(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('http_requests_total', 'Total HTTP requests');
        $counter->increment(new LabelSet(['method' => 'GET']), 5.0);

        $bridge = new OtlpMeterBridge($registry, $this->exporter);
        $bridge->collect();

        self::assertSame(1, $this->exporter->queueSize());
    }

    #[Test]
    public function collectsGaugeMetrics(): void
    {
        $registry = new MetricRegistry();
        $gauge = $registry->gauge('memory_usage_bytes', 'Memory usage');
        $gauge->set(1024.0);

        $bridge = new OtlpMeterBridge($registry, $this->exporter);
        $bridge->collect();

        self::assertSame(1, $this->exporter->queueSize());
    }

    #[Test]
    public function collectsHistogramMetrics(): void
    {
        $registry = new MetricRegistry();
        $histogram = $registry->histogram('request_duration', 'Request duration');
        $histogram->observe(0.5);

        $bridge = new OtlpMeterBridge($registry, $this->exporter);
        $bridge->collect();

        self::assertSame(1, $this->exporter->queueSize());
    }

    #[Test]
    public function collectsMultipleMetrics(): void
    {
        $registry = new MetricRegistry();
        $registry->counter('counter1')->increment();
        $registry->gauge('gauge1')->set(1.0);
        $registry->histogram('hist1')->observe(0.5);

        $bridge = new OtlpMeterBridge($registry, $this->exporter);
        $bridge->collect();

        self::assertSame(3, $this->exporter->queueSize());
    }

    #[Test]
    public function emptyRegistryDoesNotEnqueue(): void
    {
        $registry = new MetricRegistry();

        $bridge = new OtlpMeterBridge($registry, $this->exporter);
        $bridge->collect();

        self::assertSame(0, $this->exporter->queueSize());
    }

    #[Test]
    public function appliesCardinalityLimiter(): void
    {
        $registry = new MetricRegistry();
        $counter = $registry->counter('test_metric');
        $counter->increment(new LabelSet(['a' => '1']));
        $counter->increment(new LabelSet(['a' => '2']));
        $counter->increment(new LabelSet(['a' => '3']));

        $limiter = new CardinalityLimiter(maxMetricSeries: 2);

        $bridge = new OtlpMeterBridge($registry, $this->exporter, $limiter);
        $bridge->collect();

        // One metric enqueued (with 3 data points internally; 2 normal + 1 overflow)
        self::assertSame(1, $this->exporter->queueSize());
    }
}
