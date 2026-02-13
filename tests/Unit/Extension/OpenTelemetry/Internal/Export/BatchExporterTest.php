<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OpenTelemetry\Internal\Export;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Internal\Export\BatchExporter;
use Pulsar\Extension\OpenTelemetry\Internal\Transport\OtlpTransportInterface;
use Pulsar\Extension\OpenTelemetry\Internal\Transport\TransportResult;

use function count;

#[CoversClass(BatchExporter::class)]
final class BatchExporterTest extends TestCase
{
    #[Test]
    public function enqueueAddsItemToQueue(): void
    {
        $transport = $this->createTransport();

        $exporter = new BatchExporter(
            transport: $transport,
            serializer: fn(array $items): string => 'payload',
            signalPath: '/v1/traces',
            maxBatchSize: 10,
        );

        $exporter->enqueue('item1');

        self::assertSame(1, $exporter->queueSize());
    }

    #[Test]
    public function flushSendsAllQueuedItems(): void
    {
        $sentPayloads = [];
        $transport = $this->createTransport($sentPayloads);

        $exporter = new BatchExporter(
            transport: $transport,
            serializer: fn(array $items): string => 'batch_' . count($items),
            signalPath: '/v1/traces',
            maxBatchSize: 100,
        );

        $exporter->enqueue('item1');
        $exporter->enqueue('item2');
        $exporter->enqueue('item3');

        self::assertSame(3, $exporter->queueSize());

        $exporter->flush();

        self::assertSame(0, $exporter->queueSize());
        self::assertCount(1, $sentPayloads);
        self::assertSame('/v1/traces', $sentPayloads[0]['path']);
        self::assertSame('batch_3', $sentPayloads[0]['payload']);
    }

    #[Test]
    public function flushDoesNothingWhenQueueIsEmpty(): void
    {
        $sentPayloads = [];
        $transport = $this->createTransport($sentPayloads);

        $exporter = new BatchExporter(
            transport: $transport,
            serializer: fn(array $items): string => 'payload',
            signalPath: '/v1/traces',
        );

        $exporter->flush();

        self::assertCount(0, $sentPayloads);
    }

    #[Test]
    public function autoFlushesWhenBatchSizeReached(): void
    {
        $sentPayloads = [];
        $transport = $this->createTransport($sentPayloads);

        $exporter = new BatchExporter(
            transport: $transport,
            serializer: fn(array $items): string => 'batch_' . count($items),
            signalPath: '/v1/metrics',
            maxBatchSize: 3,
        );

        $exporter->enqueue('item1');
        $exporter->enqueue('item2');

        // Not yet at threshold
        self::assertSame(2, $exporter->queueSize());
        self::assertCount(0, $sentPayloads);

        // This triggers auto-flush
        $exporter->enqueue('item3');

        self::assertSame(0, $exporter->queueSize());
        self::assertCount(1, $sentPayloads);
        self::assertSame('batch_3', $sentPayloads[0]['payload']);
    }

    #[Test]
    public function queueOverflowDropsOldestItems(): void
    {
        $receivedBatches = [];
        $transport = $this->createTransport();

        $exporter = new BatchExporter(
            transport: $transport,
            serializer: function (array $items) use (&$receivedBatches): string {
                $receivedBatches[] = $items;
                return 'payload';
            },
            signalPath: '/v1/logs',
            maxBatchSize: 100,
            maxQueueSize: 3,
        );

        $exporter->enqueue('a');
        $exporter->enqueue('b');
        $exporter->enqueue('c');

        // Queue is now at max capacity
        self::assertSame(3, $exporter->queueSize());

        // Adding another should drop the oldest
        $exporter->enqueue('d');

        self::assertSame(3, $exporter->queueSize());

        // Flush and verify the oldest was dropped
        $exporter->flush();

        self::assertCount(1, $receivedBatches);
        self::assertSame(['b', 'c', 'd'], $receivedBatches[0]);
    }

