<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Tracing\Bridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\ResourceInfo;
use Pulsar\Extension\Observability\Export\Otlp\SpanBatchExporter;
use Pulsar\Extension\Observability\Export\Otlp\Transport\OtlpTransportInterface;
use Pulsar\Extension\Observability\Export\Otlp\Transport\TransportResult;
use Pulsar\Extension\Observability\Tracing\Bridge\OtlpTracerBridge;
use Pulsar\Extension\Observability\Tracing\Sampling\AlwaysSampler;
use Pulsar\Extension\Observability\Tracing\Sampling\NeverSampler;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanId;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\TraceId;

#[CoversClass(OtlpTracerBridge::class)]
final class OtlpTracerBridgeTest extends TestCase
{
    private SpanBatchExporter $exporter;

    protected function setUp(): void
    {
        $transport = $this->createStub(OtlpTransportInterface::class);
        $transport->method('send')->willReturn(TransportResult::success(200));

        $this->exporter = new SpanBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(),
            maxBatchSize: 10000, // Large batch to prevent auto-flush during tests
        );
    }

    #[Test]
    public function enqueuesSampledSpan(): void
    {
        $bridge = new OtlpTracerBridge(
            exporter: $this->exporter,
            sampler: new AlwaysSampler(),
        );

        $span = $this->createEndedSpan('test-operation');
        $bridge->onEnd($span);

        self::assertSame(1, $this->exporter->queueSize());
    }

    #[Test]
    public function skipsUnsampledSpan(): void
    {
        $bridge = new OtlpTracerBridge(
            exporter: $this->exporter,
            sampler: new NeverSampler(),
        );

        $span = $this->createEndedSpan('test-operation');
        $bridge->onEnd($span);

        self::assertSame(0, $this->exporter->queueSize());
    }

    #[Test]
    public function enqueuesMultipleSpans(): void
    {
        $bridge = new OtlpTracerBridge(
            exporter: $this->exporter,
            sampler: new AlwaysSampler(),
        );

        $bridge->onEnd($this->createEndedSpan('span-1'));
        $bridge->onEnd($this->createEndedSpan('span-2'));
        $bridge->onEnd($this->createEndedSpan('span-3'));

        self::assertSame(3, $this->exporter->queueSize());
    }

    #[Test]
    public function doesNotEnqueueWhenSamplerRejects(): void
    {
        $bridge = new OtlpTracerBridge(
            exporter: $this->exporter,
            sampler: new NeverSampler(),
        );

        $bridge->onEnd($this->createEndedSpan('span-1'));
        $bridge->onEnd($this->createEndedSpan('span-2'));

        self::assertSame(0, $this->exporter->queueSize());
    }

    private function createEndedSpan(string $name): Span
    {
        $context = new TraceContext(
            new TraceId('0af7651916cd43dd8448eb211c80319c'),
            new SpanId('b7ad6b7169203331'),
        );

        $span = new Span($name, $context);
        $span->end();

        return $span;
    }
}
