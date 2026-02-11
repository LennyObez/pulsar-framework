<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Storage\BufferedEventStore;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;

#[CoversClass(BufferedEventStore::class)]
final class BufferedEventStoreTest extends TestCase
{
    #[Test]
    public function storeBuffersEventsUntilFlush(): void
    {
        $inner = new InMemoryEventStore();
        $store = new BufferedEventStore($inner, maxBufferSize: 10);
        $envelope = $this->buildEnvelope('evt-1');

        $store->store($envelope, '{"test":1}');

        // Event should be buffered, not yet in inner
        self::assertSame(1, $store->bufferCount());
        self::assertSame(0, $inner->storedCount);

        $store->flush();

        self::assertSame(0, $store->bufferCount());
        self::assertSame(1, $inner->storedCount);
    }

    #[Test]
    public function storeAutoFlushesWhenBufferFull(): void
    {
        $inner = new InMemoryEventStore();
        $store = new BufferedEventStore($inner, maxBufferSize: 3);

        $store->store($this->buildEnvelope('e1'), '{}');
        $store->store($this->buildEnvelope('e2'), '{}');
        self::assertSame(0, $inner->storedCount);

        // Third store triggers auto-flush
        $store->store($this->buildEnvelope('e3'), '{}');
        self::assertSame(3, $inner->storedCount);
        self::assertSame(0, $store->bufferCount());
    }

    #[Test]
    public function flushOnEmptyBufferIsNoOp(): void
    {
        $inner = new InMemoryEventStore();
        $store = new BufferedEventStore($inner);

        $store->flush();

        self::assertSame(0, $inner->storedCount);
    }

    #[Test]
    public function queryFlushesBeforeDelegating(): void
    {
        $inner = new InMemoryEventStore();
        $store = new BufferedEventStore($inner, maxBufferSize: 100);
        $store->store($this->buildEnvelope('e1'), '{}');

        self::assertSame(1, $store->bufferCount());
        $store->query();
        self::assertSame(0, $store->bufferCount());
        self::assertSame(1, $inner->storedCount);
    }

    #[Test]
    public function countFlushesBeforeDelegating(): void
    {
        $inner = new InMemoryEventStore();
        $store = new BufferedEventStore($inner, maxBufferSize: 100);
        $store->store($this->buildEnvelope('e1'), '{}');

        $count = $store->count();
        self::assertSame(0, $count); // InMemoryEventStore returns 0
        self::assertSame(0, $store->bufferCount()); // buffer was flushed
    }

    #[Test]
    public function findChecksBufferThenDelegates(): void
    {
        $inner = new InMemoryEventStore();
        $store = new BufferedEventStore($inner, maxBufferSize: 100);
        $store->store($this->buildEnvelope('target-id'), '{}');

        // Finding a buffered event ID should flush first
        $result = $store->find('target-id');
        self::assertNull($result); // InMemoryEventStore returns null
        self::assertSame(0, $store->bufferCount());
    }

    #[Test]
    public function findForNonBufferedEventDelegatesToInner(): void
    {
        $inner = new InMemoryEventStore();
        $store = new BufferedEventStore($inner, maxBufferSize: 100);

        $result = $store->find('nonexistent');
        self::assertNull($result);
        self::assertSame(0, $store->bufferCount());
    }

    #[Test]
    public function clearEmptiesBufferAndInner(): void
    {
        $inner = new InMemoryEventStore();
        $store = new BufferedEventStore($inner, maxBufferSize: 100);
        $store->store($this->buildEnvelope('e1'), '{}');

        $store->clear();

        self::assertSame(0, $store->bufferCount());
        self::assertTrue($inner->cleared);
    }

    #[Test]
    public function vacuumFlushesAndDelegatesToInner(): void
    {
        $inner = new InMemoryEventStore();
        $store = new BufferedEventStore($inner, maxBufferSize: 100);
        $store->store($this->buildEnvelope('e1'), '{}');

        $store->vacuum();

        self::assertSame(0, $store->bufferCount());
        self::assertTrue($inner->vacuumed);
    }

