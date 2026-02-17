<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\JustifiedAccess\Event;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\JustifiedAccess\Event\BreakTheGlassActivated;

final class BreakTheGlassActivatedTest extends TestCase
{
    #[Test]
    public function constructs_and_exposes_properties(): void
    {
        $now = new DateTimeImmutable('2026-01-15T10:00:00Z');

        $event = new BreakTheGlassActivated(
            eventId: 'evt-1',
            occurredAt: $now,
            correlationId: 'corr-1',
            nonce: 'nonce-abc',
            justificationId: 'just-1',
            actorId: 'actor-1',
            resourceType: 'patient_record',
            resourceId: 'rec-42',
            justificationText: 'Emergency access for critical patient care',
            durationSeconds: 1800,
        );

        self::assertSame('evt-1', $event->eventId);
        self::assertSame($now, $event->occurredAt);
        self::assertSame('corr-1', $event->correlationId);
        self::assertSame('just-1', $event->justificationId);
        self::assertSame('actor-1', $event->actorId);
        self::assertSame('patient_record', $event->resourceType);
        self::assertSame('rec-42', $event->resourceId);
        self::assertSame(1800, $event->durationSeconds);
    }

    #[Test]
    public function regulation_returns_multi(): void
    {
        $event = $this->createMinimalEvent();

        self::assertSame('multi', $event->regulation());
    }

    #[Test]
    public function event_type_returns_break_the_glass(): void
    {
        $event = $this->createMinimalEvent();

        self::assertSame('break_the_glass_activated', $event->eventType());
    }

    #[Test]
    public function to_array_contains_all_fields(): void
    {
        $now = new DateTimeImmutable('2026-01-15T10:00:00Z');

        $event = new BreakTheGlassActivated(
            eventId: 'evt-1',
            occurredAt: $now,
            correlationId: 'corr-1',
            nonce: 'nonce-1',
            justificationId: 'just-1',
            actorId: 'actor-1',
            resourceType: 'record',
            resourceId: 'rec-1',
            justificationText: 'Emergency',
            durationSeconds: 900,
        );

        $data = $event->toArray();

        self::assertSame('evt-1', $data['event_id']);
        self::assertSame('corr-1', $data['correlation_id']);
        self::assertSame('nonce-1', $data['nonce']);
        self::assertSame('multi', $data['regulation']);
        self::assertSame('break_the_glass_activated', $data['event_type']);
        self::assertSame('just-1', $data['justification_id']);
        self::assertSame('actor-1', $data['actor_id']);
        self::assertSame('record', $data['resource_type']);
        self::assertSame('rec-1', $data['resource_id']);
        self::assertSame('Emergency', $data['justification_text']);
        self::assertSame(900, $data['duration_seconds']);
        self::assertSame(1, $data['schema_version']);
    }

    #[Test]
    public function from_array_round_trips(): void
    {
        $now = new DateTimeImmutable('2026-01-15T10:00:00Z');

        $original = new BreakTheGlassActivated(
            eventId: 'evt-1',
            occurredAt: $now,
            correlationId: 'corr-1',
            nonce: 'nonce-1',
            justificationId: 'just-1',
            actorId: 'actor-1',
            resourceType: 'record',
            resourceId: 'rec-1',
            justificationText: 'Emergency',
            durationSeconds: 600,
        );

        $restored = BreakTheGlassActivated::fromArray($original->toArray());

        self::assertSame('evt-1', $restored->eventId);
        self::assertSame('corr-1', $restored->correlationId);
        self::assertSame('just-1', $restored->justificationId);
        self::assertSame('actor-1', $restored->actorId);
        self::assertSame('record', $restored->resourceType);
        self::assertSame('rec-1', $restored->resourceId);
        self::assertSame('Emergency', $restored->justificationText);
        self::assertSame(600, $restored->durationSeconds);
    }

    #[Test]
    public function from_array_uses_defaults_for_missing_keys(): void
    {
        $event = BreakTheGlassActivated::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->justificationId);
        self::assertSame(900, $event->durationSeconds);
    }

    private function createMinimalEvent(): BreakTheGlassActivated
    {
        return new BreakTheGlassActivated(
            eventId: 'evt-1',
            occurredAt: new DateTimeImmutable(),
            correlationId: 'corr-1',
            nonce: 'nonce-1',
            justificationId: 'just-1',
            actorId: 'actor-1',
            resourceType: 'record',
            resourceId: 'rec-1',
            justificationText: 'test',
            durationSeconds: 300,
        );
    }
}
