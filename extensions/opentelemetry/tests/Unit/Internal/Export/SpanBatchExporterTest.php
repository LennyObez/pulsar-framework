<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Internal\Export;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Internal\Export\SpanBatchExporter;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpFieldNumbers;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpSpan;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\ResourceInfo;

#[CoversClass(SpanBatchExporter::class)]
final class SpanBatchExporterTest extends TestCase
{
    #[Test]
    public function enqueueAddsSpanToQueue(): void
    {
        $transport = new StubTransport();
        $exporter = new SpanBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(),
            maxBatchSize: 100,
            maxQueueSize: 200,
        );

        $exporter->enqueue($this->createSpan());

        self::assertSame(1, $exporter->queueSize());
    }

    #[Test]
    public function flushSendsEnqueuedSpansViaTransport(): void
    {
        $transport = new StubTransport();
        $exporter = new SpanBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(),
            maxBatchSize: 100,
            maxQueueSize: 200,
        );

        $exporter->enqueue($this->createSpan());
        $exporter->enqueue($this->createSpan('span-b'));
        $exporter->flush();

        self::assertSame(0, $exporter->queueSize());
        self::assertCount(1, $transport->sentPayloads);
    }

    #[Test]
    public function flushWithEmptyQueueDoesNotSend(): void
    {
        $transport = new StubTransport();
        $exporter = new SpanBatchExporter(
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
        $exporter = new SpanBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(),
            maxBatchSize: 100,
            maxQueueSize: 200,
        );

        $exporter->enqueue($this->createSpan());
        $exporter->shutdown();

        self::assertSame(0, $exporter->queueSize());

        $exporter->enqueue($this->createSpan());
        self::assertSame(0, $exporter->queueSize());
    }

    #[Test]
    public function queueSizeReflectsEnqueuedItems(): void
    {
        $transport = new StubTransport();
        $exporter = new SpanBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(),
            maxBatchSize: 100,
            maxQueueSize: 200,
        );

        self::assertSame(0, $exporter->queueSize());

        $exporter->enqueue($this->createSpan());
        self::assertSame(1, $exporter->queueSize());

        $exporter->enqueue($this->createSpan());
        self::assertSame(2, $exporter->queueSize());
    }

    #[Test]
    public function autoFlushTriggersAtBatchSizeThreshold(): void
    {
        $transport = new StubTransport();
        $exporter = new SpanBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(),
            maxBatchSize: 2,
            maxQueueSize: 100,
        );

        $exporter->enqueue($this->createSpan());
        self::assertCount(0, $transport->sentPayloads);

        $exporter->enqueue($this->createSpan());
        self::assertCount(1, $transport->sentPayloads);
        self::assertSame(0, $exporter->queueSize());
    }

    #[Test]
    public function usesTracesSignalPath(): void
    {
        $transport = new StubTransport();
        $exporter = new SpanBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(),
            maxBatchSize: 100,
            maxQueueSize: 200,
        );

        $exporter->enqueue($this->createSpan());
        $exporter->flush();

        self::assertSame('/v1/traces', $transport->sentPaths[0]);
    }

    private function createSpan(string $name = 'test-span'): OtlpSpan
    {
        return new OtlpSpan(
            traceId: str_repeat("\x01", 16),
            spanId: str_repeat("\x02", 8),
            parentSpanId: null,
            name: $name,
            startTimeUnixNano: 1000000000,
            endTimeUnixNano: 2000000000,
            attributes: ['http.method' => 'GET'],
            statusCode: OtlpFieldNumbers::STATUS_CODE_OK,
            statusMessage: '',
        );
    }
}
