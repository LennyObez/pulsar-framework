<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Internal\Export;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Internal\Export\BatchExporter;
use Pulsar\Extension\OpenTelemetry\Internal\Transport\OtlpTransportInterface;
use Pulsar\Extension\OpenTelemetry\Internal\Transport\TransportResult;

use function implode;

#[CoversClass(BatchExporter::class)]
final class BatchExporterTest extends TestCase
{
    #[Test]
    public function enqueueAddsItemToQueue(): void
    {
        $exporter = $this->createExporter(maxBatchSize: 100);

        $exporter->enqueue('item-1');

        self::assertSame(1, $exporter->queueSize());
    }

    #[Test]
    public function flushDrainsQueue(): void
    {
        $transport = new StubTransport();
        $exporter = $this->createExporter(transport: $transport, maxBatchSize: 100);

        $exporter->enqueue('item-1');
        $exporter->enqueue('item-2');
        self::assertSame(2, $exporter->queueSize());

        $exporter->flush();

        self::assertSame(0, $exporter->queueSize());
        self::assertCount(1, $transport->sentPayloads);
    }

    #[Test]
    public function flushWithEmptyQueueDoesNothing(): void
    {
        $transport = new StubTransport();
        $exporter = $this->createExporter(transport: $transport, maxBatchSize: 100);

        $exporter->flush();

        self::assertSame(0, $exporter->queueSize());
        self::assertCount(0, $transport->sentPayloads);
    }

    #[Test]
    public function autoFlushAtBatchSizeThreshold(): void
    {
        $transport = new StubTransport();
        $exporter = $this->createExporter(transport: $transport, maxBatchSize: 3);

        $exporter->enqueue('a');
        $exporter->enqueue('b');
        self::assertSame(2, $exporter->queueSize());
        self::assertCount(0, $transport->sentPayloads);

        // Third item should trigger auto-flush
        $exporter->enqueue('c');

        self::assertSame(0, $exporter->queueSize());
        self::assertCount(1, $transport->sentPayloads);
    }

    #[Test]
    public function queueOverflowDropsOldestItems(): void
    {
        $transport = new StubTransport();
        $exporter = $this->createExporter(
            transport: $transport,
            maxBatchSize: 100,
            maxQueueSize: 3,
        );

        $exporter->enqueue('a');
        $exporter->enqueue('b');
        $exporter->enqueue('c');
        self::assertSame(3, $exporter->queueSize());

        // Enqueue one more — should drop 'a'
        $exporter->enqueue('d');
        self::assertSame(3, $exporter->queueSize());

        // Flush to see what remains
        $exporter->flush();

        self::assertSame(0, $exporter->queueSize());
        self::assertCount(1, $transport->sentPayloads);
        // Payload should contain b,c,d (not a)
        self::assertSame('b,c,d', $transport->sentPayloads[0]);
    }

    #[Test]
    public function shutdownFlushesAndPreventsSubsequentEnqueue(): void
    {
        $transport = new StubTransport();
        $exporter = $this->createExporter(transport: $transport, maxBatchSize: 100);

        $exporter->enqueue('item-1');
        $exporter->shutdown();

        self::assertTrue($exporter->isShutDown());
        self::assertSame(0, $exporter->queueSize());
        self::assertCount(1, $transport->sentPayloads);

        // Enqueue after shutdown should be silently ignored
        $exporter->enqueue('item-2');
        self::assertSame(0, $exporter->queueSize());
    }

    #[Test]
    public function doubleShutdownIsIdempotent(): void
    {
        $transport = new StubTransport();
        $exporter = $this->createExporter(transport: $transport, maxBatchSize: 100);

        $exporter->enqueue('item-1');
        $exporter->shutdown();
        $exporter->shutdown();

        self::assertTrue($exporter->isShutDown());
        self::assertCount(1, $transport->sentPayloads);
    }

