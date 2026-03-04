<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Gdpr;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Gdpr\BreachNotified;

#[CoversClass(BreachNotified::class)]
final class BreachNotifiedTest extends TestCase
{
    private function createEvent(): BreachNotified
    {
        return new BreachNotified(
            eventId: 'evt-gdpr-bn-001',
            occurredAt: new DateTimeImmutable('2026-03-01T12:00:00+00:00'),
            correlationId: 'corr-gdpr-bn-1',
            nonce: 'nonce-gdpr-bn-1',
            authority: 'CNIL',
            notifiedAt: new DateTimeImmutable('2026-03-01T14:30:00+00:00'),
            breachEventId: 'evt-gdpr-breach-042',
            responseDeadline: new DateTimeImmutable('2026-04-01T14:30:00+00:00'),
        );
    }

    #[Test]
    public function regulationReturnsGdpr(): void
    {
        self::assertSame('gdpr', $this->createEvent()->regulation());
    }

    #[Test]
    public function eventTypeReturnsBreachNotified(): void
    {
        self::assertSame('breach_notified', $this->createEvent()->eventType());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createEvent()->toArray();

        self::assertSame('evt-gdpr-bn-001', $array['event_id']);
        self::assertSame('CNIL', $array['authority']);
        self::assertSame('evt-gdpr-breach-042', $array['breach_event_id']);
        self::assertArrayHasKey('notified_at', $array);
        self::assertArrayHasKey('response_deadline', $array);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = $this->createEvent();
        $restored = BreachNotified::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->authority, $restored->authority);
        self::assertSame($original->breachEventId, $restored->breachEventId);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $event = BreachNotified::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->authority);
        self::assertSame('', $event->breachEventId);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, BreachNotified::SCHEMA_VERSION);
    }
}
