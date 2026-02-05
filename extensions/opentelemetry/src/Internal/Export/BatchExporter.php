<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Internal\Export;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Api\Internal;
use Pulsar\Extension\OpenTelemetry\Internal\Transport\OtlpTransportInterface;

use function array_slice;
use function array_splice;
use function count;
use function usleep;

/**
 * Generic batch exporter that queues items and flushes them in batches.
 *
 * Items are enqueued individually and flushed explicitly via flush() or
 * shutdown(). The queue is capped at maxQueueSize — when exceeded, the
 * oldest items are dropped.
 *
 * @template T
 */
#[Internal(reason: 'Generic batch queue for OTLP signal export')]
final class BatchExporter
{
    /** @var list<T> */
    private array $queue = [];

    private bool $isShutDown = false;

    /**
     * @param Closure(list<T>): string $serializer  Converts a batch of items to protobuf binary
     * @param string                    $signalPath  OTLP endpoint path (e.g., "/v1/traces")
     * @param int                       $maxBatchSize  Items per flush (default 512)
     * @param int                       $maxQueueSize  Queue capacity before dropping oldest (default 2048)
     */
    public function __construct(
        private readonly OtlpTransportInterface $transport,
        private readonly Closure $serializer,
        private readonly string $signalPath,
        private readonly int $maxBatchSize = 512,
        private readonly int $maxQueueSize = 2048,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Add an item to the queue.
     *
     * @param T $item
     */
    public function enqueue(mixed $item): void
    {
        if ($this->isShutDown) {
            return;
        }

        $this->queue[] = $item;

        // Drop oldest items if queue exceeds max capacity
        if (count($this->queue) > $this->maxQueueSize) {
            $overflow = count($this->queue) - $this->maxQueueSize;
            $this->queue = array_slice($this->queue, $overflow);
            $this->logger->warning('OpenTelemetry batch queue overflow, dropped {count} oldest items', [
                'count' => $overflow,
                'signal_path' => $this->signalPath,
            ]);
        }

        // Auto-flush when batch size threshold is reached
        if (count($this->queue) >= $this->maxBatchSize) {
            $this->flush();
        }
    }

    /**
     * Flush all queued items by serializing and sending via transport.
     */
    public function flush(): void
    {
        if ($this->queue === []) {
            return;
        }

        // Drain the queue in batch-sized chunks
        while ($this->queue !== []) {
            $batchSize = min(count($this->queue), $this->maxBatchSize);
            $batch = array_slice($this->queue, 0, $batchSize);
            array_splice($this->queue, 0, $batchSize);

            $this->exportBatch($batch);
        }
    }

    /**
     * Shut down the exporter, flushing all remaining items.
     */
    public function shutdown(): void
    {
        if ($this->isShutDown) {
            return;
        }

        $this->flush();
        $this->isShutDown = true;
    }

    /**
     * Get the current queue size (for testing/monitoring).
     */
    public function queueSize(): int
    {
        return count($this->queue);
    }

    /**
     * Check if the exporter has been shut down.
     */
    public function isShutDown(): bool
    {
        return $this->isShutDown;
    }

    /**
     * @param list<T> $batch
     */
    private function exportBatch(array $batch): void
    {
        $payload = ($this->serializer)($batch);

        if ($payload === '') {
            return;
        }

        $result = $this->transport->send($this->signalPath, $payload);

        if (!$result->success) {
            if ($result->retryable) {
                $this->logger->warning('OpenTelemetry export failed (retrying): {error}', [
                    'error' => $result->errorMessage,
                    'http_status' => $result->httpStatus,
                    'signal_path' => $this->signalPath,
                    'batch_size' => count($batch),
                ]);

                usleep(1_000_000);
                $result = $this->transport->send($this->signalPath, $payload);
            }

            if (!$result->success) {
                $this->logger->error('OpenTelemetry export failed: {error}', [
                    'error' => $result->errorMessage,
                    'http_status' => $result->httpStatus,
                    'retryable' => $result->retryable,
                    'signal_path' => $this->signalPath,
                    'batch_size' => count($batch),
                ]);
            }
        }
    }
}
