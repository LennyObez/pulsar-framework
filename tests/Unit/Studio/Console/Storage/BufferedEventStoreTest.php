<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Storage\BufferedEventStore;
use Pulsar\Extension\Studio\Console\Storage\SqliteEventStore;

use function hash;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(BufferedEventStore::class)]
final class BufferedEventStoreTest extends TestCase
{
    private SqliteEventStore $innerStore;
    private BufferedEventStore $bufferedStore;

    protected function setUp(): void
    {
        $this->innerStore = SqliteEventStore::inMemory();
        $this->bufferedStore = new BufferedEventStore($this->innerStore, maxBufferSize: 5);
    }

    #[Test]
    public function storeBuffersEventWithoutWritingToInner(): void
    {
        $envelope = $this->createEnvelope('event-1');

        $this->bufferedStore->store($envelope, json_encode($envelope->payload, JSON_THROW_ON_ERROR));

        // Event is in buffer, not in inner store
        self::assertSame(1, $this->bufferedStore->bufferCount());
        self::assertSame(0, $this->innerStore->count());
    }

    #[Test]
    public function flushWritesBufferedEventsToInner(): void
    {
        $this->storeMultipleEvents(3);

        self::assertSame(3, $this->bufferedStore->bufferCount());
        self::assertSame(0, $this->innerStore->count());

        $this->bufferedStore->flush();

        self::assertSame(0, $this->bufferedStore->bufferCount());
        self::assertSame(3, $this->innerStore->count());
    }

    #[Test]
    public function autoFlushOnMaxBufferSize(): void
    {
        // maxBufferSize is 5
        $this->storeMultipleEvents(5);

        // After 5th event, buffer should have auto-flushed
        self::assertSame(0, $this->bufferedStore->bufferCount());
        self::assertSame(5, $this->innerStore->count());
    }

    #[Test]
    public function autoFlushTriggersAtExactBufferSize(): void
    {
        // Store 4 events (under threshold)
        $this->storeMultipleEvents(4);
        self::assertSame(4, $this->bufferedStore->bufferCount());
        self::assertSame(0, $this->innerStore->count());

        // Store 5th event (hits threshold)
        $envelope = $this->createEnvelope('event-5');
        $this->bufferedStore->store($envelope, json_encode($envelope->payload, JSON_THROW_ON_ERROR));

        self::assertSame(0, $this->bufferedStore->bufferCount());
        self::assertSame(5, $this->innerStore->count());
    }

    #[Test]
    public function flushOnEmptyBufferIsNoop(): void
    {
        $this->bufferedStore->flush();

        self::assertSame(0, $this->bufferedStore->bufferCount());
        self::assertSame(0, $this->innerStore->count());
    }

    #[Test]
    public function queryFlushesBeforeReturning(): void
    {
        $this->storeMultipleEvents(2);

        // query should trigger flush
        $results = $this->bufferedStore->query();

        self::assertSame(0, $this->bufferedStore->bufferCount());
        self::assertCount(2, $results);
    }

    #[Test]
    public function countFlushesBeforeReturning(): void
    {
        $this->storeMultipleEvents(3);

        $count = $this->bufferedStore->count();

        self::assertSame(0, $this->bufferedStore->bufferCount());
        self::assertSame(3, $count);
    }

    #[Test]
    public function findFlushesWhenEventInBuffer(): void
    {
        $envelope = $this->createEnvelope('buffered-event');
        $this->bufferedStore->store($envelope, json_encode($envelope->payload, JSON_THROW_ON_ERROR));

        $found = $this->bufferedStore->find('buffered-event');

        self::assertNotNull($found);
        self::assertSame('buffered-event', $found['event_id']);
    }

    #[Test]
    public function findDoesNotFlushWhenEventNotInBuffer(): void
    {
        // Store event directly in inner store
        $directEnvelope = $this->createEnvelope('direct-event');
        $this->innerStore->store($directEnvelope, json_encode($directEnvelope->payload, JSON_THROW_ON_ERROR));

        // Store another event in buffer
        $bufferedEnvelope = $this->createEnvelope('buffered-event');
        $this->bufferedStore->store($bufferedEnvelope, json_encode($bufferedEnvelope->payload, JSON_THROW_ON_ERROR));

        // Find the direct event - should not flush the buffer
        $found = $this->bufferedStore->find('direct-event');

        self::assertNotNull($found);
        self::assertSame(1, $this->bufferedStore->bufferCount());
    }

