<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Export\Otlp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Export\Otlp\BatchExporter;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\OtlpFieldNumbers;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\OtlpSpan;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\ResourceInfo;
use Pulsar\Extension\Observability\Export\Otlp\SpanBatchExporter;
use Pulsar\Extension\Observability\Export\Otlp\Transport\OtlpTransportInterface;
use Pulsar\Extension\Observability\Export\Otlp\Transport\TransportResult;

use function hex2bin;

#[CoversClass(SpanBatchExporter::class)]
#[CoversClass(BatchExporter::class)]
final class SpanBatchExporterTest extends TestCase
{
    #[Test]
    public function enqueueAndFlushSendsToTracesEndpoint(): void
    {
        $sentPayloads = [];
        $transport = $this->createTransport($sentPayloads);

        $exporter = new SpanBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(['service.name' => 'test']),
            maxBatchSize: 100,
        );

        $exporter->enqueue($this->createSpan('op1'));
        $exporter->enqueue($this->createSpan('op2'));
        $exporter->flush();

        self::assertSame(0, $exporter->queueSize());
        self::assertCount(1, $sentPayloads);
        self::assertSame('/v1/traces', $sentPayloads[0]['path']);
        self::assertStringContainsString('op1', $sentPayloads[0]['payload']);
        self::assertStringContainsString('op2', $sentPayloads[0]['payload']);
    }

    #[Test]
    public function shutdownFlushesRemaining(): void
    {
        $sentPayloads = [];
        $transport = $this->createTransport($sentPayloads);

        $exporter = new SpanBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(),
            maxBatchSize: 100,
        );

        $exporter->enqueue($this->createSpan('final'));
        $exporter->shutdown();

        self::assertCount(1, $sentPayloads);
        self::assertStringContainsString('final', $sentPayloads[0]['payload']);
    }

    private function createSpan(string $name): OtlpSpan
    {
        return new OtlpSpan(
            traceId: (string) hex2bin('0af7651916cd43dd8448eb211c80319c'),
            spanId: (string) hex2bin('b7ad6b7169203331'),
            parentSpanId: null,
            name: $name,
            kind: OtlpFieldNumbers::SPAN_KIND_INTERNAL,
            startTimeUnixNano: 1_700_000_000_000_000_000,
            endTimeUnixNano: 1_700_000_001_000_000_000,
            attributes: [],
            statusCode: OtlpFieldNumbers::STATUS_CODE_UNSET,
            statusMessage: '',
        );
    }

    /** @param list<array{path: string, payload: string}> $sentPayloads */
    private function createTransport(array &$sentPayloads = []): OtlpTransportInterface
    {
        return new class ($sentPayloads) implements OtlpTransportInterface {
            /** @param list<array{path: string, payload: string}> $sent */
            public function __construct(public array &$sent) {}

            public function send(string $path, string $protobufPayload): TransportResult
            {
                $this->sent[] = ['path' => $path, 'payload' => $protobufPayload];
                return TransportResult::success(200);
            }
        };
    }
}