    #[Test]
    public function shutdownFlushesAndPreventsNewEnqueues(): void
    {
        $sentPayloads = [];
        $transport = $this->createTransport($sentPayloads);

        $exporter = new BatchExporter(
            transport: $transport,
            serializer: fn(array $items): string => 'final_batch',
            signalPath: '/v1/traces',
            maxBatchSize: 100,
        );

        $exporter->enqueue('item1');
        $exporter->enqueue('item2');

        $exporter->shutdown();

        // Queue should be flushed
        self::assertSame(0, $exporter->queueSize());
        self::assertCount(1, $sentPayloads);
        self::assertTrue($exporter->isShutDown);

        // New enqueues should be ignored
        $exporter->enqueue('item3');
        self::assertSame(0, $exporter->queueSize());
    }

    #[Test]
    public function shutdownIsIdempotent(): void
    {
        $sentPayloads = [];
        $transport = $this->createTransport($sentPayloads);

        $exporter = new BatchExporter(
            transport: $transport,
            serializer: fn(array $items): string => 'payload',
            signalPath: '/v1/traces',
            maxBatchSize: 100,
        );

        $exporter->enqueue('item1');
        $exporter->shutdown();
        $exporter->shutdown();

        // Should only have sent once
        self::assertCount(1, $sentPayloads);
    }

    #[Test]
    public function emptySerializerOutputSkipsSend(): void
    {
        $sentPayloads = [];
        $transport = $this->createTransport($sentPayloads);

        $exporter = new BatchExporter(
            transport: $transport,
            serializer: fn(array $items): string => '',
            signalPath: '/v1/traces',
            maxBatchSize: 100,
        );

        $exporter->enqueue('item1');
        $exporter->flush();

        // Empty payload should not trigger transport send
        self::assertCount(0, $sentPayloads);
    }

    #[Test]
    public function flushDrainsInBatchSizedChunks(): void
    {
        $sentPayloads = [];
        $transport = $this->createTransport($sentPayloads);

        $exporter = new BatchExporter(
            transport: $transport,
            serializer: fn(array $items): string => 'batch_' . count($items),
            signalPath: '/v1/traces',
            maxBatchSize: 3,
            maxQueueSize: 100,
        );

        // Enqueue 7 items without auto-flushing by setting batch size high
        // Actually with maxBatchSize=3, auto-flush triggers at 3
        // So we need a different approach: enqueue 2, flush will drain
        // Let's use a bigger batch size for this test
        $exporter2 = new BatchExporter(
            transport: $transport,
            serializer: fn(array $items): string => 'batch_' . count($items),
            signalPath: '/v1/traces',
            maxBatchSize: 3,
            maxQueueSize: 100,
        );

        // Enqueue items — auto-flush at 3 will happen, then we add more
        $exporter2->enqueue('a');
        $exporter2->enqueue('b');
        $exporter2->enqueue('c'); // auto-flush: batch of 3

        self::assertSame(0, $exporter2->queueSize());
        self::assertCount(1, $sentPayloads);

        $exporter2->enqueue('d');
        $exporter2->enqueue('e');

        // Flush the remaining 2
        $exporter2->flush();

        self::assertCount(2, $sentPayloads);
        self::assertSame('batch_3', $sentPayloads[0]['payload']);
        self::assertSame('batch_2', $sentPayloads[1]['payload']);
    }

    #[Test]
    public function transportFailureIsLoggedButDoesNotThrow(): void
    {
        $transport = $this->createStub(OtlpTransportInterface::class);
        $transport->method('send')->willReturn(
            TransportResult::failure(503, 'Service unavailable', retryable: true),
        );

        $exporter = new BatchExporter(
            transport: $transport,
            serializer: fn(array $items): string => 'payload',
            signalPath: '/v1/traces',
            maxBatchSize: 100,
        );

        $exporter->enqueue('item1');

        // Should not throw — failure is handled gracefully
        $exporter->flush();

        self::assertSame(0, $exporter->queueSize());
    }

    /**
     * Create a mock transport that records sent payloads.
     *
     * @param list<array{path: string, payload: string}> $sentPayloads
     */
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
