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
use Pulsar\Event\Internal\Outbox\OutboxRelay;
use Pulsar\Saga\Port\IntegrationEventBusPort;
use RuntimeException;

#[CoversClass(OutboxRelay::class)]
final class OutboxRelayTest extends TestCase
{
    private DatabaseOutboxPort $outbox;

    protected function setUp(): void
    {
        $connection = new PdoConnection(
            connectionName: 'outbox-relay-test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $this->outbox = new DatabaseOutboxPort($connection);
        $this->outbox->installSchema();
    }

    #[Test]
    public function tickPublishesPendingEventsAndMarksThemPublished(): void
    {
        $bus = new RecordingBus();
        $relay = new OutboxRelay($this->outbox, $bus);

        $this->outbox->store($this->makeEnvelope('order.placed', ['id' => 1]));
        $this->outbox->store($this->makeEnvelope('order.placed', ['id' => 2]));

        $result = $relay->tick();

        self::assertSame(2, $result->attempted);
        self::assertSame(2, $result->published);
        self::assertSame(0, $result->failed);
        self::assertCount(2, $bus->published);
        self::assertSame([], $this->outbox->pendingEvents());
    }

    #[Test]
    public function tickKeepsFailedEventsPendingAndCountsThem(): void
    {
        $bus = new FailingBus();
        $relay = new OutboxRelay($this->outbox, $bus);

        $this->outbox->store($this->makeEnvelope('order.placed', ['id' => 1]));

        $result = $relay->tick();

        self::assertSame(1, $result->attempted);
        self::assertSame(0, $result->published);
        self::assertSame(1, $result->failed);
        self::assertCount(1, $this->outbox->pendingEvents());
    }

    #[Test]
    public function tickIsolatesFailuresPerEvent(): void
    {
        $bus = new FailFirstBus();
        $relay = new OutboxRelay($this->outbox, $bus);

        $this->outbox->store($this->makeEnvelope('a', []));
        $this->outbox->store($this->makeEnvelope('b', []));

        $result = $relay->tick();

        self::assertSame(2, $result->attempted);
        self::assertSame(1, $result->published);
        self::assertSame(1, $result->failed);
        self::assertCount(1, $this->outbox->pendingEvents());
    }

    #[Test]
    public function tickReturnsZeroResultOnEmptyOutbox(): void
    {
        $bus = new RecordingBus();
        $relay = new OutboxRelay($this->outbox, $bus);

        $result = $relay->tick();

        self::assertSame(0, $result->attempted);
        self::assertSame(0, $result->published);
        self::assertSame(0, $result->failed);
        self::assertSame([], $bus->published);
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
        );
    }
}

/**
 * @internal
 */
final class RecordingBus implements IntegrationEventBusPort
{
    /** @var list<array{type: string, payload: array<string, mixed>}> */
    public array $published = [];

    public function publish(string $eventType, array $payload): void
    {
        $this->published[] = ['type' => $eventType, 'payload' => $payload];
    }
}

/**
 * @internal
 */
final class FailingBus implements IntegrationEventBusPort
{
    public function publish(string $eventType, array $payload): void
    {
        throw new RuntimeException('bus is down');
    }
}

/**
 * @internal
 */
final class FailFirstBus implements IntegrationEventBusPort
{
    private int $calls = 0;

    public function publish(string $eventType, array $payload): void
    {
        $this->calls++;
        if ($this->calls === 1) {
            throw new RuntimeException('transient');
        }
    }
}
