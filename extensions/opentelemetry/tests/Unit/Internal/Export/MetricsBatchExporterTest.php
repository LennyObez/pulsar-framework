<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Internal\Export;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Internal\Export\MetricsBatchExporter;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpMetric;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\ResourceInfo;
use Pulsar\Observability\Metrics\MetricType;

#[CoversClass(MetricsBatchExporter::class)]
final class MetricsBatchExporterTest extends TestCase
{
    #[Test]
    public function enqueueAddsMetricToQueue(): void
    {
        $transport = new StubTransport();
        $exporter = new MetricsBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(),
            maxBatchSize: 100,
            maxQueueSize: 200,
        );

        $exporter->enqueue($this->createMetric());

        self::assertSame(1, $exporter->queueSize());
    }

    #[Test]
    public function flushSendsEnqueuedMetricsViaTransport(): void
    {
        $transport = new StubTransport();
        $exporter = new MetricsBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(),
            maxBatchSize: 100,
            maxQueueSize: 200,
        );

        $exporter->enqueue($this->createMetric());
        $exporter->enqueue($this->createMetric('gauge_b', MetricType::Gauge));
        $exporter->flush();

        self::assertSame(0, $exporter->queueSize());
        self::assertCount(1, $transport->sentPayloads);
    }

    #[Test]
    public function flushWithEmptyQueueDoesNotSend(): void
    {
        $transport = new StubTransport();
        $exporter = new MetricsBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(),
            maxBatchSize: 100,
            maxQueueSize: 200,
        );

        $exporter->flush();

        self::assertCount(0, $transport->sentPayloads);
    }

    #[Test]
    public function shutdownFlushesAndPreventsSubsequentEnqueue(): void
    {
        $transport = new StubTransport();
        $exporter = new MetricsBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(),
            maxBatchSize: 100,
            maxQueueSize: 200,
        );

        $exporter->enqueue($this->createMetric());
        $exporter->shutdown();

        self::assertSame(0, $exporter->queueSize());

        $exporter->enqueue($this->createMetric());
        self::assertSame(0, $exporter->queueSize());
    }

    #[Test]
    public function queueSizeReflectsEnqueuedItems(): void
    {
        $transport = new StubTransport();
        $exporter = new MetricsBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(),
            maxBatchSize: 100,
            maxQueueSize: 200,
        );

        self::assertSame(0, $exporter->queueSize());

        $exporter->enqueue($this->createMetric());
        self::assertSame(1, $exporter->queueSize());
    }

    #[Test]
    public function autoFlushTriggersAtBatchSizeThreshold(): void
    {
        $transport = new StubTransport();
        $exporter = new MetricsBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(),
            maxBatchSize: 2,
            maxQueueSize: 100,
        );

        $exporter->enqueue($this->createMetric());
        self::assertCount(0, $transport->sentPayloads);

        $exporter->enqueue($this->createMetric());
        self::assertCount(1, $transport->sentPayloads);
        self::assertSame(0, $exporter->queueSize());
    }

    #[Test]
    public function usesMetricsSignalPath(): void
    {
        $transport = new StubTransport();
        $exporter = new MetricsBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(),
            maxBatchSize: 100,
            maxQueueSize: 200,
        );

        $exporter->enqueue($this->createMetric());
        $exporter->flush();

        self::assertSame('/v1/metrics', $transport->sentPaths[0]);
    }

    private function createMetric(string $name = 'counter_a', MetricType $type = MetricType::Counter): OtlpMetric
    {
        return new OtlpMetric(
            name: $name,
            description: 'Test metric',
            unit: '',
            type: $type,
            dataPoints: [
                ['value' => 1.0, 'time_unix_nano' => 1000000000, 'attributes' => []],
            ],
        );
    }
}
