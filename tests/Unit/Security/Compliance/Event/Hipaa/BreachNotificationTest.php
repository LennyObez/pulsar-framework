<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Hipaa;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Hipaa\BreachNotification;

#[CoversClass(BreachNotification::class)]
final class BreachNotificationTest extends TestCase
{
    private function createEvent(): BreachNotification
    {
        return new BreachNotification(
            eventId: 'evt-hipaa-001',
            occurredAt: new DateTimeImmutable('2026-01-15T10:30:00+00:00'),
            correlationId: 'corr-456',
            nonce: 'nonce-def',
            reporterIdentity: 'privacy-officer@hospital.org',
            affectedCount: 500,
            phiCategories: ['medical_records', 'billing_info'],
            discoveryDate: new DateTimeImmutable('2026-01-14T08:00:00+00:00'),
            notificationDeadline: new DateTimeImmutable('2026-03-14T08:00:00+00:00'),
        );
    }

    #[Test]
    public function regulationReturnsHipaa(): void
    {
        self::assertSame('hipaa', $this->createEvent()->regulation());
    }

    #[Test]
    public function eventTypeReturnsBreachNotification(): void
    {
        self::assertSame('breach_notification', $this->createEvent()->eventType());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $event = $this->createEvent();
        $array = $event->toArray();

        self::assertSame('evt-hipaa-001', $array['event_id']);
        self::assertSame('corr-456', $array['correlation_id']);
        self::assertSame('privacy-officer@hospital.org', $array['reporter_identity']);
        self::assertSame(500, $array['affected_count']);
        self::assertSame(['medical_records', 'billing_info'], $array['phi_categories']);
        self::assertSame(1, $array['schema_version']);
        self::assertArrayHasKey('discovery_date', $array);
        self::assertArrayHasKey('notification_deadline', $array);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = $this->createEvent();
        $restored = BreachNotification::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->reporterIdentity, $restored->reporterIdentity);
        self::assertSame($original->affectedCount, $restored->affectedCount);
        self::assertSame($original->phiCategories, $restored->phiCategories);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $event = BreachNotification::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->reporterIdentity);
        self::assertSame(0, $event->affectedCount);
        self::assertSame([], $event->phiCategories);
    }

    #[Test]
    public function toArrayDatesAreIso8601(): void
    {
        $event = $this->createEvent();
        $array = $event->toArray();

        self::assertIsString($array['discovery_date']);
        self::assertIsString($array['notification_deadline']);
        self::assertStringContainsString('2026-01-14', $array['discovery_date']);
        self::assertStringContainsString('2026-03-14', $array['notification_deadline']);
    }
}
