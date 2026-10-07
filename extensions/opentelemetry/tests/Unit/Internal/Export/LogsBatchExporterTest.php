<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Internal\Export;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Internal\Export\LogsBatchExporter;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpLogRecord;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\ResourceInfo;

#[CoversClass(LogsBatchExporter::class)]
final class LogsBatchExporterTest extends TestCase
{
    #[Test]
    public function enqueueAddsLogRecordToQueue(): void
    {
        $transport = new StubTransport();
        $exporter = new LogsBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(),
            maxBatchSize: 100,
            maxQueueSize: 200,
        );

        $record = $this->createLogRecord();
        $exporter->enqueue($record);

        self::assertSame(1, $exporter->queueSize());
    }

    #[Test]
    public function flushSendsEnqueuedRecordsViaTransport(): void
    {
        $transport = new StubTransport();
        $exporter = new LogsBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(),
            maxBatchSize: 100,
            maxQueueSize: 200,
        );

        $exporter->enqueue($this->createLogRecord());
        $exporter->enqueue($this->createLogRecord());
        $exporter->flush();

        self::assertSame(0, $exporter->queueSize());
        self::assertCount(1, $transport->sentPayloads);
    }

    #[Test]
    public function flushWithEmptyQueueDoesNotSend(): void
    {
        $transport = new StubTransport();
        $exporter = new LogsBatchExporter(
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
        $exporter = new LogsBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(),
            maxBatchSize: 100,
            maxQueueSize: 200,
        );

        $exporter->enqueue($this->createLogRecord());
        $exporter->shutdown();

        self::assertSame(0, $exporter->queueSize());
        self::assertCount(1, $transport->sentPayloads);

        // Enqueue after shutdown is silently ignored
        $exporter->enqueue($this->createLogRecord());
        self::assertSame(0, $exporter->queueSize());
    }

    #[Test]
    public function queueSizeReflectsEnqueuedItems(): void
    {
        $transport = new StubTransport();
        $exporter = new LogsBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(),
            maxBatchSize: 100,
            maxQueueSize: 200,
        );

        self::assertSame(0, $exporter->queueSize());

        $exporter->enqueue($this->createLogRecord());
        self::assertSame(1, $exporter->queueSize());

        $exporter->enqueue($this->createLogRecord());
        self::assertSame(2, $exporter->queueSize());
    }

    #[Test]
    public function autoFlushTriggersAtBatchSizeThreshold(): void
    {
        $transport = new StubTransport();
        $exporter = new LogsBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(),
            maxBatchSize: 2,
            maxQueueSize: 100,
        );

        $exporter->enqueue($this->createLogRecord());
        self::assertCount(0, $transport->sentPayloads);

        $exporter->enqueue($this->createLogRecord());
        self::assertSame(0, $exporter->queueSize());
        self::assertCount(1, $transport->sentPayloads);
    }

    #[Test]
    public function usesLogsSignalPath(): void
    {
        $transport = new StubTransport();
        $exporter = new LogsBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(),
            maxBatchSize: 100,
            maxQueueSize: 200,
        );

        $exporter->enqueue($this->createLogRecord());
        $exporter->flush();

        self::assertSame('/v1/logs', $transport->sentPaths[0]);
    }

    private function createLogRecord(): OtlpLogRecord
    {
        return new OtlpLogRecord(
            timeUnixNano: 1000000000,
            severityNumber: 17,
            severityText: 'ERROR',
            body: 'Test log message',
            attributes: ['key' => 'value'],
            traceId: null,
            spanId: null,
        );
    }
}