    #[Test]
    public function deleteOlderThanFlushesFirst(): void
    {
        $baseTimestamp = 1_700_000_000_000_000;

        $this->bufferedStore->store(
            $this->createEnvelope('old-event', timestampUs: $baseTimestamp),
            '{}',
        );
        $this->bufferedStore->store(
            $this->createEnvelope('new-event', timestampUs: $baseTimestamp + 2_000_000),
            '{}',
        );

        $deleted = $this->bufferedStore->deleteOlderThan($baseTimestamp + 1_000_000);

        self::assertSame(1, $deleted);
        self::assertSame(0, $this->bufferedStore->bufferCount());
    }

    #[Test]
    public function clearEmptiesBufferAndInnerStore(): void
    {
        $this->storeMultipleEvents(3);

        // Also store directly in inner
        $directEnvelope = $this->createEnvelope('direct-event');
        $this->innerStore->store($directEnvelope, json_encode($directEnvelope->payload, JSON_THROW_ON_ERROR));

        $this->bufferedStore->clear();

        self::assertSame(0, $this->bufferedStore->bufferCount());
        self::assertSame(0, $this->innerStore->count());
    }

    #[Test]
    public function vacuumFlushesFirst(): void
    {
        $this->storeMultipleEvents(2);

        // vacuum should flush then vacuum
        $this->bufferedStore->vacuum();

        self::assertSame(0, $this->bufferedStore->bufferCount());
        self::assertSame(2, $this->innerStore->count());
    }

    #[Test]
    public function innerReturnsWrappedStore(): void
    {
        self::assertSame($this->innerStore, $this->bufferedStore->inner());
    }

    #[Test]
    public function sizeInBytesReturnsInnerStoreSize(): void
    {
        $size = $this->bufferedStore->sizeInBytes();

        self::assertGreaterThanOrEqual(0, $size);
    }

    #[Test]
    public function deleteByEventTypesFlushesFirst(): void
    {
        $this->bufferedStore->store(
            $this->createEnvelope('http-1', EventType::HttpRequest),
            '{}',
        );
        $this->bufferedStore->store(
            $this->createEnvelope('db-1', EventType::DatabaseQuery),
            '{}',
        );

        $deleted = $this->bufferedStore->deleteByEventTypes([EventType::HttpRequest->value]);

        self::assertSame(1, $deleted);
        self::assertSame(0, $this->bufferedStore->bufferCount());
        self::assertSame(1, $this->innerStore->count());
    }

    #[Test]
    public function multipleFlushCyclesWorkCorrectly(): void
    {
        // First batch
        $this->storeMultipleEvents(5);
        self::assertSame(5, $this->innerStore->count());

        // Second batch
        for ($i = 6; $i <= 10; $i++) {
            $envelope = $this->createEnvelope("event-{$i}");
            $this->bufferedStore->store($envelope, json_encode($envelope->payload, JSON_THROW_ON_ERROR));
        }
        self::assertSame(10, $this->innerStore->count());
    }

    private function createEnvelope(
        string $eventId,
        EventType $eventType = EventType::HttpRequest,
        ?int $timestampUs = null,
    ): EventEnvelope {
        return new EventEnvelope(
            eventId: $eventId,
            eventType: $eventType,
            schemaVersion: EventVersion::V1,
            timestampUs: $timestampUs ?? (int) (microtime(true) * 1_000_000.0),
            requestId: 'req-123',
            traceId: 'trace-123',
            spanId: 'span-123',
            jobId: null,
            appEnv: 'testing',
            hostname: 'localhost',
            payload: ['method' => 'GET', 'uri' => '/test'],
            payloadHash: hash('sha256', '{}'),
        );
    }

    private function storeMultipleEvents(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $envelope = $this->createEnvelope("event-{$i}");
            $this->bufferedStore->store($envelope, json_encode($envelope->payload, JSON_THROW_ON_ERROR));
        }
    }
}
