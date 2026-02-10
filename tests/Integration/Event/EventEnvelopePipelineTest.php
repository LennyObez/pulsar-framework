<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Event;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Context\CausationId;
use Pulsar\Context\CorrelationId;
use Pulsar\Event\EventEnvelope;
use Pulsar\Event\EventMetadata;

#[CoversClass(EventEnvelope::class)]
#[CoversClass(EventMetadata::class)]
final class EventEnvelopePipelineTest extends TestCase
{
    private CorrelationId $correlationId;
    private CausationId $causationId;

    protected function setUp(): void
    {
        $this->correlationId = CorrelationId::generate();
        $this->causationId = CausationId::generate();
    }

    private function makeMeta(
        ?CorrelationId $correlationId = null,
        ?CausationId $causationId = null,
        ?string $actor = null,
        ?string $tenantId = null,
        ?DateTimeImmutable $occurredAt = null,
    ): EventMetadata {
        return new EventMetadata(
            correlationId: $correlationId ?? $this->correlationId,
            causationId: $causationId ?? $this->causationId,
            actor: $actor,
            tenantId: $tenantId,
            occurredAt: $occurredAt,
        );
    }

    #[Test]
    public function eventMetadataSerializationRoundtrip(): void
    {
        $corrId = CorrelationId::generate();
        $causId = CausationId::generate();
        $meta = new EventMetadata(
            correlationId: $corrId,
            causationId: $causId,
            actor: 'user-1',
            tenantId: 'tenant-a',
            occurredAt: new DateTimeImmutable('2024-01-15T10:00:00Z'),
            attributes: ['source' => 'api'],
        );

        $array = $meta->toArray();

        self::assertSame($corrId->value, $array['correlation_id']);
        self::assertSame($causId->value, $array['causation_id']);
        self::assertSame('user-1', $array['actor']);
        self::assertSame('tenant-a', $array['tenant_id']);
        self::assertSame(['source' => 'api'], $array['attributes']);
    }

    #[Test]
    public function eventMetadataWithTenantId(): void
    {
        $meta = $this->makeMeta(tenantId: 'org-42');

        self::assertSame('org-42', $meta->tenantId);
        self::assertNull($meta->actor);
    }

    #[Test]
    public function eventMetadataOccurredAtDefaultsToNow(): void
    {
        $before = new DateTimeImmutable();
        $meta = $this->makeMeta();
        $after = new DateTimeImmutable();

        self::assertGreaterThanOrEqual($before->getTimestamp(), $meta->occurredAt->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $meta->occurredAt->getTimestamp());
    }

    #[Test]
    public function envelopeWrapGeneratesUniqueEventId(): void
    {
        $meta = $this->makeMeta();

        $env1 = EventEnvelope::wrap('user.created', 1, ['id' => 1], $meta);
        $env2 = EventEnvelope::wrap('user.created', 1, ['id' => 1], $meta);

        self::assertNotSame($env1->eventId, $env2->eventId);
    }

