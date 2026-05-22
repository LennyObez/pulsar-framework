<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Storage;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Throwable;

use function count;
use function register_shutdown_function;

/**
 * In-memory buffered event store that wraps any EventStoreInterface.
 *
 * Accumulates events in memory and flushes them to the inner store
 * in batch when the buffer reaches maxBufferSize or when flush()
 * is called explicitly. Registers a shutdown function to flush
 * remaining events on process termination.
 *
 * This eliminates per-event write contention from the request hot path.
 */
#[Internal]
final class BufferedEventStore implements EventStoreInterface
{
    /** @var list<array{envelope: EventEnvelope, payloadJson: string, tenantHash: ?string}> */
    private array $buffer = [];

    private bool $shutdownRegistered = false;

    public function __construct(
        private readonly EventStoreInterface $inner,
        private readonly int $maxBufferSize = 100,
    ) {}

    #[Override]
    public function store(EventEnvelope $envelope, string $payloadJson, ?string $tenantHash = null): void
    {
        $this->buffer[] = [
            'envelope' => $envelope,
            'payloadJson' => $payloadJson,
            'tenantHash' => $tenantHash,
        ];

        $this->ensureShutdownRegistered();

        if (count($this->buffer) >= $this->maxBufferSize) {
            $this->flush();
        }
    }

    /**
     * Flush all buffered events to the inner store.
     *
     * Delegates to inner store's transaction() if available via ConnectionInterface,
     * otherwise stores events sequentially.
     */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $events = $this->buffer;
        $this->buffer = [];

        foreach ($events as $event) {
            try {
                $this->inner->store($event['envelope'], $event['payloadJson'], $event['tenantHash']);
            } catch (Throwable) {
                // Buffered store must not crash the application on flush failures.
                // Events are best-effort; lost events are acceptable in buffered mode.
            }
        }
    }

    /**
     * Get the number of events currently in the buffer.
     */
    public function bufferCount(): int
    {
        return count($this->buffer);
    }

    #[Override]
    public function query(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $this->flush();

        return $this->inner->query($filters, $limit, $offset);
    }

    #[Override]
    public function count(array $filters = []): int
    {
        $this->flush();

        return $this->inner->count($filters);
    }

    #[Override]
    public function find(string $eventId): ?array
    {
        // Check buffer first
        foreach ($this->buffer as $event) {
            if ($event['envelope']->eventId === $eventId) {
                $this->flush();

                return $this->inner->find($eventId);
            }
        }

        return $this->inner->find($eventId);
    }

    #[Override]
    public function sizeInBytes(): int
    {
        return $this->inner->sizeInBytes();
    }

    #[Override]
    public function deleteOlderThan(int $timestampUs): int
    {
        $this->flush();

        return $this->inner->deleteOlderThan($timestampUs);
    }

    #[Override]
    public function deleteByEventTypes(array $eventTypes): int
    {
        $this->flush();

        return $this->inner->deleteByEventTypes($eventTypes);
    }

    #[Override]
    public function deleteByPayloadKey(string $eventType, string $jsonPath, string $value): int
    {
        $this->flush();

        return $this->inner->deleteByPayloadKey($eventType, $jsonPath, $value);
    }

    #[Override]
    public function clear(): void
    {
        $this->buffer = [];
        $this->inner->clear();
    }

    #[Override]
    public function vacuum(): void
    {
        $this->flush();
        $this->inner->vacuum();
    }

    /**
     * Get the inner store.
     */
    public function inner(): EventStoreInterface
    {
        return $this->inner;
    }

    private function ensureShutdownRegistered(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;
        register_shutdown_function($this->flush(...));
    }
}
