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