    #[Test]
    public function innerReturnsWrappedStore(): void
    {
        $inner = new InMemoryEventStore();
        $store = new BufferedEventStore($inner);

        self::assertSame($inner, $store->inner());
    }

    #[Test]
    public function sizeInBytesDelegatesToInner(): void
    {
        $inner = new InMemoryEventStore();
        $inner->sizeReturn = 4096;
        $store = new BufferedEventStore($inner);

        self::assertSame(4096, $store->sizeInBytes());
    }

    #[Test]
    public function deleteOlderThanFlushesAndDelegates(): void
    {
        $inner = new InMemoryEventStore();
        $inner->deleteReturn = 5;
        $store = new BufferedEventStore($inner, maxBufferSize: 100);
        $store->store($this->buildEnvelope('e1'), '{}');

        $deleted = $store->deleteOlderThan(1000);

        self::assertSame(5, $deleted);
        self::assertSame(0, $store->bufferCount());
    }

    #[Test]
    public function storeSilentlySwallowsInnerStoreExceptions(): void
    {
        $inner = new FailingEventStore();
        $store = new BufferedEventStore($inner, maxBufferSize: 2);

        // First store buffers
        $store->store($this->buildEnvelope('e1'), '{}');
        // Second store triggers auto-flush, inner throws — should not propagate
        $store->store($this->buildEnvelope('e2'), '{}');

        // Buffer should be cleared even though inner failed
        self::assertSame(0, $store->bufferCount());
    }

    private function buildEnvelope(string $eventId): EventEnvelope
    {
        return new EventEnvelope(
            eventId: $eventId,
            eventType: EventType::HttpRequest,
            schemaVersion: EventVersion::V1,
            timestampUs: 1000000,
            requestId: 'req-1',
            traceId: null,
            spanId: null,
            jobId: null,
            appEnv: 'testing',
            hostname: 'localhost',
            payload: [],
            payloadHash: 'hash',
        );
    }
}

/**
 * @internal Test double
 */
final class InMemoryEventStore implements EventStoreInterface
{
    public int $storedCount = 0;
    public bool $cleared = false;
    public bool $vacuumed = false;
    public int $sizeReturn = 0;
    public int $deleteReturn = 0;

    public function store(EventEnvelope $envelope, string $payloadJson, ?string $tenantHash = null): void
    {
        $this->storedCount++;
    }

    public function query(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return [];
    }

    public function count(array $filters = []): int
    {
        return 0;
    }

    public function find(string $eventId): ?array
    {
        return null;
    }

    public function sizeInBytes(): int
    {
        return $this->sizeReturn;
    }

    public function deleteOlderThan(int $timestampUs): int
    {
        return $this->deleteReturn;
    }

    public function deleteByEventTypes(array $eventTypes): int
    {
        return $this->deleteReturn;
    }

    public function deleteByPayloadKey(string $eventType, string $jsonPath, string $value): int
    {
        return $this->deleteReturn;
    }

    public function clear(): void
    {
        $this->cleared = true;
    }

    public function vacuum(): void
    {
        $this->vacuumed = true;
    }
}

/**
 * @internal Test double that throws on store
 */
final class FailingEventStore implements EventStoreInterface
{
    public function store(EventEnvelope $envelope, string $payloadJson, ?string $tenantHash = null): void
    {
        throw new \RuntimeException('Simulated failure');
    }

    public function query(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return [];
    }

    public function count(array $filters = []): int
    {
        return 0;
    }

    public function find(string $eventId): ?array
    {
        return null;
    }

    public function sizeInBytes(): int
    {
        return 0;
    }

    public function deleteOlderThan(int $timestampUs): int
    {
        return 0;
    }

    public function deleteByEventTypes(array $eventTypes): int
    {
        return 0;
    }

    public function deleteByPayloadKey(string $eventType, string $jsonPath, string $value): int
    {
        return 0;
    }

    public function clear(): void {}

    public function vacuum(): void {}
}
