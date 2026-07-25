<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Event\Internal\Outbox;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\StormProtectionConfig;
use Pulsar\Context\CausationId;
use Pulsar\Context\CorrelationId;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Event\EventEnvelope;
use Pulsar\Event\EventMetadata;
use Pulsar\Event\Internal\EventDispatcher;
use Pulsar\Event\Internal\ListenerProvider;
use Pulsar\Event\Internal\Outbox\DatabaseOutboxPort;
use Pulsar\Event\Internal\Outbox\DispatchingIntegrationEventBus;
use Pulsar\Event\Internal\Outbox\OutboxRelay;
use Pulsar\Event\Internal\StormGuard;

#[CoversClass(DispatchingIntegrationEventBus::class)]
final class DispatchingIntegrationEventBusTest extends TestCase
{
    #[Test]
    public function publishDispatchesAnEnvelopeIntegrationConsumersReceive(): void
    {
        // Integration consumers subscribe to the EventEnvelope and route by
        // eventType (the framework keys listeners by class).
        $listeners = new ListenerProvider();
        /** @var list<EventEnvelope> $received */
        $received = [];
        $listeners->addListener(EventEnvelope::class, static function (EventEnvelope $envelope) use (&$received): void {
            $received[] = $envelope;
        });

        $bus = new DispatchingIntegrationEventBus($this->dispatcher($listeners));
        $bus->publish('order.shipped', ['id' => 42]);

        self::assertCount(1, $received);
        self::assertSame('order.shipped', $received[0]->eventType);
        self::assertSame(['id' => 42], $received[0]->payload);
    }

    #[Test]
    public function endToEndStoreRelayThenDeliver(): void
    {
        // The full transactional-outbox path: an event stored in the outbox is
        // drained by the relay, published through this bus, and delivered to a
        // cross-module listener.
        $listeners = new ListenerProvider();
        /** @var list<array<string, mixed>> $delivered */
        $delivered = [];
        $listeners->addListener(EventEnvelope::class, static function (EventEnvelope $envelope) use (&$delivered): void {
            if ($envelope->eventType === 'invoice.issued') {
                $delivered[] = $envelope->payload;
            }
        });

        $outbox = new DatabaseOutboxPort(new PdoConnection(
            connectionName: 'outbox-bus-test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        ));
        $outbox->installSchema();
        $outbox->store($this->envelope('invoice.issued', ['number' => 'INV-1']));

        $relay = new OutboxRelay($outbox, new DispatchingIntegrationEventBus($this->dispatcher($listeners)));
        $result = $relay->tick();

        self::assertSame(1, $result->published);
        self::assertSame([['number' => 'INV-1']], $delivered);
        self::assertSame([], $outbox->pendingEvents());
    }

    private function dispatcher(ListenerProvider $listeners): EventDispatcher
    {
        return new EventDispatcher($listeners, $listeners, new StormGuard(new StormProtectionConfig()));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function envelope(string $eventType, array $payload): EventEnvelope
    {
        return EventEnvelope::wrap(
            $eventType,
            1,
            $payload,
            new EventMetadata(CorrelationId::generate(), CausationId::generate()),
        );
    }
}
