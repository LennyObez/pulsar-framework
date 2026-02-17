<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Export\Otlp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Export\Otlp\BatchExporter;
use Pulsar\Extension\Observability\Export\Otlp\LogsBatchExporter;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\OtlpLogRecord;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\ResourceInfo;
use Pulsar\Extension\Observability\Export\Otlp\Transport\OtlpTransportInterface;
use Pulsar\Extension\Observability\Export\Otlp\Transport\TransportResult;

#[CoversClass(LogsBatchExporter::class)]
#[CoversClass(BatchExporter::class)]
final class LogsBatchExporterTest extends TestCase
{
    #[Test]
    public function enqueueAndFlushSendsToLogsEndpoint(): void
    {
        $sentPayloads = [];
        $transport = $this->createTransport($sentPayloads);

        $exporter = new LogsBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(['service.name' => 'test']),
            maxBatchSize: 100,
        );

        $exporter->enqueue(new OtlpLogRecord(
            timeUnixNano: 1_700_000_000_000_000_000,
            severityNumber: 17,
            severityText: 'ERROR',
            body: 'Something broke',
            attributes: [],
            traceId: null,
            spanId: null,
        ));

        $exporter->flush();

        self::assertSame(0, $exporter->queueSize());
        self::assertCount(1, $sentPayloads);
        self::assertSame('/v1/logs', $sentPayloads[0]['path']);
        self::assertStringContainsString('Something broke', $sentPayloads[0]['payload']);
    }

    #[Test]
    public function shutdownFlushesRemaining(): void
    {
        $sentPayloads = [];
        $transport = $this->createTransport($sentPayloads);

        $exporter = new LogsBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(),
            maxBatchSize: 100,
        );

        $exporter->enqueue(new OtlpLogRecord(
            timeUnixNano: 1_700_000_000_000_000_000,
            severityNumber: 9,
            severityText: 'INFO',
            body: 'Final log entry',
            attributes: [],
            traceId: null,
            spanId: null,
        ));

        $exporter->shutdown();

        self::assertCount(1, $sentPayloads);
        self::assertStringContainsString('Final log entry', $sentPayloads[0]['payload']);
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