    #[Test]
    public function flushDrainsInBatchSizedChunks(): void
    {
        $transport = new StubTransport();
        $exporter = $this->createExporter(
            transport: $transport,
            maxBatchSize: 2,
            maxQueueSize: 100,
        );

        // Enqueue first 2 — triggers auto-flush
        $exporter->enqueue('a');
        $exporter->enqueue('b');
        // That's 1 flush so far

        // Enqueue 3 more without triggering auto (only at 2 threshold)
        $exporter->enqueue('c');
        // queue = [c]
        self::assertSame(1, $exporter->queueSize());

        $exporter->enqueue('d');
        // queue was [c, d] -> triggers auto-flush
        self::assertSame(0, $exporter->queueSize());

        $exporter->enqueue('e');
        $exporter->flush();

        // Total flushes: auto(a,b), auto(c,d), manual(e) = 3 payloads
        self::assertCount(3, $transport->sentPayloads);
        self::assertSame('a,b', $transport->sentPayloads[0]);
        self::assertSame('c,d', $transport->sentPayloads[1]);
        self::assertSame('e', $transport->sentPayloads[2]);
    }

    #[Test]
    public function transportFailureIsLoggedButDoesNotThrow(): void
    {
        $transport = new StubTransport(
            result: TransportResult::failure(503, 'Service Unavailable', retryable: true),
        );
        $exporter = $this->createExporter(transport: $transport, maxBatchSize: 100);

        $exporter->enqueue('item-1');
        $exporter->flush();

        // Should not throw; queue should still drain
        self::assertSame(0, $exporter->queueSize());
        self::assertCount(1, $transport->sentPayloads);
    }

    #[Test]
    public function emptySerializerOutputSkipsSend(): void
    {
        $transport = new StubTransport();
        $exporter = new BatchExporter(
            transport: $transport,
            serializer: fn(array $items): string => '',
            signalPath: '/v1/traces',
            maxBatchSize: 100,
        );

        $exporter->enqueue('item-1');
        $exporter->flush();

        self::assertSame(0, $exporter->queueSize());
        // Transport send should NOT have been called for empty payload
        self::assertCount(0, $transport->sentPayloads);
    }

    #[Test]
    public function isShutDownReturnsFalseInitially(): void
    {
        $exporter = $this->createExporter(maxBatchSize: 100);

        self::assertFalse($exporter->isShutDown());
    }

    #[Test]
    public function correctSignalPathIsSentToTransport(): void
    {
        $transport = new StubTransport();
        /** @var BatchExporter<string> $exporter */
        $exporter = new BatchExporter(
            transport: $transport,
            serializer: /** @param list<string> $items */ fn(array $items): string => implode(',', $items),
            signalPath: '/v1/metrics',
            maxBatchSize: 100,
        );

        $exporter->enqueue('m1');
        $exporter->flush();

        self::assertSame('/v1/metrics', $transport->sentPaths[0]);
    }

    /**
     * @return BatchExporter<string>
     */
    private function createExporter(
        ?StubTransport $transport = null,
        int $maxBatchSize = 512,
        int $maxQueueSize = 2048,
    ): BatchExporter {
        /** @var BatchExporter<string> */
        return new BatchExporter(
            transport: $transport ?? new StubTransport(),
            serializer: /** @param list<string> $items */ fn(array $items): string => implode(',', $items),
            signalPath: '/v1/traces',
            maxBatchSize: $maxBatchSize,
            maxQueueSize: $maxQueueSize,
        );
    }
}

/**
 * Test double that records all send() calls without real I/O.
 */
final class StubTransport implements OtlpTransportInterface
{
    /** @var list<string> */
    public array $sentPayloads = [];

    /** @var list<string> */
    public array $sentPaths = [];

    public function __construct(
        private readonly TransportResult $result = new TransportResult(
            success: true,
            httpStatus: 200,
            errorMessage: '',
            retryable: false,
        ),
    ) {}

    public function send(string $path, string $protobufPayload): TransportResult
    {
        $this->sentPaths[] = $path;
        $this->sentPayloads[] = $protobufPayload;

        return $this->result;
    }
}
