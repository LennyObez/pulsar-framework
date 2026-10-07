<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Storage\BufferedEventStore;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use RuntimeException;

#[CoversClass(BufferedEventStore::class)]
final class BufferedEventStoreTest extends TestCase
{
    #[Test]
    public function storeBuffersEventsInMemory(): void
    {
        $inner = $this->createStub(EventStoreInterface::class);
        $store = new BufferedEventStore($inner, maxBufferSize: 10);

        $store->store($this->createEnvelope('e1'), '{}');

        self::assertSame(1, $store->bufferCount());
    }

    #[Test]
    public function flushDrainsBuffer(): void
    {
        $stored = [];
        $inner = $this->createStub(EventStoreInterface::class);
        $inner->method('store')->willReturnCallback(
            function (EventEnvelope $env) use (&$stored): void {
                $stored[] = $env->eventId;
            },
        );

        $store = new BufferedEventStore($inner, maxBufferSize: 100);
        $store->store($this->createEnvelope('e1'), '{}');
        $store->store($this->createEnvelope('e2'), '{}');

        self::assertSame(2, $store->bufferCount());

        $store->flush();

        self::assertSame(0, $store->bufferCount());
        self::assertSame(['e1', 'e2'], $stored);
    }

    #[Test]
    public function flushOnEmptyBufferIsNoOp(): void
    {
        $inner = $this->createStub(EventStoreInterface::class);
        $store = new BufferedEventStore($inner);

        $store->flush();

        self::assertSame(0, $store->bufferCount());
    }

    #[Test]
    public function autoFlushOnBufferFull(): void
    {
        $storeCount = 0;
        $inner = $this->createStub(EventStoreInterface::class);
        $inner->method('store')->willReturnCallback(
            function () use (&$storeCount): void {
                $storeCount++;
            },
        );

        $store = new BufferedEventStore($inner, maxBufferSize: 2);

        $store->store($this->createEnvelope('e1'), '{}');
        self::assertSame(1, $store->bufferCount());

        $store->store($this->createEnvelope('e2'), '{}');
        self::assertSame(0, $store->bufferCount());
        self::assertSame(2, $storeCount);
    }

    #[Test]
    public function queryFlushesBeforeDelegating(): void
    {
        $inner = $this->createStub(EventStoreInterface::class);
        $inner->method('query')->willReturn([['event_id' => 'e1']]);

        $store = new BufferedEventStore($inner);
        $store->store($this->createEnvelope('e1'), '{}');

        $result = $store->query();

        self::assertSame(0, $store->bufferCount());
        self::assertCount(1, $result);
    }

    #[Test]
    public function countFlushesBeforeDelegating(): void
    {
        $inner = $this->createStub(EventStoreInterface::class);
        $inner->method('count')->willReturn(5);

        $store = new BufferedEventStore($inner);
        $store->store($this->createEnvelope('e1'), '{}');

        self::assertSame(5, $store->count());
        self::assertSame(0, $store->bufferCount());
    }

    #[Test]
    public function findChecksBufferBeforeDelegating(): void
    {
        $inner = $this->createStub(EventStoreInterface::class);
        $inner->method('find')->willReturn(['event_id' => 'e1']);

        $store = new BufferedEventStore($inner);
        $store->store($this->createEnvelope('e1'), '{}');

        $result = $store->find('e1');

        self::assertSame('e1', $result['event_id']);
    }

    #[Test]
    public function findDelegatesToInnerWhenNotInBuffer(): void
    {
        $inner = $this->createStub(EventStoreInterface::class);
        $inner->method('find')->willReturn(null);

        $store = new BufferedEventStore($inner);

        self::assertNull($store->find('nonexistent'));
    }

    #[Test]
    public function clearEmptiesBufferAndDelegates(): void
    {
        $clearCalled = false;
        $inner = $this->createStub(EventStoreInterface::class);
        $inner->method('clear')->willReturnCallback(function () use (&$clearCalled): void {
            $clearCalled = true;
        });

        $store = new BufferedEventStore($inner);
        $store->store($this->createEnvelope('e1'), '{}');
        $store->clear();

        self::assertSame(0, $store->bufferCount());
        self::assertTrue($clearCalled);
    }

    #[Test]
    public function sizeInBytesDelegatesToInner(): void
    {
        $inner = $this->createStub(EventStoreInterface::class);
        $inner->method('sizeInBytes')->willReturn(1024);

        $store = new BufferedEventStore($inner);

        self::assertSame(1024, $store->sizeInBytes());
    }

    #[Test]
    public function innerReturnsWrappedStore(): void
    {
        $inner = $this->createStub(EventStoreInterface::class);
        $store = new BufferedEventStore($inner);

        self::assertSame($inner, $store->inner());
    }

    #[Test]
    public function flushSilentlyHandlesInnerStoreFailures(): void
    {
        $inner = $this->createStub(EventStoreInterface::class);
        $inner->method('store')->willThrowException(new RuntimeException('DB error'));

        $store = new BufferedEventStore($inner, maxBufferSize: 100);
        $store->store($this->createEnvelope('e1'), '{}');

        $store->flush();

        self::assertSame(0, $store->bufferCount());
    }

    #[Test]
    public function deleteOlderThanFlushesAndDelegates(): void
    {
        $inner = $this->createStub(EventStoreInterface::class);
        $inner->method('deleteOlderThan')->willReturn(3);

        $store = new BufferedEventStore($inner);
        $store->store($this->createEnvelope('e1'), '{}');

        self::assertSame(3, $store->deleteOlderThan(1000));
        self::assertSame(0, $store->bufferCount());
    }

    #[Test]
    public function vacuumFlushesAndDelegates(): void
    {
        $vacuumCalled = false;
        $inner = $this->createStub(EventStoreInterface::class);
        $inner->method('vacuum')->willReturnCallback(function () use (&$vacuumCalled): void {
            $vacuumCalled = true;
        });

        $store = new BufferedEventStore($inner);
        $store->vacuum();

        self::assertTrue($vacuumCalled);
    }

    private function createEnvelope(string $eventId): EventEnvelope
    {
        return new EventEnvelope(
            eventId: $eventId,
            eventType: EventType::HttpResponse,
            schemaVersion: EventVersion::V1,
            timestampUs: 1700000000_000000,
            requestId: null,
            traceId: null,
            spanId: null,
            jobId: null,
            appEnv: 'test',
            hostname: 'localhost',
            payload: [],
            payloadHash: 'hash',
        );
    }
}
