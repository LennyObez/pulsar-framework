<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Context\CausationId;
use Pulsar\Context\CorrelationId;
use Pulsar\Event\EventEnvelope;
use Pulsar\Event\EventMetadata;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

use function strlen;

#[CoversClass(EventEnvelope::class)]
final class EventEnvelopeTest extends TestCase
{
    #[Test]
    public function wrapCreatesEnvelopeWithComputedHash(): void
    {
        $metadata = $this->createMetadata();
        $randomizer = new Randomizer(new Xoshiro256StarStar(42));

        $envelope = EventEnvelope::wrap(
            eventType: 'order.created',
            schemaVersion: 1,
            payload: ['order_id' => 'abc-123', 'amount' => 9900],
            metadata: $metadata,
            randomizer: $randomizer,
        );

        self::assertSame(32, strlen($envelope->eventId));
        self::assertSame('order.created', $envelope->eventType);
        self::assertSame(1, $envelope->schemaVersion);
        self::assertSame($metadata, $envelope->metadata);
        self::assertSame(['order_id' => 'abc-123', 'amount' => 9900], $envelope->payload);
        self::assertSame(64, strlen($envelope->payloadHash)); // SHA-256 hex
    }

    #[Test]
    public function payloadHashIsDeterministic(): void
    {
        $metadata = $this->createMetadata();
        $r1 = new Randomizer(new Xoshiro256StarStar(1));
        $r2 = new Randomizer(new Xoshiro256StarStar(2));

        $e1 = EventEnvelope::wrap('test.event', 1, ['key' => 'value'], $metadata, $r1);
        $e2 = EventEnvelope::wrap('test.event', 1, ['key' => 'value'], $metadata, $r2);

        // Different event IDs but same payload hash
        self::assertNotSame($e1->eventId, $e2->eventId);
        self::assertSame($e1->payloadHash, $e2->payloadHash);
    }

    #[Test]
    public function payloadHashIgnoresKeyInsertionOrder(): void
    {
        $metadata = $this->createMetadata();
        $r1 = new Randomizer(new Xoshiro256StarStar(1));
        $r2 = new Randomizer(new Xoshiro256StarStar(2));

        $e1 = EventEnvelope::wrap('test', 1, ['a' => 1, 'b' => 2], $metadata, $r1);
        $e2 = EventEnvelope::wrap('test', 1, ['b' => 2, 'a' => 1], $metadata, $r2);

        self::assertSame($e1->payloadHash, $e2->payloadHash);
    }

    #[Test]
    public function payloadHashRecursivelySortsNestedKeys(): void
    {
        $metadata = $this->createMetadata();
        $r1 = new Randomizer(new Xoshiro256StarStar(1));
        $r2 = new Randomizer(new Xoshiro256StarStar(2));

        $e1 = EventEnvelope::wrap('test', 1, ['outer' => ['z' => 1, 'a' => 2]], $metadata, $r1);
        $e2 = EventEnvelope::wrap('test', 1, ['outer' => ['a' => 2, 'z' => 1]], $metadata, $r2);

        self::assertSame($e1->payloadHash, $e2->payloadHash);
    }

    #[Test]
    public function differentEventTypesProduceDifferentHashes(): void
    {
        $metadata = $this->createMetadata();
        $payload = ['key' => 'value'];
        $r1 = new Randomizer(new Xoshiro256StarStar(1));
        $r2 = new Randomizer(new Xoshiro256StarStar(2));

        $e1 = EventEnvelope::wrap('type.a', 1, $payload, $metadata, $r1);
        $e2 = EventEnvelope::wrap('type.b', 1, $payload, $metadata, $r2);

        self::assertNotSame($e1->payloadHash, $e2->payloadHash);
    }

    #[Test]
    public function differentSchemaVersionsProduceDifferentHashes(): void
    {
        $metadata = $this->createMetadata();
        $payload = ['key' => 'value'];
        $r1 = new Randomizer(new Xoshiro256StarStar(1));
        $r2 = new Randomizer(new Xoshiro256StarStar(2));

        $e1 = EventEnvelope::wrap('test', 1, $payload, $metadata, $r1);
        $e2 = EventEnvelope::wrap('test', 2, $payload, $metadata, $r2);

        self::assertNotSame($e1->payloadHash, $e2->payloadHash);
    }

    #[Test]
    public function toArrayFromArrayRoundtrip(): void
    {
        $metadata = $this->createMetadata();
        $randomizer = new Randomizer(new Xoshiro256StarStar(42));

        $original = EventEnvelope::wrap(
            eventType: 'order.created',
            schemaVersion: 1,
            payload: ['order_id' => 'abc-123'],
            metadata: $metadata,
            randomizer: $randomizer,
        );

        $array = $original->toArray();
        $restored = EventEnvelope::fromArray($array);

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->eventType, $restored->eventType);
        self::assertSame($original->schemaVersion, $restored->schemaVersion);
        self::assertSame($original->payloadHash, $restored->payloadHash);
        self::assertSame($original->payload, $restored->payload);
        self::assertSame($original->metadata->correlationId->value, $restored->metadata->correlationId->value);
    }

    #[Test]
    public function toArrayUsesSnakeCaseKeys(): void
    {
        $metadata = $this->createMetadata();
        $envelope = EventEnvelope::wrap('test', 1, [], $metadata);

        $array = $envelope->toArray();

        self::assertArrayHasKey('event_id', $array);
        self::assertArrayHasKey('event_type', $array);
        self::assertArrayHasKey('schema_version', $array);
        self::assertArrayHasKey('payload_hash', $array);
    }

    private function createMetadata(): EventMetadata
    {
        return new EventMetadata(
            correlationId: CorrelationId::fromString(str_repeat('aa', 16)),
            causationId: CausationId::fromString(str_repeat('bb', 16)),
        );
    }
}
