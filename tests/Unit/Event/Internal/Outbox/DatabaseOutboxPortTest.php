<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Event\Internal\Outbox;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Context\CausationId;
use Pulsar\Context\CorrelationId;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Event\EventEnvelope;
use Pulsar\Event\EventMetadata;
use Pulsar\Event\Internal\Outbox\DatabaseOutboxPort;
use Pulsar\Event\Internal\Outbox\PendingEnvelope;

#[CoversClass(DatabaseOutboxPort::class)]
final class DatabaseOutboxPortTest extends TestCase
{
    private DatabaseOutboxPort $outbox;

    protected function setUp(): void
    {
        $connection = new PdoConnection(
            connectionName: 'outbox-test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $this->outbox = new DatabaseOutboxPort($connection);
        $this->outbox->installSchema();
    }

    #[Test]
    public function pendingEventsReturnsEmptyOnFreshTable(): void
    {
        self::assertSame([], $this->outbox->pendingEvents());
    }

    #[Test]
    public function storeAndPendingRoundTripsEnvelope(): void
    {
        $envelope = $this->makeEnvelope('order.placed', ['order_id' => 'ord-1']);
        $this->outbox->store($envelope);

        $pending = $this->outbox->pendingEvents();

        self::assertCount(1, $pending);
        self::assertSame('order.placed', $pending[0]->eventType);
        self::assertSame('ord-1', $pending[0]->payload['order_id']);
    }

    #[Test]
    public function markPublishedRemovesFromPendingSet(): void
    {
        $envelope = $this->makeEnvelope('order.placed', []);
        $this->outbox->store($envelope);

        $this->outbox->markPublished($envelope->eventId);

        self::assertSame([], $this->outbox->pendingEvents());
    }

    #[Test]
    public function recordFailureKeepsRowPendingAndIncrementsAttempts(): void
    {
        $envelope = $this->makeEnvelope('order.placed', []);
        $this->outbox->store($envelope);

        $this->outbox->recordFailure($envelope->eventId, 'transient bus failure');

        self::assertCount(1, $this->outbox->pendingEvents());
    }

    #[Test]
    public function storeBatchPreservesOrder(): void
    {
        $first = $this->makeEnvelope('a', []);
        $second = $this->makeEnvelope('b', []);
        $third = $this->makeEnvelope('c', []);

        $this->outbox->storeBatch([$first, $second, $third]);

        $pending = $this->outbox->pendingEvents();

        self::assertSame(['a', 'b', 'c'], array_map(static fn(EventEnvelope $e): string => $e->eventType, $pending));
    }

    #[Test]
    public function pendingEventsRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->outbox->store($this->makeEnvelope('e' . $i, []));
        }

        self::assertCount(2, $this->outbox->pendingEvents(2));
    }

    #[Test]
    public function recordFailureDeadLettersEnvelopeWhenAttemptsReachCap(): void
    {
        $envelope = $this->makeEnvelope('order.placed', []);
        $this->outbox->store($envelope);

        // cap = 3: the first two failures keep it pending...
        $this->outbox->recordFailure($envelope->eventId, 'fail-1', 3);
        $this->outbox->recordFailure($envelope->eventId, 'fail-2', 3);
        self::assertCount(1, $this->outbox->pendingEvents());
        self::assertSame([], $this->outbox->deadLetteredEvents());

        // ...the third reaches the cap and dead-letters it.
        $this->outbox->recordFailure($envelope->eventId, 'fail-3', 3);
        self::assertSame([], $this->outbox->pendingEvents());

        $deadLettered = $this->outbox->deadLetteredEvents();
        self::assertCount(1, $deadLettered);
        self::assertSame($envelope->eventId, $deadLettered[0]->eventId);
    }

    #[Test]
    public function deadLetteredEnvelopeIsExcludedFromPendingForRelay(): void
    {
        $envelope = $this->makeEnvelope('order.placed', []);
        $this->outbox->store($envelope);

        $this->outbox->recordFailure($envelope->eventId, 'fail-1', 2);
        $this->outbox->recordFailure($envelope->eventId, 'fail-2', 2);

        self::assertSame([], $this->outbox->pendingForRelay(100, 2));
    }

    #[Test]
    public function healthyEventsAreNotStarvedByPoisonEnvelope(): void
    {
        // Poison stored first (oldest = FIFO head), healthy second.
        $poison = $this->makeEnvelope('poison', []);
        $this->outbox->store($poison);
        $healthy = $this->makeEnvelope('healthy', []);
        $this->outbox->store($healthy);

        // Dead-letter the poison (cap = 1 -> one failure exhausts it).
        $this->outbox->recordFailure($poison->eventId, 'permanent', 1);

        // A dead-lettered event must not hold the FIFO head: even with a batch
        // size of 1 the relay still reaches the healthy event behind it.
        $batch = $this->outbox->pendingForRelay(1, 1);

        self::assertCount(1, $batch);
        self::assertSame('healthy', $batch[0]->envelope->eventType);
    }

    #[Test]
    public function pendingForRelayPairsEnvelopeWithItsAttemptCount(): void
    {
        $envelope = $this->makeEnvelope('order.placed', []);
        $this->outbox->store($envelope);

        $this->outbox->recordFailure($envelope->eventId, 'fail-1', 100);
        $this->outbox->recordFailure($envelope->eventId, 'fail-2', 100);

        $batch = $this->outbox->pendingForRelay(100, 100);

        self::assertCount(1, $batch);
        self::assertInstanceOf(PendingEnvelope::class, $batch[0]);
        self::assertSame(2, $batch[0]->publishAttempts);
    }

    #[Test]
    public function deadLetteredEventsReturnsEmptyOnFreshTable(): void
    {
        self::assertSame([], $this->outbox->deadLetteredEvents());
    }

    #[Test]
    public function migrateSchemaIsIdempotentWhenColumnAlreadyPresent(): void
    {
        // installSchema() (setUp) already added dead_lettered_at; migrateSchema()
        // must be a safe no-op and leave the table fully usable.
        $this->outbox->migrateSchema();
        $this->outbox->migrateSchema();

        $envelope = $this->makeEnvelope('order.placed', []);
        $this->outbox->store($envelope);
        self::assertCount(1, $this->outbox->pendingEvents());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function makeEnvelope(string $type, array $payload): EventEnvelope
    {
        return EventEnvelope::wrap(
            eventType: $type,
            schemaVersion: 1,
            payload: $payload,
            metadata: new EventMetadata(
                correlationId: CorrelationId::fromString('00000000000000000000000000000001'),
                causationId: CausationId::fromString('00000000000000000000000000000002'),
            ),
            originModule: 'tests',
        );
    }
}