    #[Test]
    public function envelopeWrapComputesPayloadHash(): void
    {
        $meta = $this->makeMeta();
        $envelope = EventEnvelope::wrap('order.placed', 1, ['order_id' => 99], $meta);

        self::assertNotSame('', $envelope->payloadHash);
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $envelope->payloadHash);
    }

    #[Test]
    public function envelopeHashIsConsistentForSamePayload(): void
    {
        $meta = $this->makeMeta();

        $env1 = EventEnvelope::wrap('item.sold', 1, ['sku' => 'ABC', 'qty' => 5], $meta);
        $env2 = EventEnvelope::wrap('item.sold', 1, ['sku' => 'ABC', 'qty' => 5], $meta);

        self::assertSame($env1->payloadHash, $env2->payloadHash);
    }

    #[Test]
    public function envelopeHashDiffersForDifferentPayload(): void
    {
        $meta = $this->makeMeta();

        $env1 = EventEnvelope::wrap('item.sold', 1, ['sku' => 'ABC', 'qty' => 5], $meta);
        $env2 = EventEnvelope::wrap('item.sold', 1, ['sku' => 'XYZ', 'qty' => 5], $meta);

        self::assertNotSame($env1->payloadHash, $env2->payloadHash);
    }

    #[Test]
    public function envelopeHashDiffersForDifferentEventType(): void
    {
        $meta = $this->makeMeta();
        $payload = ['id' => 1];

        $env1 = EventEnvelope::wrap('user.created', 1, $payload, $meta);
        $env2 = EventEnvelope::wrap('user.deleted', 1, $payload, $meta);

        self::assertNotSame($env1->payloadHash, $env2->payloadHash);
    }

    #[Test]
    public function envelopeHashDiffersForDifferentSchemaVersion(): void
    {
        $meta = $this->makeMeta();
        $payload = ['id' => 1];

        $env1 = EventEnvelope::wrap('user.created', 1, $payload, $meta);
        $env2 = EventEnvelope::wrap('user.created', 2, $payload, $meta);

        self::assertNotSame($env1->payloadHash, $env2->payloadHash);
    }

    #[Test]
    public function envelopeHashIsOrderIndependentForPayloadKeys(): void
    {
        $meta = $this->makeMeta();

        $env1 = EventEnvelope::wrap('test.event', 1, ['a' => 1, 'b' => 2], $meta);
        $env2 = EventEnvelope::wrap('test.event', 1, ['b' => 2, 'a' => 1], $meta);

        self::assertSame($env1->payloadHash, $env2->payloadHash);
    }

    #[Test]
    public function envelopeToAndFromArrayRoundtrip(): void
    {
        $corrId = CorrelationId::generate();
        $causId = CausationId::generate();
        $meta = new EventMetadata(
            correlationId: $corrId,
            causationId: $causId,
            actor: 'bot-1',
            occurredAt: new DateTimeImmutable('2024-06-01T00:00:00Z'),
        );

        $original = EventEnvelope::wrap('payment.processed', 1, ['amount' => 100], $meta);
        $array = $original->toArray();
        $restored = EventEnvelope::fromArray($array);

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->eventType, $restored->eventType);
        self::assertSame($original->schemaVersion, $restored->schemaVersion);
        self::assertSame($original->payloadHash, $restored->payloadHash);
        self::assertSame($original->payload, $restored->payload);
    }

    #[Test]
    public function envelopeToArrayIncludesAllFields(): void
    {
        $meta = $this->makeMeta();
        $envelope = EventEnvelope::wrap('test.event', 1, ['key' => 'val'], $meta);

        $array = $envelope->toArray();

        self::assertArrayHasKey('event_id', $array);
        self::assertArrayHasKey('event_type', $array);
        self::assertArrayHasKey('schema_version', $array);
        self::assertArrayHasKey('payload_hash', $array);
        self::assertArrayHasKey('payload', $array);
        self::assertArrayHasKey('metadata', $array);
    }

    #[Test]
    public function envelopePreservesNestedPayload(): void
    {
        $meta = $this->makeMeta();
        $nestedPayload = [
            'user' => ['id' => 42, 'name' => 'Alice'],
            'items' => [['sku' => 'A1', 'qty' => 2], ['sku' => 'B2', 'qty' => 1]],
        ];

        $envelope = EventEnvelope::wrap('cart.checked_out', 1, $nestedPayload, $meta);

        self::assertSame($nestedPayload, $envelope->payload);
    }

    #[Test]
    public function multipleEventsInPipelineShareCorrelationId(): void
    {
        $corrId = CorrelationId::generate();
        $causId = CausationId::generate();
        $meta = new EventMetadata(
            correlationId: $corrId,
            causationId: $causId,
        );

        $events = [
            EventEnvelope::wrap('order.created', 1, ['order_id' => 1], $meta),
            EventEnvelope::wrap('payment.initiated', 1, ['order_id' => 1], $meta),
            EventEnvelope::wrap('payment.confirmed', 1, ['order_id' => 1], $meta),
        ];

        foreach ($events as $event) {
            self::assertSame($corrId->value, $event->metadata->correlationId->value);
        }

        // All events are unique despite sharing correlation
        $ids = array_map(fn($e) => $e->eventId, $events);
        self::assertCount(3, array_unique($ids));
    }
}
